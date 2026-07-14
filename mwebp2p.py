#!/usr/bin/env python3
"""Node-less MWEB scanner daemon.

Discovers peers from the Litecoin DNS seeds and fetches blocks from the P2P
network, merkle-verifying each. Headers are PoW-checked, header batches must be
chain-linked, each block must hash to its header, and reorg rollbacks are
bounded to REORG_DEPTH. Electrum is used only for peg-in source-address lookups
and a cold-start anchor. Writes the same mwebscan.db as mwebscan.py, reusing its
parse/persist logic. Resumable from the DB's last-scanned cursor; rotates peers
and resumes on a mid-sync drop. Can also cold-start activation->tip with no node.

Run from the repo root (same CWD as mwebscan.py):
    python3 mwebp2p.py            # catch up, then poll forever
    python3 mwebp2p.py once       # catch up once and exit (for cron)

Env knobs:
    MWEBSCAN_P2P_POLL     seconds between polls once caught up (default 60)
    MWEBSCAN_P2P_COMMIT   blocks between DB commits during sync (default 200)
    MWEBSCAN_P2P_SOURCES  '1' to resolve peg-in sources via Electrum (default 0)
"""

import os
import sys
import time

import mwebscan
import tools.p2p_fetch as p2p

POLL_INTERVAL = int(os.environ.get('MWEBSCAN_P2P_POLL', '60'))
COMMIT_EVERY = int(os.environ.get('MWEBSCAN_P2P_COMMIT', '200'))
USE_ELECTRUM_SOURCES = os.environ.get('MWEBSCAN_P2P_SOURCES', '0') == '1'
RETRY_SECONDS = 15


def db_block_hash(height):
    """Stored block hash for a height, or None. Every post-activation block has
    a HogEx and therefore a mweb_blocks row, so resume needs no node."""
    row = mwebscan.cursor.execute(
        "SELECT block_hash FROM mweb_blocks WHERE block_height = ?", (height,)).fetchone()
    return row[0] if row and row[0] else None


def db_height_of(block_hash):
    """Height of a stored block hash, or None."""
    row = mwebscan.cursor.execute(
        "SELECT block_height FROM mweb_blocks WHERE block_hash = ?", (block_hash,)).fetchone()
    return row[0] if row else None


def build_locator(electrum, height):
    """Newest-first block-locator of known hashes (locator[0] is our tip),
    exponentially spaced going back so a peer can find the common ancestor
    across a deep reorg. Hashes come from our DB; a cold start with no rows
    falls back to Electrum."""
    hashes, step, h, count = [], 1, height, 0
    while h > mwebscan.MWEB_ACTIVATION_HEIGHT:
        bh = db_block_hash(h)
        if bh:
            hashes.append(bh)
        count += 1
        if count >= 10:                              # dense for 10, then exponential
            step *= 2
        h -= step
    if not hashes:                                   # cold start: anchor via Electrum
        hashes = [db_block_hash(height) or electrum.block_hash_at(height)]
    return hashes


def handle_block(block, electrum):
    """Parse + persist one block, optionally enriching peg-in sources via
    Electrum. Like mwebscan.process_block but sources come from Electrum."""
    parsed = mwebscan.parse_block(block)
    sources, pegin_input_rows = {}, []
    if USE_ELECTRUM_SOURCES and parsed['pegins']:
        pegin_txids = {p['txid'] for p in parsed['pegins']}
        for tx in block['tx']:
            if tx['txid'] in pegin_txids and tx['txid'] not in sources:
                value_by_address, complete = p2p.resolve_pegin_sources(electrum, tx)
                if value_by_address and complete:
                    dominant = max(value_by_address, key=value_by_address.get)
                    sources[tx['txid']] = (dominant, len(value_by_address))
                    pegin_input_rows += [(tx['txid'], a, v) for a, v in value_by_address.items()]
                else:
                    sources[tx['txid']] = (None, 0)
    mwebscan.persist_block(parsed, sources, pegin_input_rows)


ANCHOR_CONFIRMATIONS = 6   # cross-check this many blocks below the tip (settled)


def verify_electrum_anchor(electrum):
    """Cross-check our synced chain against an independent Electrum server at a
    settled height. A single P2P peer can serve an internally-valid but forged
    chain (especially now that low-difficulty headers are also rejected); this
    catches that by comparing block hashes with Electrum. On mismatch, roll back
    past the anchor and raise so the caller re-syncs from a different peer."""
    tip = mwebscan.get_last_scanned_block()
    try:
        anchor = min(tip, electrum.tip_height()) - ANCHOR_CONFIRMATIONS
    except Exception:                                # noqa: BLE001 - Electrum down
        return                                       # skip; do not block syncing
    if anchor <= mwebscan.MWEB_ACTIVATION_HEIGHT:
        return
    ours = db_block_hash(anchor)
    theirs = electrum.block_hash_at(anchor)
    if ours and theirs and ours != theirs:
        floor = max(mwebscan.MWEB_ACTIVATION_HEIGHT, anchor - 1)
        mwebscan.rollback_to(floor)
        mwebscan.conn.commit()
        raise RuntimeError(
            f"Electrum anchor mismatch at {anchor} (ours {ours[:16]} != "
            f"electrum {theirs[:16]}); rolled back to {floor}, will re-sync")


def catch_up(electrum):
    """Stream blocks from one peer, DB tip -> chain tip. Returns count synced.
    Handles reorgs via the block-locator; raises on peer drop or merkle failure
    so the caller can rotate peers."""
    have_height = mwebscan.get_last_scanned_block()
    locator = build_locator(electrum, have_height)
    sock, ip, services = p2p.connect_any()
    print(f"[peer] {ip} services=0x{services:x} full={bool(services & p2p.NODE_NETWORK)}")
    n = 0
    try:
        for block in p2p.iter_new_blocks(sock, locator, have_height):
            if not block['_merkle_ok']:
                raise RuntimeError(f"merkle check FAILED at height {block['height']} from {ip}")
            handle_block(block, electrum)
            mwebscan.set_last_scanned_block(block['height'])
            n += 1
            if n % COMMIT_EVERY == 0:
                mwebscan.conn.commit()
                print(f"[sync] at height {block['height']} (+{n})")
        mwebscan.conn.commit()
    except p2p.ReorgDetected as r:
        # Peer built on an older locator block: our tip was reorged out. Roll
        # back to the common ancestor and re-sync the new branch. Rollback is
        # bounded to REORG_DEPTH below our tip so a peer can't name an ancient
        # fork-hash to force a deep wipe; a deeper reorg unwinds over several
        # bounded passes.
        floor = max(mwebscan.MWEB_ACTIVATION_HEIGHT, have_height - mwebscan.REORG_DEPTH)
        fork_height = db_height_of(r.fork_hash)
        if fork_height is None or fork_height < floor:
            fork_height = floor
        mwebscan.rollback_to(fork_height)
        mwebscan.conn.commit()
        print(f"[reorg] rolled back to {fork_height}; will re-sync new branch")
        return -1                                    # re-sync immediately
    finally:
        sock.close()
    verify_electrum_anchor(electrum)
    if n:
        print(f"[sync] +{n} blocks -> height {mwebscan.get_last_scanned_block()}")
    return n


def run(loop=True):
    mwebscan.init_db()
    mwebscan.conn.commit()
    electrum = p2p.ElectrumClient()
    print(f"[electrum] connected to {electrum.connect()}")
    print(f"[start] DB tip height {mwebscan.get_last_scanned_block()}; "
          f"chain tip {electrum.tip_height()}")
    while True:
        try:
            synced = catch_up(electrum)
            if synced < 0:
                continue                 # reorg handled; re-sync the new branch
            if not loop:
                return
            if synced == 0:
                time.sleep(POLL_INTERVAL)
        except KeyboardInterrupt:
            print("\n[stop] interrupted; cursor is committed, safe to resume.")
            return
        except Exception as e:                       # noqa: BLE001 - keep daemon alive
            print(f"[retry] {type(e).__name__}: {e}; reconnecting in {RETRY_SECONDS}s")
            # Roll back any half-finished write first. If a commit failed on a
            # transient lock (e.g. the analysis pass holding the writer), the
            # shared connection is left mid-transaction; leaving it open pins
            # the WAL (it grows without bound) and wedges the scanner for good.
            # Rollback frees the lock and lets the WAL checkpoint; the dropped
            # blocks are re-streamed next pass, since writes are idempotent and
            # the cursor only advanced over committed blocks.
            try:
                mwebscan.conn.rollback()
            except Exception:                        # noqa: BLE001
                pass
            try:
                electrum.close()
                electrum.connect()
            except Exception:                        # noqa: BLE001
                pass
            time.sleep(RETRY_SECONDS)


if __name__ == '__main__':
    run(loop=(len(sys.argv) < 2 or sys.argv[1] != 'once'))
