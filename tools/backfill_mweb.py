#!/usr/bin/env python3
"""Backfill mweb_kernels / mweb_txos on existing mweb_blocks rows.

Reads each block's MWEB header summary via getblock verbosity 1 (num_kernels /
num_txos) and fills the columns. One-time and safe to re-run: it only touches
rows still NULL. Honours MWEBSCAN_NETWORK and uses the same RPC config as
mwebscan.py, so run it the same way, e.g.:

    MWEBSCAN_NETWORK=testnet LTC_RPC_USER=... LTC_RPC_PASSWORD=... \\
        python3 tools/backfill_mweb.py

Needs a full node (RPC); it can't run on a node-less (P2P/Electrum) deploy.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import mwebscan as m

# Blocks per DB commit (progress granularity); RPC fan-out is handled inside
# fetch_mweb via GETBLOCK_BATCH + RPC_WORKERS.
COMMIT_EVERY = 500


def main():
    m.init_db()   # ensure the columns exist (auto-migration)
    rows = m.cursor.execute(
        "SELECT block_height, block_hash FROM mweb_blocks "
        "WHERE block_hash IS NOT NULL AND mweb_kernels IS NULL "
        "ORDER BY block_height"
    ).fetchall()
    total = len(rows)
    print(f"{total} block(s) to backfill "
          f"(network={m._NET['name']}, db={m._NET['DB_FILENAME']})")
    if not total:
        return

    filled = 0
    for i in range(0, total, COMMIT_EVERY):
        chunk = rows[i:i + COMMIT_EVERY]
        summaries = m.fetch_mweb([bh for _, bh in chunk])
        for height, bh in chunk:
            mweb = summaries.get(bh)
            if not mweb:
                continue
            m.cursor.execute(
                "UPDATE mweb_blocks SET mweb_kernels = ?, mweb_txos = ? "
                "WHERE block_height = ?",
                (mweb.get('num_kernels'), mweb.get('num_txos'), height))
            filled += 1
        m.conn.commit()
        print(f"  {min(i + COMMIT_EVERY, total)}/{total} scanned, {filled} filled")
    print(f"Done. Filled {filled} of {total} block(s).")


if __name__ == '__main__':
    main()
