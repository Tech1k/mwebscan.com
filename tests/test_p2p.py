"""Offline unit tests for the node-less P2P fetcher (tools/p2p_fetch.py).

No network required. Builds a synthetic legacy block (the serialization a
non-MWEB peer sends for MSG_BLOCK) from raw bytes and locks down: tx/txid
parsing, witness-version classification (peg-in = v9, hogaddr = v8), merkle-root
verification, full-block consumption (leftover == 0), and the message framing /
varint helpers used on the wire.
"""

import os
import sys
import struct

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, REPO)
import tools.p2p_fetch as p2p  # noqa: E402

passed, failed = [], []


class _FakeSock:
    """Feeds canned bytes to recv() so we can exercise the wire framing offline."""

    def __init__(self, data):
        self.data, self.i = data, 0

    def recv(self, n):
        chunk = self.data[self.i:self.i + n]
        self.i += len(chunk)
        return chunk


def check(name, ok):
    (passed if ok else failed).append(name)
    print(f"  [{'ok  ' if ok else 'FAIL'}] {name}")


def _tx_coinbase():
    # version, 1 input (null prevout), scriptSig '03aabbcc', seq, 1 p2pkh output, locktime
    return bytes.fromhex(
        '01000000' '01' + '00' * 32 + 'ffffffff' '0403aabbcc' 'ffffffff'
        '01' '00f2052a01000000' '1976a914' + '11' * 20 + '88ac' '00000000')


def _tx_with_outputs(spk_hexes):
    """A legacy tx with a dummy input and the given scriptPubKeys (value 1 LTC each)."""
    body = '01000000' '01' + '22' * 32 + '00000000' '00' 'ffffffff'
    body += format(len(spk_hexes), '02x')
    for spk in spk_hexes:
        body += '00e1f50500000000'                       # 1.0 LTC
        body += format(len(spk) // 2, '02x') + spk
    body += '00000000'
    return bytes.fromhex(body)


def _build_block(txs):
    """Assemble header(80) + varint(txcount) + txs, with a correct merkle root."""
    merkle = p2p.merkle_root([p2p.dsha256(t) for t in txs])
    header = (struct.pack('<I', 0x20000000) + b'\x33' * 32 + merkle
              + struct.pack('<I', 1700000000) + struct.pack('<I', 0x1a00ffff) + struct.pack('<I', 7))
    assert len(header) == 80
    return header + p2p._varint(len(txs)) + b''.join(txs)


def main():
    # The shipped self-test (base58/bech32/classification/merkle helpers).
    check('p2p self-test passes', p2p.selftest() is True)

    # Witness-version classification: pegin=v9, hogaddr=v8.
    check('pegin = witness v9 (0x59)',
          p2p.classify_output(bytes([0x59, 0x20]) + bytes(32))[0] == 'witness_mweb_pegin')
    check('hogaddr = witness v8 (0x58)',
          p2p.classify_output(bytes([0x58, 0x20]) + bytes(32))[0] == 'witness_mweb_hogaddr')

    # varint round-trips across size boundaries.
    check('varint encodings',
          p2p._varint(0x10) == b'\x10' and p2p._varint(0xfd) == b'\xfd\xfd\x00'
          and p2p._varint(0x10000) == b'\xfe\x00\x00\x01\x00')

    # Build a realistic post-activation block and parse it.
    pegin_spk = '59' '20' + 'aa' * 32                    # OP_9 push32 -> peg-in
    hog_spk = '58' '20' + 'bb' * 32                      # OP_8 push32 -> hogaddr
    pegout_spk = '0014' + 'cc' * 20                      # v0 keyhash peg-out
    txs = [
        _tx_coinbase(),
        _tx_with_outputs([pegin_spk]),
        _tx_with_outputs([hog_spk, pegout_spk]),         # HogEx: hogaddr + 1 peg-out
    ]
    raw = _build_block(txs)
    block = p2p.deserialize_block(raw, height=2300000)

    check('parsed all txs', len(block['tx']) == 3)
    check('consumed whole block (leftover 0)', block['_leftover'] == 0)
    check('merkle root verifies', block['_merkle_ok'] is True)
    check('no segwit txs in a legacy block', block['_segwit_txs'] == 0)

    parsed = _classify(block)
    check('one peg-in found', parsed['pegins'] == 1)
    check('hogaddr/HogEx detected', parsed['hogex'] is True)
    check('one peg-out found', parsed['pegouts'] == 1)

    # A corrupted tx must fail the merkle check.
    bad = bytearray(raw)
    bad[-40] ^= 0xff
    check('merkle fails on tampered block', p2p.deserialize_block(bytes(bad))['_merkle_ok'] is False)

    # A segwit tx round-trips to a stable txid via the legacy byte-slice path.
    segwit_tx = bytes.fromhex(
        '01000000' '0001'                                # version + segwit marker/flag
        '01' + '44' * 32 + '00000000' '00' 'ffffffff'    # 1 input, empty scriptSig
        '01' '00e1f50500000000' '1976a914' + '55' * 20 + '88ac'
        '0103aabbcc'                                     # 1 witness item
        '00000000')
    stx = p2p.deserialize_tx(p2p.Reader(segwit_tx))
    check('segwit tx parses with witness stripped from txid',
          len(stx['txid']) == 64 and stx['_segwit'] is True and stx['vout'][0]['value'] == 1.0)

    # ---- malformed / malicious input ----

    # recv_msg must reject an oversized message length.
    def _hdr(cmd, length):
        c = cmd.encode() + b'\x00' * (12 - len(cmd))
        return p2p.MAGIC + c + struct.pack('<I', length) + b'\x00\x00\x00\x00'

    try:
        p2p.recv_msg(_FakeSock(_hdr('block', 0xFFFFFFFF)))
        check('recv_msg rejects oversized message', False)
    except ValueError:
        check('recv_msg rejects oversized message', True)

    # A block with a duplicated adjacent (even-aligned) tx pair (CVE-2012-2459)
    # must fail merkle even though the naive root still matches the header.
    dup = _build_block([_tx_coinbase(), txs[1], txs[2], txs[2]])
    dblock = p2p.deserialize_block(dup)
    check('duplicated-tx block parses fully', dblock['_leftover'] == 0 and len(dblock['tx']) == 4)
    check('CVE-2012-2459 mutated tree rejected', dblock['_merkle_ok'] is False)
    _, mut = p2p.merkle_root_checked([b'\x11' * 32, b'\x11' * 32])
    check('merkle_root_checked flags adjacent duplicate', mut is True)

    # A tx truncated right after the version must raise EOFError, not IndexError.
    try:
        p2p.deserialize_tx(p2p.Reader(bytes.fromhex('01000000')))
        check('truncated tx raises EOFError', False)
    except EOFError:
        check('truncated tx raises EOFError', True)
    except IndexError:
        check('truncated tx raises EOFError', False)

    # An absurd tx-count in a block header must be rejected before allocating.
    absurd = (struct.pack('<I', 1) + b'\x00' * 32 + b'\x00' * 32
              + struct.pack('<I', 1) + struct.pack('<I', 0) + struct.pack('<I', 0)
              + b'\xff' + struct.pack('<Q', 10 ** 9))
    try:
        p2p.deserialize_block(absurd)
        check('absurd tx_count rejected', False)
    except ValueError:
        check('absurd tx_count rejected', True)

    print(f"\n{len(passed)} passed, {len(failed)} failed")
    if failed:
        print("FAILED: " + ", ".join(failed))
        sys.exit(1)
    print("All P2P unit tests passed.")


def _classify(block):
    pegins = sum(1 for t in block['tx'] for v in t['vout']
                 if v['scriptPubKey']['type'] == 'witness_mweb_pegin')
    hog = next((t for t in block['tx'] if t['vout']
                and t['vout'][0]['scriptPubKey']['type'] == 'witness_mweb_hogaddr'), None)
    pegouts = 0
    if hog:
        pegouts = sum(1 for v in hog['vout'][1:]
                      if v['scriptPubKey']['type'] != 'witness_mweb_hogaddr')
    return {'pegins': pegins, 'hogex': hog is not None, 'pegouts': pegouts}


if __name__ == '__main__':
    main()
