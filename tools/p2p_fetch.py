"""Litecoin block parser and P2P fetcher for MWEBscan.

Pulls blocks straight from the P2P network, no litecoind chainstate/dbcache:
parse what we need, discard the block. We connect without the NODE_MWEB service
flag and request MSG_BLOCK, so peers send the stripped legacy block (no witness,
no MimbleWimble extension) carrying the canonical data we want: peg-ins
(witness v9 outputs), the HogEx (witness v8 hogaddr output = MWEB supply), and
the peg-out outputs on the HogEx.

peg-in = witness v9 (0x59), hogaddr = witness v8 (0x58); program 32B.

Usage:
  python3 tools/p2p_fetch.py selftest
  python3 tools/p2p_fetch.py peers                           # find peers via DNS seeds
  python3 tools/p2p_fetch.py electrum <txid>                 # look up a tx via Electrum
  python3 tools/p2p_fetch.py parse   <rawblock.hex>          # parse a raw block file
  python3 tools/p2p_fetch.py fetch   <peer|auto> <blockhash> # fetch+parse one block
  python3 tools/p2p_fetch.py catchup <peer|auto> <hash> <height> [max]  # dry-run walk

  peer = a node IP, or 'auto' to discover a full node from the DNS seeds.
"""

import sys
import ssl
import json
import time
import random
import socket
import struct
import hashlib
import os
import sys

# Network parameters (Litecoin mainnet by default; testnet via MWEBSCAN_NETWORK).
# network.py lives at the repo root; add it to the path so this file works whether
# imported by mwebp2p.py or run directly as a CLI from tools/.
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from network import PARAMS as _NET

# --- Network constants (resolved from the active network) ---
MAGIC = _NET['MAGIC']
DEFAULT_PORT = _NET['DEFAULT_PORT']
PROTOCOL_VERSION = 70015

# Witness program version -> MWEB output type. (Identical on mainnet and testnet.)
WITVER_PEGIN = 9     # OP_9 (0x59) -> witness_mweb_pegin   (program = kernel ID, 32B)
WITVER_HOGADDR = 8   # OP_8 (0x58) -> witness_mweb_hogaddr  (program = header hash, 32B)

# Base58 address version bytes / bech32 HRP (network-specific).
P2PKH_VERSION = _NET['P2PKH_VERSION']
P2SH_VERSION = _NET['P2SH_VERSION']
BECH32_HRP = _NET['BECH32_HRP']


def dsha256(b):
    return hashlib.sha256(hashlib.sha256(b).digest()).digest()


def merkle_root_checked(internal_hashes):
    """Merkle root + 'mutated' flag, from 32-byte internal-order txids.

    Odd layers duplicate the last element (Bitcoin/Litecoin rule). 'mutated' is
    True if any layer has an adjacent duplicate pair (the CVE-2012-2459 vector:
    such a pair lets a peer forge a different tx list with the same root). Reject
    a mutated tree even when the root matches."""
    if not internal_hashes:
        return b'\x00' * 32, False
    layer = list(internal_hashes)
    mutated = False
    while len(layer) > 1:
        # Check pre-padding pairs; an equal adjacent pair is a mutation.
        for i in range(0, len(layer) - 1, 2):
            if layer[i] == layer[i + 1]:
                mutated = True
        if len(layer) % 2:
            layer.append(layer[-1])
        layer = [dsha256(layer[i] + layer[i + 1]) for i in range(0, len(layer), 2)]
    return layer[0], mutated


def merkle_root(internal_hashes):
    """Merkle root only; merkle_root_checked also returns the mutation flag."""
    return merkle_root_checked(internal_hashes)[0]


def _bits_to_target(bits):
    """Decode compact 'nBits' into a full 256-bit target."""
    exp = bits >> 24
    mant = bits & 0x007fffff
    if exp <= 3:
        return mant >> (8 * (3 - exp))
    return mant << (8 * (exp - 3))


# Minimum-difficulty target (consensus.powLimit). A header claiming an easier
# (larger) target than this is rejected, so a peer cannot serve a forged
# low-difficulty chain any scrypt hash trivially satisfies. The powLimit and its
# compact form (0x1e0fffff) are identical on mainnet and testnet; testnet's
# min-difficulty headers still satisfy target <= powLimit, so the floor holds.
POW_LIMIT = _bits_to_target(_NET['POW_LIMIT_BITS'])


def check_pow(hdr80):
    """True if the 80-byte header's proof-of-work meets its encoded target.

    Litecoin PoW is scrypt(N=1024, r=1, p=1) over the header, not dsha256;
    dsha256 is only the block identity. The target must also be at or below the
    network powLimit, else a peer could serve an arbitrarily easy chain."""
    target = _bits_to_target(struct.unpack('<I', hdr80[72:76])[0])
    if target <= 0 or target > POW_LIMIT:
        return False
    powhash = int.from_bytes(
        hashlib.scrypt(hdr80, salt=hdr80, n=1024, r=1, p=1, dklen=32), 'little')
    return powhash <= target


def _varint(n):
    if n < 0xfd:
        return bytes([n])
    if n <= 0xffff:
        return b'\xfd' + struct.pack('<H', n)
    if n <= 0xffffffff:
        return b'\xfe' + struct.pack('<I', n)
    return b'\xff' + struct.pack('<Q', n)


# --------------------------------------------------------------------------
# Byte reader
# --------------------------------------------------------------------------
class Reader:
    def __init__(self, data):
        self.d = data
        self.i = 0

    def read(self, n):
        if self.i + n > len(self.d):
            raise EOFError(f"need {n} bytes at {self.i}, have {len(self.d) - self.i}")
        b = self.d[self.i:self.i + n]
        self.i += n
        return b

    def u8(self):
        return self.read(1)[0]

    def u16(self):
        return struct.unpack('<H', self.read(2))[0]

    def u32(self):
        return struct.unpack('<I', self.read(4))[0]

    def i32(self):
        return struct.unpack('<i', self.read(4))[0]

    def u64(self):
        return struct.unpack('<Q', self.read(8))[0]

    def varint(self):
        n = self.u8()
        if n < 0xfd:
            return n
        if n == 0xfd:
            return self.u16()
        if n == 0xfe:
            return self.u32()
        return self.u64()

    def varbytes(self):
        return self.read(self.varint())

    def remaining(self):
        return len(self.d) - self.i


# --------------------------------------------------------------------------
# Address / script classification (base58check + bech32/bech32m)
# --------------------------------------------------------------------------
_B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'


def b58check(version, payload):
    raw = bytes([version]) + payload
    raw += dsha256(raw)[:4]
    n = int.from_bytes(raw, 'big')
    s = ''
    while n > 0:
        n, r = divmod(n, 58)
        s = _B58[r] + s
    return '1' * (len(raw) - len(raw.lstrip(b'\x00'))) + s


_BECH_CHARS = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l'


def _bech32_polymod(values):
    gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3]
    chk = 1
    for v in values:
        b = chk >> 25
        chk = ((chk & 0x1ffffff) << 5) ^ v
        for i in range(5):
            chk ^= gen[i] if ((b >> i) & 1) else 0
    return chk


def _bech32_hrp_expand(hrp):
    return [ord(c) >> 5 for c in hrp] + [0] + [ord(c) & 31 for c in hrp]


def _bech32_encode(hrp, data, spec_const):
    combined = data + [0, 0, 0, 0, 0, 0]
    polymod = _bech32_polymod(_bech32_hrp_expand(hrp) + combined) ^ spec_const
    checksum = [(polymod >> 5 * (5 - i)) & 31 for i in range(6)]
    return hrp + '1' + ''.join(_BECH_CHARS[d] for d in data + checksum)


def _convertbits(data, frombits, tobits, pad=True):
    acc = 0
    bits = 0
    ret = []
    maxv = (1 << tobits) - 1
    for value in data:
        acc = (acc << frombits) | value
        bits += frombits
        while bits >= tobits:
            bits -= tobits
            ret.append((acc >> bits) & maxv)
    if pad and bits:
        ret.append((acc << (tobits - bits)) & maxv)
    return ret


def segwit_address(witver, program):
    spec = 1 if witver == 0 else 0x2bc830a3  # bech32 vs bech32m constant
    data = [witver] + _convertbits(list(program), 8, 5)
    return _bech32_encode(BECH32_HRP, data, spec)


def classify_output(spk):
    """Return (type_string, address_or_None) for an output scriptPubKey."""
    n = len(spk)
    # p2pkh: OP_DUP OP_HASH160 14 <20> OP_EQUALVERIFY OP_CHECKSIG
    if n == 25 and spk[0] == 0x76 and spk[1] == 0xa9 and spk[2] == 0x14 and spk[23] == 0x88 and spk[24] == 0xac:
        return 'pubkeyhash', b58check(P2PKH_VERSION, spk[3:23])
    # p2sh: OP_HASH160 14 <20> OP_EQUAL
    if n == 23 and spk[0] == 0xa9 and spk[1] == 0x14 and spk[22] == 0x87:
        return 'scripthash', b58check(P2SH_VERSION, spk[2:22])
    # witness programs: <OP_n> <push len> <program>
    if n >= 4 and (spk[0] == 0x00 or 0x51 <= spk[0] <= 0x60) and spk[1] == n - 2:
        witver = 0 if spk[0] == 0x00 else spk[0] - 0x50
        program = spk[2:]
        if witver == WITVER_PEGIN and len(program) == 32:
            return 'witness_mweb_pegin', None
        if witver == WITVER_HOGADDR and len(program) == 32:
            return 'witness_mweb_hogaddr', None
        if witver == 0 and len(program) == 20:
            return 'witness_v0_keyhash', segwit_address(0, program)
        if witver == 0 and len(program) == 32:
            return 'witness_v0_scripthash', segwit_address(0, program)
        if witver == 1 and len(program) == 32:
            return 'witness_v1_taproot', segwit_address(1, program)
        return f'witness_v{witver}_other', segwit_address(witver, program)
    return 'nonstandard', None


# --------------------------------------------------------------------------
# Transaction / block deserialization -> getblock-verbosity-2-like dict
# --------------------------------------------------------------------------
def deserialize_tx(r):
    """Read one transaction. Returns a dict shaped like getblock v2's tx entry
    (txid, vin[coinbase flag], vout[{n,value,scriptPubKey{type,address}}]).

    txid is computed from the legacy (non-witness) byte ranges sliced out of the
    original buffer, so it is correct regardless of SegWit/MWEB witness layout."""
    data = r.d
    start = r.i
    r.i32()  # version

    if r.remaining() < 1:          # truncated: fail as EOF, not IndexError
        raise EOFError('truncated tx after version')
    segwit = (data[r.i] == 0x00)   # real input count is never 0, so 0x00 is the marker
    flag = 0
    if segwit:
        r.u8()                     # marker 0x00
        flag = r.u8()              # 0x01 = witness, other bits = MWEB-flavoured
    inputs_start = r.i

    in_count = r.varint()
    # Bound against remaining bytes before allocating: each input needs >=41
    # bytes on the wire, so a peer cannot declare a huge in_count to exhaust
    # memory (mirrors the block-level tx_count guard in deserialize_block).
    if in_count > r.remaining() // 41 + 1:
        raise ValueError(f"implausible in_count {in_count} for {r.remaining()} bytes")
    vin = []
    for _ in range(in_count):
        prev_hash = r.read(32)
        prev_n = r.u32()
        script = r.varbytes()
        seq = r.u32()
        entry = {'sequence': seq}
        if prev_hash == b'\x00' * 32 and prev_n == 0xffffffff:
            entry['coinbase'] = script.hex()
        else:
            entry['txid'] = prev_hash[::-1].hex()
            entry['vout'] = prev_n
        vin.append(entry)

    out_count = r.varint()
    # Each output needs >=9 bytes (u64 value + a script-length varint), so bound
    # the count against what is left before building a dict per output; otherwise
    # one transaction in a 32 MiB block can inflate into gigabytes of objects,
    # all allocated before the merkle check, OOM-killing the scanner.
    if out_count > r.remaining() // 9 + 1:
        raise ValueError(f"implausible out_count {out_count} for {r.remaining()} bytes")
    vout = []
    for n in range(out_count):
        value = r.u64()
        spk = r.varbytes()
        t, addr = classify_output(spk)
        spk_obj = {'type': t}
        if addr is not None:
            spk_obj['address'] = addr
        vout.append({'n': n, 'value': value / 1e8, 'scriptPubKey': spk_obj})

    outputs_end = r.i              # end of outputs, before witness

    if flag & 0x01:                # witness data, only present when the witness bit is set
        for _ in range(in_count):
            for _ in range(r.varint()):
                r.varbytes()
    # MSG_BLOCK gives legacy serialisation, so this branch is unused in practice;
    # kept so the parser also handles a witness block if ever fed one.

    r.u32()                        # locktime
    end = r.i

    if segwit:
        legacy = data[start:start + 4] + data[inputs_start:outputs_end] + data[end - 4:end]
    else:
        legacy = data[start:end]
    txid = dsha256(legacy)[::-1].hex()
    return {'txid': txid, 'vin': vin, 'vout': vout, '_segwit': segwit, '_flag': flag}


def deserialize_block(raw, height=None):
    """Parse a raw block into a getblock-v2-like dict. Trailing MWEB
    extension-block bytes after the transactions are ignored."""
    r = Reader(raw)
    header = r.read(80)
    block_hash = dsha256(header)[::-1].hex()
    hr = Reader(header)
    hr.u32()                       # version
    hr.read(32)                    # prev block
    hdr_merkle = hr.read(32)       # merkle root (internal byte order)
    block_time = hr.u32()

    tx_count = r.varint()
    # Each tx needs >=60 bytes; cap the count up front to bound allocation.
    if tx_count > r.remaining() // 60 + 1:
        raise ValueError(f"implausible tx_count {tx_count} for {r.remaining()} bytes")
    txs = [deserialize_tx(r) for _ in range(tx_count)]
    # Recompute the merkle root from parsed txids and check it against the header.
    leaves = [bytes.fromhex(t['txid'])[::-1] for t in txs]
    root, mutated = merkle_root_checked(leaves)
    merkle_ok = (root == hdr_merkle) and not mutated
    return {
        'hash': block_hash,
        'height': height,
        'time': block_time,
        'tx': txs,
        '_leftover': r.remaining(),
        '_segwit_txs': sum(1 for t in txs if t.get('_segwit')),
        '_merkle_ok': merkle_ok,
    }


# --------------------------------------------------------------------------
# P2P networking. No NODE_MWEB service flag, and request MSG_BLOCK (no witness),
# so peers send the stripped canonical block in legacy serialization.
# --------------------------------------------------------------------------
MSG_BLOCK = 2
NODE_NETWORK = 1 << 0           # serves the full block history
NODE_NETWORK_LIMITED = 1 << 10  # pruned: only the last ~288 blocks
MAX_MSG_BYTES = 32 * 1024 * 1024  # cap on any single P2P message
MAX_WAIT_MSGS = 1000              # rotate peer after this many off-topic msgs

# DNS seeds for the active network (chainparams.cpp); each resolves to many
# live peer IPs.
DNS_SEEDS = _NET['DNS_SEEDS']


def _checksum(payload):
    return dsha256(payload)[:4]


def send_msg(sock, command, payload=b''):
    cmd = command.encode() + b'\x00' * (12 - len(command))
    sock.sendall(MAGIC + cmd + struct.pack('<I', len(payload)) + _checksum(payload) + payload)


def _recv_exact(sock, n):
    buf = b''
    while len(buf) < n:
        chunk = sock.recv(min(65536, n - len(buf)))
        if not chunk:
            raise EOFError('peer closed connection')
        buf += chunk
    return buf


def recv_msg(sock):
    hdr = _recv_exact(sock, 24)
    if hdr[:4] != MAGIC:
        raise ValueError('bad network magic (wrong chain/port?)')
    command = hdr[4:16].rstrip(b'\x00').decode('ascii', 'replace')
    length = struct.unpack('<I', hdr[16:20])[0]
    # Peer-controlled length, up to 4 GiB; cap it before allocating. 32 MiB is
    # well above any real Litecoin message (non-witness blocks are <2 MiB).
    if length > MAX_MSG_BYTES:
        raise ValueError(f'oversized {command} message: {length} bytes')
    payload = _recv_exact(sock, length) if length else b''
    if _checksum(payload) != hdr[20:24]:
        raise ValueError(f'bad checksum on {command}')
    return command, payload


def _version_payload(height=0):
    return (
        struct.pack('<i', PROTOCOL_VERSION)
        + struct.pack('<Q', 0)                          # our services: 0 (no NODE_MWEB)
        + struct.pack('<q', int(time.time()))
        + b'\x00' * 26                                  # addr_recv
        + b'\x00' * 26                                  # addr_from
        + struct.pack('<Q', int(time.time() * 1000) & 0xffffffffffffffff)  # nonce
        + _varint(len(b'/mwebscan:0.1/')) + b'/mwebscan:0.1/'
        + struct.pack('<i', height)
        + b'\x00'                                       # relay = false
    )


def _handshake(sock):
    """Complete the version/verack exchange. Returns the peer's service flags."""
    send_msg(sock, 'version', _version_payload())
    services = 0
    got_version = got_verack = False
    seen = 0
    while not (got_version and got_verack):
        seen += 1
        if seen > MAX_WAIT_MSGS:
            raise ValueError('handshake stalled (too many off-topic messages)')
        cmd, payload = recv_msg(sock)
        if cmd == 'version':
            got_version = True
            if len(payload) < 12:
                raise ValueError('short version payload')
            services = struct.unpack('<Q', payload[4:12])[0]
            send_msg(sock, 'verack')
        elif cmd == 'verack':
            got_verack = True
        elif cmd == 'ping':
            send_msg(sock, 'pong', payload)
    return services


def connect(peer_ip, port=DEFAULT_PORT, timeout=30):
    """Open a connection to a specific peer and complete the handshake."""
    sock = socket.create_connection((peer_ip, port), timeout=timeout)
    sock.settimeout(timeout)
    try:
        _handshake(sock)
    except Exception:
        sock.close()
        raise
    return sock


def discover_peers(max_total=200):
    """Resolve the Litecoin DNS seeds into a de-duplicated list of peer IPs."""
    peers, seen = [], set()
    for seed in DNS_SEEDS:
        try:
            infos = socket.getaddrinfo(seed, DEFAULT_PORT, type=socket.SOCK_STREAM)
        except OSError:
            continue
        for info in infos:
            ip = info[4][0]
            if ip not in seen:
                seen.add(ip)
                peers.append(ip)
    return peers[:max_total]


def connect_any(require_network=True, timeout=10, max_attempts=30):
    """Discover peers via DNS seeds and connect to the first usable one.
    require_network=True insists on a full (NODE_NETWORK) peer for catch-up;
    pruned peers only have the last ~288 blocks."""
    peers = discover_peers()
    random.shuffle(peers)
    last_err = None
    for ip in peers[:max_attempts]:
        sock = None
        try:
            sock = socket.create_connection((ip, DEFAULT_PORT), timeout=timeout)
            sock.settimeout(timeout)
            services = _handshake(sock)
            if require_network and not (services & NODE_NETWORK):
                sock.close()
                continue
            return sock, ip, services
        except (OSError, EOFError, ValueError, struct.error) as e:
            last_err = e
            if sock is not None:
                try:
                    sock.close()
                except OSError:
                    pass
            continue
    raise ConnectionError(f"no suitable peer found in {max_attempts} tries (last: {last_err})")


def fetch_block(sock, block_hash_hex):
    """Request one block by hash (MSG_BLOCK) and return its raw bytes."""
    h = bytes.fromhex(block_hash_hex)[::-1]             # display -> internal byte order
    send_msg(sock, 'getdata', _varint(1) + struct.pack('<I', MSG_BLOCK) + h)
    seen = 0
    while True:
        seen += 1
        if seen > MAX_WAIT_MSGS:
            raise ValueError('peer never returned the requested block')
        cmd, payload = recv_msg(sock)
        if cmd == 'block':
            return payload
        if cmd == 'ping':
            send_msg(sock, 'pong', payload)
        if cmd == 'notfound':
            raise LookupError('peer does not have that block')


def get_headers(sock, locator_hashes, stop_hash=None):
    """Send getheaders with a block locator; return a list of {hash, prev}
    (up to 2000, the children of our locator)."""
    payload = struct.pack('<I', PROTOCOL_VERSION) + _varint(len(locator_hashes))
    for h in locator_hashes:
        payload += bytes.fromhex(h)[::-1]
    payload += bytes.fromhex(stop_hash)[::-1] if stop_hash else b'\x00' * 32
    send_msg(sock, 'getheaders', payload)
    seen = 0
    while True:
        seen += 1
        if seen > MAX_WAIT_MSGS:
            raise ValueError('peer never returned headers')
        cmd, data = recv_msg(sock)
        if cmd == 'headers':
            break
        if cmd == 'ping':
            send_msg(sock, 'pong', data)
    r = Reader(data)
    out = []
    count = r.varint()
    # A `headers` message carries at most MAX_HEADERS_RESULTS (2000) per the P2P
    # protocol. Enforce that before the per-header scrypt PoW check, else a peer
    # can pack a 32 MiB message with ~400k valid headers and pin this daemon on
    # hundreds of thousands of scrypt computations (CPU-exhaustion DoS).
    if count > 2000:
        raise ValueError(f'peer sent too many headers ({count})')
    for _ in range(count):
        hdr = r.read(80)
        r.varint()                                  # txn_count, always 0 in headers
        # Reject any header failing its own PoW, and require the batch to be
        # chain-linked; with the block-hash binding in iter_new_blocks this
        # rejects a fabricated or out-of-order chain.
        if not check_pow(hdr):
            raise ValueError('peer sent a header that fails proof-of-work')
        h = {'hash': dsha256(hdr)[::-1].hex(), 'prev': hdr[4:36][::-1].hex()}
        if out and h['prev'] != out[-1]['hash']:
            raise ValueError('peer sent a header batch that is not chain-linked')
        out.append(h)
    return out


class ReorgDetected(Exception):
    """The peer's chain does not extend our tip. `fork_hash` is the newest
    locator hash the peer recognised: the common ancestor to roll back to.
    Determined from the header chain, not from Electrum."""

    def __init__(self, fork_hash):
        super().__init__(f"reorg: peer built on {fork_hash}")
        self.fork_hash = fork_hash


def iter_new_blocks(sock, locator, have_height):
    """Yield parsed blocks after our tip, walking forward to the chain tip.

    `locator` is a newest-first list of known block hashes (locator[0] is our DB
    tip; the rest are exponentially-spaced fallbacks). If the peer builds on an
    older locator hash than our tip, the tip was reorged out: raise
    ReorgDetected(common_ancestor). Each yielded block is height-tagged and
    shaped for mwebscan.parse_block."""
    tip = locator[0]
    while True:
        headers = get_headers(sock, [tip] + locator[1:])
        if not headers:
            return
        if headers[0]['prev'] != tip:
            # Peer extends an older known hash, not our tip: reorg.
            raise ReorgDetected(headers[0]['prev'])
        for h in headers:
            have_height += 1
            block = deserialize_block(fetch_block(sock, h['hash']), height=have_height)
            # Returned block must be the one we asked for (header already
            # PoW-verified); merkle alone proves consistency, not identity.
            if block['hash'] != h['hash']:
                raise ValueError(f"peer returned the wrong block for {h['hash']}")
            yield block
            tip = h['hash']
        if len(headers) < 2000:
            return


# --------------------------------------------------------------------------
# Electrum client. Used to enrich peg-in source addresses (look up the funding
# prevout's output address), since a node-less daemon has no getrawtransaction.
# Consensus data comes from P2P + merkle, not here; a lying server can only
# mislabel a source.
# --------------------------------------------------------------------------
ELECTRUM_SERVERS = _NET['ELECTRUM_SERVERS']


class ElectrumClient:
    """Minimal Electrum (JSON-over-TLS, newline-delimited) client."""

    def __init__(self, servers=None, timeout=20):
        self.servers = servers or ELECTRUM_SERVERS
        self.timeout = timeout
        self.sock = None
        self.buf = b''
        self._id = 0

    def connect(self):
        ctx = ssl.create_default_context()
        ctx.check_hostname = False                 # many Electrum servers are self-signed
        ctx.verify_mode = ssl.CERT_NONE
        last = None
        for host, port in self.servers:
            try:
                raw = socket.create_connection((host, port), timeout=self.timeout)
                self.sock = ctx.wrap_socket(raw, server_hostname=host)
                self.sock.settimeout(self.timeout)
                self.call('server.version', ['mwebscan', '1.4'])
                return host
            except Exception as e:                 # noqa: BLE001 - try next server
                last = e
                self.sock = None
        raise ConnectionError(f"no Electrum server reachable (last: {last})")

    def call(self, method, params):
        self._id += 1
        self.sock.sendall((json.dumps({'id': self._id, 'method': method, 'params': params}) + '\n').encode())
        while b'\n' not in self.buf:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise EOFError('Electrum server closed connection')
            self.buf += chunk
            # Cap the buffer in case a server streams without a newline.
            if len(self.buf) > MAX_MSG_BYTES:
                raise ValueError('Electrum response exceeded size cap')
        line, self.buf = self.buf.split(b'\n', 1)
        resp = json.loads(line)
        if resp.get('error'):
            raise RuntimeError(f"Electrum error: {resp['error']}")
        return resp.get('result')

    def get_transaction(self, txid):
        """Return the raw transaction hex for txid."""
        return self.call('blockchain.transaction.get', [txid])

    def block_hash_at(self, height):
        """Canonical block hash at a height, to anchor catch-up with no node."""
        return dsha256(bytes.fromhex(self.call('blockchain.block.header', [height])))[::-1].hex()

    def tip_height(self):
        return self.call('blockchain.headers.subscribe', [])['height']

    def close(self):
        if self.sock:
            try:
                self.sock.close()
            finally:
                self.sock = None


def resolve_pegin_sources(electrum, tx):
    """Peg-in source addresses via Electrum, node-less analogue of
    mwebscan.resolve_pegin_inputs. Returns (value_by_address, complete)."""
    value_by_address, complete = {}, True
    for vin in tx.get('vin', []):
        if 'coinbase' in vin:
            continue
        prev_txid, n = vin.get('txid'), vin.get('vout')
        if prev_txid is None or n is None:
            complete = False
            continue
        try:
            prev = deserialize_tx(Reader(bytes.fromhex(electrum.get_transaction(prev_txid))))
            addr = prev['vout'][n]['scriptPubKey'].get('address')
            val = prev['vout'][n]['value']
        except Exception:                          # noqa: BLE001 - skip on lookup failure
            complete = False
            continue
        if addr is None:
            complete = False
            continue
        value_by_address[addr] = value_by_address.get(addr, 0.0) + val
    return value_by_address, complete


# --------------------------------------------------------------------------
# CLI / self-test
# --------------------------------------------------------------------------
def selftest():
    ok = True

    def check(name, cond):
        nonlocal ok
        ok = ok and cond
        print(f"  [{'ok  ' if cond else 'FAIL'}] {name}")

    # base58check: hash160 of all zeros
    addr = b58check(0x00, bytes(20))
    check('base58check runs', isinstance(addr, str) and addr.startswith('1'))
    # bech32 BIP173 reference vector (hrp bc, v0 20-byte zero program)
    global BECH32_HRP
    saved = BECH32_HRP
    BECH32_HRP = 'bc'
    a = segwit_address(0, bytes.fromhex('751e76e8199196d454941c45d1b3a323f1433bd6'))
    BECH32_HRP = saved
    check('bech32 matches BIP173 vector',
          a == 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4')
    check('classify p2pkh', classify_output(bytes.fromhex('76a914' + '00' * 20 + '88ac'))[0] == 'pubkeyhash')
    check('classify pegin (witv9)', classify_output(bytes([0x59, 0x20]) + bytes(32))[0] == 'witness_mweb_pegin')
    check('classify hogaddr (witv8)', classify_output(bytes([0x58, 0x20]) + bytes(32))[0] == 'witness_mweb_hogaddr')
    # Round-trip a tiny legacy (non-witness) tx and confirm a stable 64-hex txid.
    legacy_tx = bytes.fromhex(
        '01000000'                                  # version
        '01'                                        # 1 input
        + '00' * 32 + 'ffffffff'                     # coinbase prevout
        + '0100' + 'ffffffff'                        # scriptSig len 1, seq
        + '01'                                       # 1 output
        + '00f2052a01000000' + '0100'                # value, spk len 1
        + '00000000')                                # locktime
    tx = deserialize_tx(Reader(legacy_tx))
    check('legacy txid is 64 hex + coinbase detected',
          len(tx['txid']) == 64 and 'coinbase' in tx['vin'][0])
    h = bytes.fromhex(tx['txid'])[::-1]
    check('merkle root of 1 tx == its hash', merkle_root([h]) == h)
    check('merkle root of 2 txs', merkle_root([h, h]) == dsha256(h + h))
    print("\nNOTE: validate end-to-end with `fetch 127.0.0.1 <hash>` (P2P gives a"
          " CANONICAL block) and compare to `litecoin-cli getblock <hash> 2`.")
    print("PASS" if ok else "FAIL")
    return ok


def _open(peer):
    """Resolve a peer argument to a connected socket. 'auto' picks a full node
    via DNS-seed discovery; otherwise connect to the given IP."""
    if peer == 'auto':
        sock, ip, services = connect_any()
        print(f"discovered peer {ip} (services=0x{services:x}, "
              f"full={'Y' if services & NODE_NETWORK else 'N'})")
        return sock
    return connect(peer)


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == 'selftest':
        selftest()
    elif len(sys.argv) >= 2 and sys.argv[1] == 'peers':
        peers = discover_peers()
        print(f"discovered {len(peers)} peer IPs via DNS seeds:")
        for ip in peers[:20]:
            print(f"  {ip}")
        if len(peers) > 20:
            print(f"  ... and {len(peers) - 20} more")
    elif len(sys.argv) >= 3 and sys.argv[1] == 'electrum':
        e = ElectrumClient()
        host = e.connect()
        print(f"connected to Electrum server {host}")
        tx = deserialize_tx(Reader(bytes.fromhex(e.get_transaction(sys.argv[2]))))
        e.close()
        print(f"txid {tx['txid']}")
        for v in tx['vout']:
            print(f"  vout {v['n']}: {v['value']:.8f}  "
                  f"{v['scriptPubKey'].get('address', '(' + v['scriptPubKey']['type'] + ')')}")
    elif len(sys.argv) >= 3 and sys.argv[1] == 'parse':
        # Compare to getblock <hash> 2 for the same block.
        raw = bytes.fromhex(open(sys.argv[2]).read().strip())
        _print_block(deserialize_block(raw))
    elif len(sys.argv) >= 4 and sys.argv[1] == 'fetch':
        # Fetch a block over P2P and parse it; compare to getblock <hash> 2.
        peer, block_hash = sys.argv[2], sys.argv[3]
        sock = _open(peer)
        print("requesting block...")
        raw = fetch_block(sock, block_hash)
        sock.close()
        print(f"received {len(raw)} bytes")
        _print_block(deserialize_block(raw))
    elif len(sys.argv) >= 5 and sys.argv[1] == 'catchup':
        # Dry-run catch-up from (hash, height) to the tip, one summary line per
        # block, no DB writes. Optional 5th arg caps the block count.
        peer, start_hash, start_height = sys.argv[2], sys.argv[3], int(sys.argv[4])
        limit = int(sys.argv[5]) if len(sys.argv) > 5 else None
        sock = _open(peer)
        print(f"catching up from height {start_height}...")
        n = 0
        for block in iter_new_blocks(sock, [start_hash], start_height):
            n += 1
            pegins = sum(1 for t in block['tx'] for v in t['vout']
                         if v['scriptPubKey']['type'] == 'witness_mweb_pegin')
            hog = next((t for t in block['tx'] if t['vout']
                        and t['vout'][0]['scriptPubKey']['type'] == 'witness_mweb_hogaddr'), None)
            supply = hog['vout'][0]['value'] if hog else None
            print(f"  +{block['height']} {block['hash'][:16]}... txs={len(block['tx']):4d} "
                  f"pegins={pegins} hogex={'Y' if hog else 'N'} "
                  f"supply={supply if supply is not None else '-'}")
            if limit and n >= limit:
                break
        sock.close()
        print(f"caught up {n} blocks")
    else:
        print(__doc__)


def _print_block(block):
    print(f"hash  {block['hash']}")
    print(f"time  {block['time']}   txs {len(block['tx'])}")
    # leftover==0 means the block was consumed exactly; segwit_txs shows whether
    # the peer stripped witness data.
    print(f"leftover bytes after parse: {block.get('_leftover')}   "
          f"segwit txs: {block.get('_segwit_txs')}/{len(block['tx'])}   "
          f"merkle_ok: {block.get('_merkle_ok')}")
    hist = {}
    for t in block['tx']:
        for v in t['vout']:
            ty = v['scriptPubKey']['type']
            hist[ty] = hist.get(ty, 0) + 1
    print("output type histogram:")
    for ty, n in sorted(hist.items(), key=lambda kv: -kv[1]):
        print(f"  {n:6d}  {ty}")
    pegins = [(t['txid'], v['value']) for t in block['tx']
              for v in t['vout'] if v['scriptPubKey']['type'] == 'witness_mweb_pegin']
    print(f"peg-ins ({len(pegins)}):")
    for txid, val in pegins:
        print(f"  {val:.8f}  {txid}")
    hogex = next((t for t in block['tx']
                  if t['vout'] and t['vout'][0]['scriptPubKey']['type'] == 'witness_mweb_hogaddr'), None)
    if hogex:
        pegouts = [v for v in hogex['vout'][1:] if v['scriptPubKey']['type'] != 'witness_mweb_hogaddr']
        print(f"HogEx {hogex['txid']}")
        print(f"  supply (hogaddr) {hogex['vout'][0]['value']:.8f}")
        print(f"  peg-outs ({len(pegouts)}):")
        for v in pegouts:
            print(f"    {v['value']:.8f}  {v['scriptPubKey'].get('address', '(no addr)')}")
    else:
        print("no HogEx found (pre-activation block, or canonical-block PARSER BUG)")


if __name__ == '__main__':
    main()
