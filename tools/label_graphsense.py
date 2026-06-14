"""Import Litecoin address labels from GraphSense TagPacks into a CSV.

GraphSense TagPacks (github.com/graphsense/graphsense-tagpacks) are open YAML
files of attribution data. This reads a tagpack file or a directory of them,
keeps the LTC tags, maps their category to our taxonomy, and writes a CSV for
mweblabels.py.

Needs PyYAML (`pip install pyyaml`).

Usage:
  python3 tools/label_graphsense.py <tagpack.yaml | dir> [out.csv] [CURRENCY]
  python3 mweblabels.py labels_graphsense.csv
"""

import sys
import os
import csv
import glob

try:
    import yaml
except ImportError:
    print("This importer needs PyYAML:  pip install pyyaml")
    sys.exit(1)

# GraphSense concept -> our category taxonomy.
CATEGORY_MAP = {
    'exchange': 'exchange', 'exchanges': 'exchange',
    'mining': 'pool', 'miner': 'pool', 'mining_pool': 'pool', 'pool': 'pool',
    'mixing_service': 'mixer', 'mixer': 'mixer', 'mixing': 'mixer', 'coinjoin': 'mixer',
    'gambling': 'gambling',
    'merchant_services': 'merchant', 'merchant': 'merchant',
    'wallet_service': 'service', 'service': 'service',
    'payment_processor': 'service', 'hosted_wallet': 'service',
    'sanctions': 'sanctioned', 'sanctioned': 'sanctioned',
}


def map_category(c):
    return CATEGORY_MAP.get(str(c or '').strip().lower(), 'other')


def normalize_conf(c):
    if c is None:
        return 0.8
    try:
        v = float(c)
        return round(v / 100, 2) if v > 1 else round(v, 2)
    except (TypeError, ValueError):
        return {'high': 0.9, 'medium': 0.7, 'low': 0.5, 'ownership': 0.95}.get(str(c).strip().lower(), 0.8)


def iter_tagpacks(path):
    if os.path.isdir(path):
        yield from sorted(glob.glob(os.path.join(path, '**', '*.yaml'), recursive=True))
        yield from sorted(glob.glob(os.path.join(path, '**', '*.yml'), recursive=True))
    else:
        yield path


def extract(path, currency, rows, seen):
    with open(path) as fh:
        data = yaml.safe_load(fh) or {}
    if not isinstance(data, dict):
        return
    # Tagpack-level defaults are inherited by tags that don't override them.
    defaults = {k: data.get(k) for k in
                ('currency', 'category', 'source', 'label', 'confidence', 'license', 'creator')}
    for tag in data.get('tags', []) or []:
        if not isinstance(tag, dict):
            continue
        addr = (tag.get('address') or '').strip()
        cur = tag.get('currency') or defaults['currency'] or ''
        if not addr or str(cur).strip().upper() != currency.upper() or addr in seen:
            continue
        seen.add(addr)
        # Keep the tagpack license/creator in the source so attribution travels
        # with each label.
        base = tag.get('source') or defaults['source'] or 'GraphSense TagPack'
        extras = '; '.join(str(x) for x in (defaults.get('license'), defaults.get('creator')) if x)
        rows.append({
            'address': addr,
            'entity': tag.get('label') or defaults['label'] or '',
            'category': map_category(tag.get('category') or defaults['category']),
            'confidence': normalize_conf(tag.get('confidence') or defaults['confidence']),
            'source': base + (' [' + extras + ']' if extras else ''),
        })


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 label_graphsense.py <tagpack.yaml|dir> [out.csv] [CURRENCY]")
        sys.exit(1)
    path = sys.argv[1]
    out = sys.argv[2] if len(sys.argv) > 2 else 'labels_graphsense.csv'
    currency = sys.argv[3] if len(sys.argv) > 3 else 'LTC'

    rows, seen = [], set()
    for f in iter_tagpacks(path):
        try:
            extract(f, currency, rows, seen)
        except Exception as e:
            print(f"  skipped {f}: {e}")

    with open(out, 'w', newline='') as fh:
        w = csv.DictWriter(fh, fieldnames=['address', 'entity', 'category', 'confidence', 'source'])
        w.writeheader()
        w.writerows(rows)
    print(f"Wrote {len(rows)} {currency} label(s) to {out}.")


if __name__ == '__main__':
    main()
