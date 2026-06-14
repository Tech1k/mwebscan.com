"""Derive Litecoin mining-pool address labels from a pools.json + coinbase scan.

Two sources, combined:
  1. pools.json `payout_addresses` -> labelled instantly (no node needed).
  2. pools.json `coinbase_tags` -> scan recent coinbase txs, match the pool tag,
     and label the current payout addresses (pools rotate addresses, so this
     catches ones not in the static list).

Uses LTC_RPC_* env vars (same as mwebscan.py).

Usage:
  python3 label_pools.py [blocks_back] [out.csv] [pools.json]
    blocks_back default 5000; set 0 to skip the scan (static addresses only).
  python3 mweblabels.py labels_pools.csv          # then load it
"""

import os
import sys
import csv
import json
import binascii
import requests

RPC_URL = os.environ.get('LTC_RPC_URL', 'http://127.0.0.1:9332/')
RPC_USER = os.environ.get('LTC_RPC_USER', 'litecoinrpc')
RPC_PASSWORD = os.environ.get('LTC_RPC_PASSWORD', 'litecoinrpcpass')


def rpc(method, params=None):
    r = requests.post(
        RPC_URL,
        data=json.dumps({'jsonrpc': '1.0', 'id': 'pools', 'method': method, 'params': params or []}),
        headers={'Content-Type': 'text/plain'},
        auth=(RPC_USER, RPC_PASSWORD), timeout=60)
    r.raise_for_status()
    return r.json()['result']


def load_pools(path):
    with open(path) as f:
        data = json.load(f)
    # Lower-cased tag -> pool name. Skip entries missing a name.
    tags = {tag.lower(): v['name'] for tag, v in data.get('coinbase_tags', {}).items()
            if isinstance(v, dict) and v.get('name')}
    addrs = {addr: v['name'] for addr, v in data.get('payout_addresses', {}).items()
             if isinstance(v, dict) and v.get('name')}
    return tags, addrs


def coinbase_text(coinbase_hex):
    try:
        raw = binascii.unhexlify(coinbase_hex)
    except Exception:
        return ''
    ascii_str = ''.join(chr(b) if 32 <= b < 127 else ' ' for b in raw)
    return (ascii_str + ' ' + raw.decode('utf-8', 'ignore')).lower()


def identify(text, tags):
    for tag, name in tags.items():
        if tag in text:
            return name
    return None


def vout_address(vout):
    spk = vout.get('scriptPubKey', {})
    return spk.get('address') or (spk.get('addresses') or [None])[0]


def main():
    blocks_back = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
    out = sys.argv[2] if len(sys.argv) > 2 else 'labels_pools.csv'
    default_pools = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pools.json')
    pools_path = sys.argv[3] if len(sys.argv) > 3 else default_pools

    tags, known = load_pools(pools_path)
    print(f"Loaded {len(tags)} coinbase tag(s) and {len(known)} known payout address(es).")

    # address -> (pool, confidence, source)
    found = {a: (n, '0.95', 'pools.json (known address)') for a, n in known.items()}

    if blocks_back > 0:
        tip = rpc('getblockcount')
        start = max(1, tip - blocks_back + 1)
        print(f"Scanning coinbase of blocks {start}..{tip} for pool tags...")
        for h in range(start, tip + 1):
            try:
                block = rpc('getblock', [rpc('getblockhash', [h]), 2])
                txs = block.get('tx') or []
                if not txs:
                    continue
                cb = txs[0]
                pool = identify(coinbase_text((cb.get('vin') or [{}])[0].get('coinbase', '')), tags)
                if not pool:
                    continue
                for vout in cb.get('vout', []):
                    addr = vout_address(vout)
                    if addr and addr not in known:   # don't downgrade a known-address label
                        found[addr] = (pool, '0.85', 'pools.json (coinbase-tag)')
            except Exception as e:
                print(f"  block {h} skipped: {e}")
            if h % 1000 == 0:
                print(f"  ...{h} ({len(found)} addresses)")

    with open(out, 'w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=['address', 'entity', 'category', 'confidence', 'source'])
        w.writeheader()
        for addr, (pool, conf, source) in sorted(found.items()):
            w.writerow({'address': addr, 'entity': pool, 'category': 'pool',
                        'confidence': conf, 'source': source})

    print(f"Wrote {len(found)} pool address(es) to {out}.")


if __name__ == '__main__':
    main()
