"""Unit tests for the scanner's block-parsing logic (no node required).

Exercises parse_block() and vout_address() against a synthetic block shaped like
Litecoin Core's `getblock <hash> 2` output, locking down HogEx detection,
peg-in/peg-out extraction and the supply snapshot.
"""

import os
import sys
import shutil
import atexit
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # repo root (tests/ -> ..)

# Importing mwebscan opens a SQLite file at module load; do it in a temp cwd so
# we don't litter the repo. Then test the pure parsing functions.
_tmp = tempfile.mkdtemp(prefix='mwebscan_unit_')
os.chdir(_tmp)
atexit.register(lambda: (os.chdir(REPO), shutil.rmtree(_tmp, ignore_errors=True)))
sys.path.insert(0, REPO)
import mwebscan  # noqa: E402

passed, failed = [], []


def check(name, ok):
    (passed if ok else failed).append(name)
    print(f"  [{'ok  ' if ok else 'FAIL'}] {name}")


def make_block():
    """A post-activation block: coinbase, a peg-in tx, and the HogEx (hogaddr +
    one peg-out)."""
    return {
        'height': 2300000,
        'time': 1700000000,
        'hash': 'abc123blockhash',
        'tx': [
            {'txid': 'coinbase_tx', 'vin': [{'coinbase': '03'}],
             'vout': [{'n': 0, 'value': 12.5, 'scriptPubKey': {'type': 'pubkeyhash', 'address': 'Lminer'}}]},
            {'txid': 'pegin_tx', 'vin': [{'txid': 'prev', 'vout': 0}],
             'vout': [
                 {'n': 0, 'value': 5.0, 'scriptPubKey': {'type': 'witness_mweb_pegin'}},
                 {'n': 1, 'value': 1.23, 'scriptPubKey': {'type': 'witness_v0_keyhash', 'address': 'ltc1qchange'}},
             ]},
            {'txid': 'hogex_tx', 'vin': [{'txid': 'prevhog', 'vout': 0}],
             'vout': [
                 {'n': 0, 'value': 987.65, 'scriptPubKey': {'type': 'witness_mweb_hogaddr'}},
                 {'n': 1, 'value': 2.5, 'scriptPubKey': {'type': 'witness_v0_keyhash', 'address': 'ltc1qpegout'}},
                 {'n': 2, 'value': 0.5, 'scriptPubKey': {'type': 'pubkeyhash', 'addresses': ['Llegacypegout']}},
             ]},
        ],
    }


def main():
    # vout_address handles both 'address' and legacy 'addresses'.
    check('vout_address reads "address"',
          mwebscan.vout_address({'scriptPubKey': {'address': 'A'}}) == 'A')
    check('vout_address reads legacy "addresses"',
          mwebscan.vout_address({'scriptPubKey': {'addresses': ['B', 'C']}}) == 'B')
    check('vout_address returns None when absent',
          mwebscan.vout_address({'scriptPubKey': {'type': 'nonstandard'}}) is None)

    p = mwebscan.parse_block(make_block())

    check('block hash captured', p['block_hash'] == 'abc123blockhash')
    check('HogEx txid identified', p['hogex_txid'] == 'hogex_tx')
    check('supply = hogaddr value', p['supply'] == 987.65)

    check('exactly one peg-in', len(p['pegins']) == 1)
    check('peg-in txid/amount correct',
          p['pegins'][0]['txid'] == 'pegin_tx' and p['pegins'][0]['amount'] == 5.0)
    check('peg-in change output not counted', all(x['amount'] != 1.23 for x in p['pegins']))

    check('two peg-outs (hogaddr excluded)', len(p['pegouts']) == 2)
    # pegout tuple: (txid, vout, height, time, amount, address)
    amounts = sorted(po[4] for po in p['pegouts'])
    check('peg-out amounts correct', amounts == [0.5, 2.5])
    addrs = {po[5] for po in p['pegouts']}
    check('peg-out addresses extracted (incl. legacy)', addrs == {'ltc1qpegout', 'Llegacypegout'})
    check('hogaddr output not treated as a peg-out',
          all(po[1] != 0 for po in p['pegouts']))

    # A pre-MWEB / non-MWEB block: no hogaddr, no pegs.
    plain = mwebscan.parse_block({'height': 1, 'time': 1, 'hash': 'h', 'tx': [
        {'txid': 't', 'vout': [{'n': 0, 'value': 1.0, 'scriptPubKey': {'type': 'pubkeyhash', 'address': 'X'}}]}
    ]})
    check('non-MWEB block has no HogEx', plain['hogex_txid'] is None)
    check('non-MWEB block has no supply', plain['supply'] is None)
    check('non-MWEB block has no pegs', not plain['pegins'] and not plain['pegouts'])

    # --- Batch-gap regression: a failed block must NOT advance the cursor past it.
    mwebscan.init_db()
    mwebscan.set_last_scanned_block(1000)
    mwebscan.conn.commit()

    def block_at(h):
        return {'height': h, 'time': 1, 'hash': f'hash{h}', 'tx': []}

    fail_height = [1003]  # mutable so we can "repair" it for the retry case

    def fake_request(method, params=None):
        return 1005 if method == 'getblockcount' else None

    def fake_batch(calls):
        method = calls[0][0]
        if method == 'getblockhash':
            return [f'hash{p[0]}' for _, p in calls]
        if method == 'getblock':  # small concurrent sub-batches via fetch_blocks
            out = []
            for _, p in calls:
                h = int(p[0].replace('hash', ''))
                out.append(None if h in fail_height else block_at(h))
            return out
        return [None] * len(calls)

    mwebscan.rpc_request = fake_request
    mwebscan.rpc_batch = fake_batch

    mwebscan.scan_blocks()
    check('cursor stops at the block before a gap (no skip)',
          mwebscan.get_last_scanned_block() == 1002)

    fail_height.clear()  # block 1003 now available
    mwebscan.scan_blocks()
    check('cursor resumes and finishes after the gap clears',
          mwebscan.get_last_scanned_block() == 1005)

    # --- Reorg regression: a forked recent block must roll back to the fork point.
    cur = mwebscan.cursor
    for t in ('mweb_blocks', 'mweb_pegins', 'mweb_pegouts'):
        cur.execute(f"DELETE FROM {t}")
    for h in (100, 101, 102, 103):
        cur.execute("""INSERT INTO mweb_blocks
            (block_height, block_time, block_hash, hogex_txid, supply,
             pegin_count, pegin_amount, pegout_count, pegout_amount)
            VALUES (?,?,?,?,?,0,0,0,0)""", (h, 1, f'h{h}', f'hog{h}', 100.0))
    cur.execute("INSERT INTO mweb_pegins (txid,vout,block_height,block_time,amount) VALUES ('pin102',0,102,1,1.0)")
    cur.execute("INSERT INTO mweb_pegouts (txid,vout,block_height,block_time,amount,address) VALUES ('pout103',1,103,1,1.0,'x')")
    mwebscan.set_last_scanned_block(103)
    mwebscan.conn.commit()

    def reorg_request(method, params=None):
        if method == 'getblockcount':
            return 103
        if method == 'getblockhash':
            h = params[0]
            return f'h{h}' if h <= 101 else f'NEW{h}'  # chain reorged from 102
        return None

    mwebscan.rpc_request = reorg_request
    mwebscan.check_reorg()
    check('reorg rolls back to the fork point (101)',
          mwebscan.get_last_scanned_block() == 101)
    check('reorg deleted blocks above the fork',
          cur.execute("SELECT COUNT(*) FROM mweb_blocks WHERE block_height > 101").fetchone()[0] == 0)
    check('reorg deleted peg-outs above the fork',
          cur.execute("SELECT COUNT(*) FROM mweb_pegouts WHERE block_height > 101").fetchone()[0] == 0)

    print(f"\n{len(passed)} passed, {len(failed)} failed")
    if failed:
        print("FAILED: " + ", ".join(failed))
        sys.exit(1)
    print("All scanner unit tests passed.")


if __name__ == '__main__':
    main()
