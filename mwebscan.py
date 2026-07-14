import requests
import sqlite3
import json
import time
import os
import concurrent.futures

from network import PARAMS as _NET
import tools.p2p_fetch as _p2p   # MWEB-aware raw-block parser, shared with mwebp2p

# Credentials come from the environment, not source.
RPC_URL = os.environ.get('LTC_RPC_URL', _NET['RPC_URL'])
RPC_USER = os.environ.get('LTC_RPC_USER', 'litecoinrpc')
RPC_PASSWORD = os.environ.get('LTC_RPC_PASSWORD', 'litecoinrpcpass')
COMMIT_EVERY_N_BLOCKS = 100
POLL_INTERVAL = 180
# Abandon an RPC call after this many seconds so a hung node can't stall us.
RPC_TIMEOUT = 120

# Performance knobs, env-overridable. Defaults suit ~4 GB hosts.
#   Big box (fast initial sync): MWEBSCAN_RPC_WORKERS=16 MWEBSCAN_BATCH_SIZE=200
#                                MWEBSCAN_CACHE_MIB=1024 MWEBSCAN_MMAP_MIB=16384
#   Raspberry Pi (steady state): MWEBSCAN_RPC_WORKERS=2  MWEBSCAN_BATCH_SIZE=20
#                                MWEBSCAN_CACHE_MIB=64   MWEBSCAN_MMAP_MIB=0
# Blocks held in memory per pass; bounds peak memory.
BATCH_SIZE = int(os.environ.get('MWEBSCAN_BATCH_SIZE', '100'))
# getblock calls per HTTP request. Kept small: 100/request was >1 GB and broke
# the connection. Set to 1 for one block per request.
GETBLOCK_BATCH = int(os.environ.get('MWEBSCAN_GETBLOCK_BATCH', '4'))
# Concurrent getblock requests. Set near the node's -rpcthreads.
RPC_WORKERS = int(os.environ.get('MWEBSCAN_RPC_WORKERS', '8'))
# Some Litecoin Core builds return HTTP 500 on getblock verbosity 2 for MWEB
# blocks (serializing the range proofs/kernels into JSON aborts). When a whole
# window fails that way, fall back to raw blocks (verbosity 0) parsed locally
# with the MWEB-aware deserializer. MWEBSCAN_RAW_BLOCKS=1 skips the v2 attempt
# and uses raw blocks from the start.
RAW_BLOCKS = os.environ.get('MWEBSCAN_RAW_BLOCKS', '0') == '1'
# SQLite page cache (MiB) and memory-map (MiB).
SQLITE_CACHE_MIB = int(os.environ.get('MWEBSCAN_CACHE_MIB', '256'))
SQLITE_MMAP_MIB = int(os.environ.get('MWEBSCAN_MMAP_MIB', '512'))
# Recent blocks re-verified each poll to catch reorgs.
REORG_DEPTH = 12

# MWEB activation height (network-specific; see network.py).
MWEB_ACTIVATION_HEIGHT = _NET['MWEB_ACTIVATION_HEIGHT']

# Record each peg-in's public funding address (the input contributing the most
# value), tying the MWEB entry to a public identity for address-reuse linking.
# Needs txindex=1 and an RPC lookup per peg-in input, so it slows initial sync.
TRACK_PEGIN_SOURCES = False

# scriptPubKey "type" values reported by Litecoin Core for MWEB outputs.
HOGADDR_TYPE = 'witness_mweb_hogaddr'
PEGIN_TYPE = 'witness_mweb_pegin'

conn = sqlite3.connect(_NET['DB_FILENAME'])
cursor = conn.cursor()
conn.execute("PRAGMA journal_mode = WAL;")
# NORMAL is crash-safe under WAL and nearly as fast as OFF, which risks
# corruption on power loss for a long-running daemon.
conn.execute("PRAGMA synchronous = NORMAL;")
# Wait instead of failing if another process holds the write lock. The
# analysis pass (mwebanalysis.py) recomputes the whole dataset inside one
# write transaction and can hold the lock for tens of seconds, so a short
# timeout guarantees the scanner collides with it; 60s rides out a normal
# pass. The retry loop still rolls back on the rare overrun (see mwebp2p.py).
conn.execute("PRAGMA busy_timeout = 60000;")
# Page cache + mmap sized per host; in-memory temp; large WAL checkpoint
# interval to avoid stalls during bulk sync.
conn.execute(f"PRAGMA cache_size = {-SQLITE_CACHE_MIB * 1024};")
conn.execute(f"PRAGMA mmap_size = {SQLITE_MMAP_MIB * 1024 * 1024};")
conn.execute("PRAGMA temp_store = MEMORY;")
conn.execute("PRAGMA wal_autocheckpoint = 20000;")


def init_db():
    # Peg-ins: public outputs moving coins into MWEB. No public destination
    # (coins enter the sidechain), but the funding side is public.
    # source_address = input address contributing the most value (optional).
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS mweb_pegins (
            txid TEXT,
            vout INTEGER,
            block_height INTEGER,
            block_time INTEGER,
            amount REAL,
            source_address TEXT,
            input_count INTEGER,
            PRIMARY KEY (txid, vout)
        )
    ''')

    # Peg-outs: HogEx outputs moving coins out of MWEB back to the public
    # chain. These have a visible destination address and amount.
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS mweb_pegouts (
            txid TEXT,
            vout INTEGER,
            block_height INTEGER,
            block_time INTEGER,
            amount REAL,
            address TEXT,
            PRIMARY KEY (txid, vout)
        )
    ''')

    # Per-block time-series, one row per block with a HogEx: absolute MWEB
    # supply (hogaddr value) plus in/out activity. Backs supply charts,
    # net-flow, and anonymity-set sizing.
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS mweb_blocks (
            block_height INTEGER PRIMARY KEY,
            block_time INTEGER,
            block_hash TEXT,
            hogex_txid TEXT,
            supply REAL,
            pegin_count INTEGER,
            pegin_amount REAL,
            pegout_count INTEGER,
            pegout_amount REAL,
            mweb_kernels INTEGER,
            mweb_txos INTEGER
        )
    ''')
    # Migrate DBs created before the MWEB-metric columns existed (cumulative
    # kernel-MMR size and current TXO-set size, from the block's mweb header).
    # Race-safe: if another process (e.g. the analysis pass) added the column
    # first, ignore the duplicate rather than crashing.
    for _col in ('mweb_kernels', 'mweb_txos'):
        try:
            cursor.execute("ALTER TABLE mweb_blocks ADD COLUMN %s INTEGER" % _col)
        except sqlite3.OperationalError as _e:
            if 'duplicate column' not in str(_e).lower():
                raise

    cursor.execute('''
        CREATE TABLE IF NOT EXISTS scan_progress (
            id INTEGER PRIMARY KEY,
            last_scanned_block INTEGER
        )
    ''')

    # Every public funding input of each peg-in tx. Addresses co-spending in
    # one peg-in tx are taken to share an owner (common-input-ownership), which
    # drives wallet clustering. Only populated when TRACK_PEGIN_SOURCES is set.
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS pegin_inputs (
            pegin_txid TEXT,
            address TEXT,
            value REAL,
            PRIMARY KEY (pegin_txid, address)
        )
    ''')
    conn.commit()


def build_indexes():
    """Build secondary indexes. Deferred until after the initial bulk sync so
    millions of inserts don't pay index write amplification. Idempotent."""
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_blocks_hash ON mweb_blocks(block_hash)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegin_inputs_addr ON pegin_inputs(address)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegins_amount ON mweb_pegins(amount)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegins_height ON mweb_pegins(block_height)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegins_source ON mweb_pegins(source_address)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegouts_amount ON mweb_pegouts(amount)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegouts_height ON mweb_pegouts(block_height)')
    cursor.execute('CREATE INDEX IF NOT EXISTS idx_pegouts_address ON mweb_pegouts(address)')
    conn.commit()


# Keep-alive pool sized for concurrent block fetching, avoiding a handshake
# per RPC call.
SESSION = requests.Session()
_adapter = requests.adapters.HTTPAdapter(pool_connections=RPC_WORKERS, pool_maxsize=RPC_WORKERS)
SESSION.mount('http://', _adapter)
SESSION.mount('https://', _adapter)

# orjson if available; verbosity-2 responses are large and decode cost matters.
try:
    import orjson

    def _loads(raw):
        return orjson.loads(raw)
except ImportError:
    def _loads(raw):
        return json.loads(raw)


# Network errors worth retrying.
_TRANSIENT = (
    requests.exceptions.ChunkedEncodingError,
    requests.exceptions.ConnectionError,
    requests.exceptions.Timeout,
)


def _post(payload, retries=4):
    """POST a JSON-RPC payload with retry/backoff on transient network errors."""
    for attempt in range(retries):
        try:
            response = SESSION.post(
                RPC_URL,
                data=payload,
                headers={'Content-Type': 'text/plain'},
                auth=(RPC_USER, RPC_PASSWORD),
                timeout=RPC_TIMEOUT
            )
            response.raise_for_status()
            return _loads(response.content)
        except _TRANSIENT as e:
            if attempt == retries - 1:
                raise
            print(f"  RPC transient error ({e.__class__.__name__}); retry {attempt + 1}/{retries}")
            time.sleep(2 ** attempt)


def rpc_request(method, params=None):
    payload = json.dumps({'jsonrpc': '1.0', 'id': 'python', 'method': method, 'params': params or []})
    return _post(payload)['result']


def rpc_batch(calls):
    """Send many small JSON-RPC calls in one request (e.g. getblockhash). Not
    for getblock verbosity 2: 100 full blocks in one response is >1 GB and
    breaks the connection. Use fetch_blocks() for those."""
    payload = json.dumps([
        {'jsonrpc': '1.0', 'id': i, 'method': method, 'params': params or []}
        for i, (method, params) in enumerate(calls)
    ])
    results = [None] * len(calls)
    for item in _post(payload):
        idx = item.get('id')
        # Only accept an in-range integer id; skip malformed/error items.
        if isinstance(idx, int) and 0 <= idx < len(calls):
            results[idx] = item.get('result')
    return results


def fetch_blocks(hashes):
    """Fetch getblock (verbosity 2) in GETBLOCK_BATCH-sized requests across
    RPC_WORKERS threads. Small batches keep each response bounded (tens of MB).
    Returns {hash: block}; a sub-batch that fails after retries maps its hashes
    to None so the caller stalls and retries."""
    wanted = [h for h in hashes if h]
    if not wanted:
        return {}
    subs = [wanted[i:i + GETBLOCK_BATCH] for i in range(0, len(wanted), GETBLOCK_BATCH)]

    def fetch_sub(sub):
        try:
            return rpc_batch([('getblock', [h, 2]) for h in sub])
        except Exception as e:
            print(f"  getblock batch failed ({sub[0]}...): {e}")
            return [None] * len(sub)

    result = {}
    if RPC_WORKERS > 1 and len(subs) > 1:
        with concurrent.futures.ThreadPoolExecutor(max_workers=RPC_WORKERS) as ex:
            for sub, blocks in zip(subs, ex.map(fetch_sub, subs)):
                result.update(zip(sub, blocks))
    else:
        for sub in subs:
            result.update(zip(sub, fetch_sub(sub)))
    return result


def fetch_blocks_raw(heights, hashes):
    """Fetch blocks as raw hex (getblock verbosity 0) and parse them locally with
    the MWEB-aware deserializer, returning {hash: block} shaped like fetch_blocks.
    Used when the node can't serialize verbosity 2 (some Litecoin Core builds 500
    on MWEB blocks). A block whose local merkle check fails is dropped so the
    caller retries instead of persisting a misparse."""
    pairs = [(h, bh) for h, bh in zip(heights, hashes) if bh]
    subs = [pairs[i:i + GETBLOCK_BATCH] for i in range(0, len(pairs), GETBLOCK_BATCH)]

    def fetch_sub(sub):
        try:
            raws = rpc_batch([('getblock', [bh, 0]) for _, bh in sub])
        except Exception as e:
            print(f"  raw getblock batch failed ({sub[0][0]}...): {e}")
            raws = [None] * len(sub)
        out = {}
        for (h, bh), raw in zip(sub, raws):
            if not raw:
                continue
            block = _p2p.deserialize_block(bytes.fromhex(raw), h)
            if block.get('_merkle_ok'):
                out[bh] = block
            else:
                print(f"  raw block {h} failed local merkle check; skipping")
        return out

    result = {}
    if RPC_WORKERS > 1 and len(subs) > 1:
        with concurrent.futures.ThreadPoolExecutor(max_workers=RPC_WORKERS) as ex:
            for part in ex.map(fetch_sub, subs):
                result.update(part)
    else:
        for sub in subs:
            result.update(fetch_sub(sub))
    return result


def fetch_mweb(hashes):
    """Per-block MWEB summary (num_kernels / num_txos) via getblock verbosity 1 --
    the mweb header fields without the heavy inputs/outputs/kernels arrays that
    make verbosity 2 fail on MWEB blocks. Needs a full node (RPC); the node-less
    P2P path can't supply it. Returns {hash: mweb_dict} for blocks that have one."""
    subs = [hashes[i:i + GETBLOCK_BATCH] for i in range(0, len(hashes), GETBLOCK_BATCH)]

    def fetch_sub(sub):
        try:
            res = rpc_batch([('getblock', [h, 1]) for h in sub])
        except Exception as e:
            print(f"  getblock v1 (mweb) batch failed ({sub[0]}...): {e}")
            res = [None] * len(sub)
        return {h: v['mweb'] for h, v in zip(sub, res)
                if isinstance(v, dict) and isinstance(v.get('mweb'), dict)}

    out = {}
    if RPC_WORKERS > 1 and len(subs) > 1:
        with concurrent.futures.ThreadPoolExecutor(max_workers=RPC_WORKERS) as ex:
            for part in ex.map(fetch_sub, subs):
                out.update(part)
    else:
        for sub in subs:
            out.update(fetch_sub(sub))
    return out


def fetch_window(heights, hashes):
    """Return {hash: block} for a window. Uses getblock verbosity 2, then fills
    any block the node couldn't serialize from raw blocks (verbosity 0). If a
    whole window fails v2, switch to raw for the rest of the session: that means
    the node can't serialize MWEB blocks to JSON at all, so retrying v2 is waste."""
    global RAW_BLOCKS
    valid = [(h, bh) for h, bh in zip(heights, hashes) if bh]
    blocks = {} if RAW_BLOCKS else fetch_blocks(hashes)
    missing = [(h, bh) for h, bh in valid if not blocks.get(bh)]
    if missing:
        if not RAW_BLOCKS and valid and len(missing) == len(valid):
            print("  getblock verbosity 2 unavailable; switching to raw-block parsing")
            RAW_BLOCKS = True
        blocks.update(fetch_blocks_raw([h for h, _ in missing], [bh for _, bh in missing]))
    # Attach the per-block MWEB summary. getblock 2 already carries 'mweb'; the
    # raw (verbosity 0) path doesn't, so fetch it cheaply via getblock 1.
    need_mweb = [bh for _, bh in valid if blocks.get(bh) and 'mweb' not in blocks[bh]]
    if need_mweb:
        for bh, summary in fetch_mweb(need_mweb).items():
            blocks[bh]['mweb'] = summary
    return blocks


def get_last_scanned_block():
    cursor.execute("SELECT last_scanned_block FROM scan_progress WHERE id = 1")
    row = cursor.fetchone()
    return int(row[0]) if row else MWEB_ACTIVATION_HEIGHT


def set_last_scanned_block(height):
    cursor.execute('''
        INSERT INTO scan_progress (id, last_scanned_block) VALUES (1, ?)
        ON CONFLICT(id) DO UPDATE SET last_scanned_block=excluded.last_scanned_block
    ''', (height,))


def vout_address(vout):
    """Litecoin Core returns 'address' (newer) or an 'addresses' list (older)."""
    spk = vout.get('scriptPubKey', {})
    if 'address' in spk:
        return spk['address']
    addrs = spk.get('addresses')
    if addrs:
        return addrs[0]
    return None


def resolve_pegin_inputs(tx):
    """Return (value_by_address, complete) for a peg-in's public funding
    inputs. `complete` is False if any input couldn't be resolved, so callers
    can skip a misleading partial dominant-source/cluster. Requires txindex=1."""
    value_by_address = {}
    complete = True
    for vin in tx.get('vin', []):
        if 'coinbase' in vin:
            continue
        prev_txid = vin.get('txid')
        prev_n = vin.get('vout')
        if prev_txid is None or prev_n is None:
            complete = False
            continue
        try:
            prev = rpc_request('getrawtransaction', [prev_txid, True])
            prev_vout = prev['vout'][prev_n]
        except Exception as e:
            print(f"  source lookup failed for {prev_txid}:{prev_n}: {e}")
            complete = False
            continue
        addr = vout_address(prev_vout)
        if addr is None:
            complete = False
            continue
        value_by_address[addr] = value_by_address.get(addr, 0.0) + prev_vout.get('value', 0.0)
    return value_by_address, complete


def parse_block(block):
    """Extract peg-ins, peg-outs and the supply snapshot from one block.

    Peg-ins are witness_mweb_pegin outputs on any tx. The HogEx is the tx whose
    first output is the witness_mweb_hogaddr; its value is the absolute MWEB
    supply and its remaining outputs are peg-outs.
    """
    height = block['height']
    block_time = block.get('time')
    block_hash = block.get('hash')
    mweb = block.get('mweb') or {}

    pegins = []
    pegouts = []
    supply = None
    hogex_txid = None

    for tx in block['tx']:
        vouts = tx.get('vout', [])
        is_hogex = bool(vouts) and vouts[0].get('scriptPubKey', {}).get('type') == HOGADDR_TYPE

        if is_hogex:
            hogex_txid = tx['txid']
            supply = vouts[0].get('value', 0.0)
            # Outputs after the hogaddr are peg-outs to the public chain.
            for vout in vouts[1:]:
                if vout.get('scriptPubKey', {}).get('type') == HOGADDR_TYPE:
                    continue
                pegouts.append((
                    tx['txid'], vout['n'], height, block_time,
                    vout.get('value', 0.0), vout_address(vout)
                ))

        for vout in vouts:
            if vout.get('scriptPubKey', {}).get('type') == PEGIN_TYPE:
                pegins.append({
                    'txid': tx['txid'],
                    'vout': vout['n'],
                    'height': height,
                    'time': block_time,
                    'amount': vout.get('value', 0.0),
                })

    return {
        'height': height,
        'block_time': block_time,
        'block_hash': block_hash,
        'hogex_txid': hogex_txid,
        'supply': supply,
        'pegins': pegins,
        'pegouts': pegouts,
        'mweb_kernels': mweb.get('num_kernels'),
        'mweb_txos': mweb.get('num_txos'),
    }


def persist_block(parsed, sources=None, pegin_input_rows=None):
    sources = sources or {}
    if parsed['pegins']:
        rows = []
        for p in parsed['pegins']:
            src_addr, src_count = sources.get(p['txid'], (None, None))
            rows.append((p['txid'], p['vout'], p['height'], p['time'],
                         p['amount'], src_addr, src_count))
        cursor.executemany('''
            INSERT OR IGNORE INTO mweb_pegins
                (txid, vout, block_height, block_time, amount, source_address, input_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ''', rows)

    if pegin_input_rows:
        cursor.executemany('''
            INSERT OR IGNORE INTO pegin_inputs (pegin_txid, address, value)
            VALUES (?, ?, ?)
        ''', pegin_input_rows)

    if parsed['pegouts']:
        cursor.executemany('''
            INSERT OR IGNORE INTO mweb_pegouts (txid, vout, block_height, block_time, amount, address)
            VALUES (?, ?, ?, ?, ?, ?)
        ''', parsed['pegouts'])

    # Block-level snapshot only when the block has a HogEx.
    if parsed['hogex_txid'] is not None:
        pegin_amount = sum(p['amount'] for p in parsed['pegins'])
        pegout_amount = sum(p[4] for p in parsed['pegouts'])
        cursor.execute('''
            INSERT INTO mweb_blocks
                (block_height, block_time, block_hash, hogex_txid, supply,
                 pegin_count, pegin_amount, pegout_count, pegout_amount,
                 mweb_kernels, mweb_txos)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(block_height) DO UPDATE SET
                block_time=excluded.block_time,
                block_hash=excluded.block_hash,
                hogex_txid=excluded.hogex_txid,
                supply=excluded.supply,
                pegin_count=excluded.pegin_count,
                pegin_amount=excluded.pegin_amount,
                pegout_count=excluded.pegout_count,
                pegout_amount=excluded.pegout_amount,
                mweb_kernels=COALESCE(excluded.mweb_kernels, mweb_blocks.mweb_kernels),
                mweb_txos=COALESCE(excluded.mweb_txos, mweb_blocks.mweb_txos)
        ''', (
            parsed['height'], parsed['block_time'], parsed['block_hash'],
            parsed['hogex_txid'], parsed['supply'],
            len(parsed['pegins']), pegin_amount,
            len(parsed['pegouts']), pegout_amount,
            parsed.get('mweb_kernels'), parsed.get('mweb_txos'),
        ))


def process_block(block):
    """Parse a block, resolve peg-in funding sources (if enabled), and persist."""
    parsed = parse_block(block)

    sources = {}
    pegin_input_rows = []
    if TRACK_PEGIN_SOURCES and parsed['pegins']:
        pegin_txids = {p['txid'] for p in parsed['pegins']}
        for tx in block['tx']:
            if tx['txid'] in pegin_txids and tx['txid'] not in sources:
                value_by_address, complete = resolve_pegin_inputs(tx)
                # Record source + cluster only when every input resolved; a
                # partial set picks a wrong dominant funder and under-clusters.
                if value_by_address and complete:
                    dominant = max(value_by_address, key=value_by_address.get)
                    sources[tx['txid']] = (dominant, len(value_by_address))
                    for addr, val in value_by_address.items():
                        pegin_input_rows.append((tx['txid'], addr, val))
                else:
                    sources[tx['txid']] = (None, 0)

    persist_block(parsed, sources, pegin_input_rows)
    return parsed


def scan_blocks():
    start = get_last_scanned_block() + 1
    tip = rpc_request('getblockcount')

    if tip < start:
        return

    window = BATCH_SIZE
    height = start
    while height <= tip:
        win_end = min(height + window - 1, tip)
        heights = list(range(height, win_end + 1))

        hashes = rpc_batch([('getblockhash', [h]) for h in heights])
        block_by_hash = fetch_window(heights, hashes)

        # Process in height order, stopping at the first gap. Advance the cursor
        # only over the contiguous successful prefix so a transient failure
        # can't leave a permanently-skipped block.
        last_ok = height - 1
        pegins = pegouts = 0
        stalled = False
        for h, bh in zip(heights, hashes):
            block = block_by_hash.get(bh) if bh else None
            if not block:
                print(f"  block {h} unavailable; stopping window, will retry next pass")
                stalled = True
                break
            parsed = process_block(block)
            pegins += len(parsed['pegins'])
            pegouts += len(parsed['pegouts'])
            last_ok = h

        if last_ok >= height:
            set_last_scanned_block(last_ok)
            conn.commit()
            print(f"Scanned {height}-{last_ok} (tip {tip}) | peg-ins: {pegins} | peg-outs: {pegouts}")

        if stalled:
            return  # cursor stays at last_ok; next poll retries the gap
        height = win_end + 1

    conn.commit()


def rollback_to(height):
    """Undo data above `height` after a chain reorganisation."""
    print(f"Reorg detected: rolling back to block {height}")
    cursor.execute("DELETE FROM mweb_pegins WHERE block_height > ?", (height,))
    cursor.execute("DELETE FROM mweb_pegouts WHERE block_height > ?", (height,))
    cursor.execute("DELETE FROM mweb_blocks WHERE block_height > ?", (height,))
    cursor.execute("DELETE FROM pegin_inputs WHERE pegin_txid NOT IN (SELECT txid FROM mweb_pegins)")
    # watch_hits is additive with no recompute path, so drop rolled-back rows
    # here to avoid false webhook history. Analysis tables are rebuilt later.
    if cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='watch_hits'").fetchone():
        cursor.execute("DELETE FROM watch_hits WHERE block_height > ?", (height,))
    set_last_scanned_block(height)
    conn.commit()


def check_reorg():
    """Re-verify recent stored block hashes against the node; roll back on fork.

    Walks back from the tip in widening windows until a stored hash still
    matches the node, then rolls back to that common ancestor. Handles reorgs
    deeper than REORG_DEPTH."""
    depth = REORG_DEPTH
    while True:
        rows = cursor.execute('''
            SELECT block_height, block_hash FROM mweb_blocks
            WHERE block_hash IS NOT NULL ORDER BY block_height DESC LIMIT ?
        ''', (depth,)).fetchall()
        if not rows:
            return

        for height, stored_hash in rows:  # newest first
            if rpc_request('getblockhash', [height]) == stored_hash:
                if height != rows[0][0]:
                    rollback_to(height)   # highest valid block; drop above
                return

        # Whole window mismatched. If it covers every stored block, the fork is
        # below all of them: roll back beneath the oldest. Otherwise widen and
        # re-check, the common ancestor is deeper than we looked.
        if len(rows) < depth:
            rollback_to(rows[-1][0] - 1)
            return
        depth *= 2


def poll_for_blocks(interval=POLL_INTERVAL):
    print(f"Polling for new blocks every {interval} seconds...")
    while True:
        try:
            time.sleep(interval)
            check_reorg()
            scan_blocks()
        except Exception as e:
            print(f"Polling error: {e}")
            time.sleep(5)


if __name__ == "__main__":
    try:
        init_db()
        scan_blocks()       # bulk catch-up, no secondary indexes yet
        build_indexes()     # build once, after the heavy insert phase
        poll_for_blocks()
    except KeyboardInterrupt:
        print("Shutting down...")
    finally:
        conn.commit()
        conn.close()
