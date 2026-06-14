"""Load curated address labels into the database.

Labels drive entity attribution: tagging peg-outs / peg-in funders as known
exchanges, services, mixers, etc. Re-run when labels change; it upserts.
Addresses beginning with 'EXAMPLE' are skipped so the shipped placeholder
doesn't pollute real data.

Accepts labels.json (the shipped format) or a CSV with columns
address,entity,category,confidence,source.

Usage: python3 mweblabels.py [labels.json|labels.csv] [mwebscan.db]
"""

import sqlite3
import json
import csv
import sys

VALID_CATEGORIES = {
    'exchange', 'service', 'pool', 'mixer',
    'gambling', 'merchant', 'sanctioned', 'other',
}


def init_table(cur):
    cur.execute('''
        CREATE TABLE IF NOT EXISTS labels (
            address TEXT PRIMARY KEY,
            entity TEXT,
            category TEXT,
            confidence REAL,
            source TEXT
        )
    ''')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_labels_entity ON labels(entity)')


def read_labels(path):
    """Read label rows from a .json (labels.json format) or .csv file."""
    if path.lower().endswith('.csv'):
        with open(path, newline='') as f:
            return list(csv.DictReader(f))
    with open(path) as f:
        return json.load(f).get('labels', [])


def load(labels_path='labels.json', db_path='mwebscan.db'):
    rows_in = read_labels(labels_path)

    conn = sqlite3.connect(db_path)
    cur = conn.cursor()
    init_table(cur)

    loaded, skipped = 0, 0
    for row in rows_in:
        address = (row.get('address') or '').strip()
        if not address or address.startswith('EXAMPLE'):
            skipped += 1
            continue
        category = (row.get('category') or 'other').lower()
        if category not in VALID_CATEGORIES:
            print(f"  warning: unknown category '{category}' for {address}, using 'other'")
            category = 'other'
        # Clamp confidence to a finite 0.0-1.0; reject inf/nan and out-of-range
        # values that would skew cluster propagation or the >= 0.5 linkable
        # filter.
        conf = float(row.get('confidence') or 1.0)
        if conf != conf or conf in (float('inf'), float('-inf')):
            conf = 1.0
        conf = max(0.0, min(1.0, conf))
        cur.execute('''
            INSERT INTO labels (address, entity, category, confidence, source)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(address) DO UPDATE SET
                entity=excluded.entity,
                category=excluded.category,
                confidence=excluded.confidence,
                source=excluded.source
        ''', (
            address,
            row.get('entity'),
            category,
            conf,
            row.get('source'),
        ))
        loaded += 1

    conn.commit()
    conn.close()
    print(f"Loaded {loaded} label(s), skipped {skipped} placeholder/empty.")


if __name__ == '__main__':
    labels_path = sys.argv[1] if len(sys.argv) > 1 else 'labels.json'
    db_path = sys.argv[2] if len(sys.argv) > 2 else 'mwebscan.db'
    load(labels_path, db_path)
