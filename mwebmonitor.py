"""Watchlist monitor.

Watch public Litecoin addresses; POST a webhook when one funds a peg-in or
receives a peg-out. Run `check` after each analysis pass (cron / systemd
timer): it records new hits and POSTs un-notified ones to each watch's
webhook_url.

Usage:
    python3 mwebmonitor.py add <address> [webhook_url] [label]
    python3 mwebmonitor.py list
    python3 mwebmonitor.py remove <address>
    python3 mwebmonitor.py check          # default; find + fire notifications
"""

import sqlite3
import sys
import time
import json

try:
    import requests
except ImportError:
    requests = None

DB_PATH = 'mwebscan.db'
WEBHOOK_TIMEOUT = 10


def connect():
    conn = sqlite3.connect(DB_PATH)
    conn.execute('PRAGMA journal_mode = WAL;')
    conn.execute('PRAGMA busy_timeout = 5000;')
    init(conn)
    return conn


def init(conn):
    conn.execute('''
        CREATE TABLE IF NOT EXISTS watchlist (
            address TEXT PRIMARY KEY,
            label TEXT,
            webhook_url TEXT,
            created_at INTEGER
        )
    ''')
    conn.execute('''
        CREATE TABLE IF NOT EXISTS watch_hits (
            address TEXT,
            kind TEXT,           -- 'pegout_received' or 'pegin_funded'
            txid TEXT,
            vout INTEGER,
            block_height INTEGER,
            amount REAL,
            notified INTEGER DEFAULT 0,
            ts INTEGER,
            PRIMARY KEY (address, kind, txid, vout)
        )
    ''')
    conn.commit()


def add(conn, address, webhook_url=None, label=None):
    conn.execute('''
        INSERT INTO watchlist (address, label, webhook_url, created_at)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(address) DO UPDATE SET
            label=excluded.label, webhook_url=excluded.webhook_url
    ''', (address, label, webhook_url, int(time.time())))
    conn.commit()
    print(f"Watching {address}" + (f" -> {webhook_url}" if webhook_url else " (no webhook)"))


def remove(conn, address):
    cur = conn.execute('DELETE FROM watchlist WHERE address = ?', (address,))
    conn.commit()
    print(f"Removed {address}" if cur.rowcount else f"{address} was not watched")


def list_watches(conn):
    rows = conn.execute('SELECT address, label, webhook_url FROM watchlist ORDER BY created_at').fetchall()
    if not rows:
        print("Watchlist is empty.")
        return
    for address, label, webhook in rows:
        print(f"  {address}  label={label or '-'}  webhook={webhook or '-'}")


def find_hits(conn):
    """Record any peg activity touching watched addresses. Returns new-hit count."""
    cur = conn.cursor()
    watches = cur.execute('SELECT address FROM watchlist').fetchall()
    new_hits = 0
    now = int(time.time())

    for (address,) in watches:
        # Peg-outs received by the watched address.
        for txid, vout, height, amount in cur.execute(
            'SELECT txid, vout, block_height, amount FROM mweb_pegouts WHERE address = ?',
            (address,)
        ).fetchall():
            cur.execute('''
                INSERT OR IGNORE INTO watch_hits
                    (address, kind, txid, vout, block_height, amount, notified, ts)
                VALUES (?, 'pegout_received', ?, ?, ?, ?, 0, ?)
            ''', (address, txid, vout, height, amount, now))
            new_hits += cur.rowcount

        # Peg-ins funded by the watched address: as the dominant source or as
        # any one of the tx inputs (common-input ownership).
        for txid, vout, height, amount in cur.execute(
            '''SELECT DISTINCT p.txid, p.vout, p.block_height, p.amount
               FROM mweb_pegins p
               WHERE p.source_address = ?
                  OR p.txid IN (SELECT pegin_txid FROM pegin_inputs WHERE address = ?)''',
            (address, address)
        ).fetchall():
            # Use the real vout so multiple peg-in outputs in one tx get
            # distinct (address, kind, txid, vout) PKs instead of colliding
            # under INSERT OR IGNORE.
            cur.execute('''
                INSERT OR IGNORE INTO watch_hits
                    (address, kind, txid, vout, block_height, amount, notified, ts)
                VALUES (?, 'pegin_funded', ?, ?, ?, ?, 0, ?)
            ''', (address, txid, vout, height, amount, now))
            new_hits += cur.rowcount

    conn.commit()
    return new_hits


def notify(conn):
    """POST un-notified hits to each watch's webhook. Returns sent count."""
    cur = conn.cursor()
    pending = cur.execute('''
        SELECT h.address, h.kind, h.txid, h.vout, h.block_height, h.amount,
               w.webhook_url, w.label
        FROM watch_hits h
        JOIN watchlist w ON w.address = h.address
        WHERE h.notified = 0 AND w.webhook_url IS NOT NULL AND w.webhook_url != ''
    ''').fetchall()

    sent = 0
    for address, kind, txid, vout, height, amount, webhook, label in pending:
        payload = {
            'address': address, 'label': label, 'kind': kind,
            'txid': txid, 'vout': vout, 'block_height': height, 'amount': amount,
        }
        if requests is None:
            print("  requests not installed; cannot POST webhooks")
            break
        try:
            r = requests.post(webhook, json=payload, timeout=WEBHOOK_TIMEOUT)
            r.raise_for_status()    # 4xx/5xx: stay notified=0, retry next run
            cur.execute('''
                UPDATE watch_hits SET notified = 1
                WHERE address = ? AND kind = ? AND txid = ? AND vout = ?
            ''', (address, kind, txid, vout))
            conn.commit()           # persist per POST so a crash can't re-fire it
            sent += 1
        except Exception as e:
            print(f"  webhook failed for {address} ({txid}): {e}")

    # Mark webhook-less hits notified so they don't linger as pending.
    cur.execute('''
        UPDATE watch_hits SET notified = 1
        WHERE notified = 0 AND address IN (
            SELECT address FROM watchlist WHERE webhook_url IS NULL OR webhook_url = ''
        )
    ''')
    conn.commit()
    return sent


def check(conn):
    new_hits = find_hits(conn)
    sent = notify(conn)
    print(f"Found {new_hits} new hit(s); sent {sent} webhook notification(s).")


def main():
    conn = connect()
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'check'

    if cmd == 'add' and len(sys.argv) >= 3:
        add(conn, sys.argv[2],
            sys.argv[3] if len(sys.argv) > 3 else None,
            sys.argv[4] if len(sys.argv) > 4 else None)
    elif cmd == 'remove' and len(sys.argv) >= 3:
        remove(conn, sys.argv[2])
    elif cmd == 'list':
        list_watches(conn)
    elif cmd == 'check':
        check(conn)
    else:
        print(__doc__)

    conn.close()


if __name__ == '__main__':
    main()
