"""Unit tests for the label-importer parsing logic (no node, no network).

Covers tools/label_ofac.py, tools/label_pools.py, tools/label_graphsense.py.
(label_graphsense needs PyYAML; those checks skip if it isn't installed.)
"""

import os
import sys
import binascii
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(REPO, 'tools'))

import label_ofac          # noqa: E402
import label_pools         # noqa: E402

passed, failed = [], []


def check(name, ok):
    (passed if ok else failed).append(name)
    print(f"  [{'ok  ' if ok else 'FAIL'}] {name}")


def hx(b):
    return binascii.hexlify(b).decode()


def main():
    # --- OFAC ---
    xml = (b'<sdnList xmlns="x"><sdnEntry><lastName>EVIL LLC</lastName><idList>'
           b'<id><idType>Digital Currency Address - LTC</idType><idNumber>Lofac1</idNumber></id>'
           b'<id><idType>Digital Currency Address - XBT</idType><idNumber>1btc</idNumber></id>'
           b'</idList></sdnEntry></sdnList>')
    rows = label_ofac.extract(xml, 'LTC')
    check('ofac: extracts LTC only, tags sanctioned',
          len(rows) == 1 and rows[0]['address'] == 'Lofac1' and rows[0]['category'] == 'sanctioned'
          and rows[0]['entity'] == 'EVIL LLC')

    # --- Pools ---
    tags, known = label_pools.load_pools(os.path.join(REPO, 'tools', 'pools.json'))
    check('pools: tags + known addresses load', len(tags) > 10 and len(known) > 5)
    check('pools: ASCII tag match', label_pools.identify(label_pools.coinbase_text(hx(b'xx/F2Pool/yy')), tags) == 'F2Pool')
    check('pools: CJK tag match', label_pools.identify(label_pools.coinbase_text(hx('七彩神仙鱼'.encode())), tags) == 'F2Pool')
    check('pools: no false match', label_pools.identify(label_pools.coinbase_text(hx(b'solo miner')), tags) is None)
    check('pools: address extraction', label_pools.vout_address({'scriptPubKey': {'address': 'Lx'}}) == 'Lx')

    # --- GraphSense (needs PyYAML) ---
    try:
        import label_graphsense  # noqa: E402
        check('gs: category mapping',
              label_graphsense.map_category('exchange') == 'exchange'
              and label_graphsense.map_category('mining') == 'pool'
              and label_graphsense.map_category('mixing_service') == 'mixer'
              and label_graphsense.map_category('unknown_x') == 'other')
        check('gs: confidence normalize',
              label_graphsense.normalize_conf('high') == 0.9
              and label_graphsense.normalize_conf(90) == 0.9
              and label_graphsense.normalize_conf(0.5) == 0.5
              and label_graphsense.normalize_conf(None) == 0.8)
        tp = tempfile.NamedTemporaryFile('w', suffix='.yaml', delete=False)
        tp.write("currency: LTC\ncategory: exchange\nsource: s\ntags:\n"
                 "  - address: Lgs1\n    label: Foo\n"
                 "  - address: '1btc'\n    label: Bar\n    currency: BTC\n")
        tp.close()
        rows, seen = [], set()
        label_graphsense.extract(tp.name, 'LTC', rows, seen)
        os.unlink(tp.name)
        check('gs: extracts LTC only with inherited defaults',
              len(rows) == 1 and rows[0]['address'] == 'Lgs1' and rows[0]['category'] == 'exchange')
    except SystemExit:
        print("  [skip] graphsense (PyYAML not installed)")
    except ImportError:
        print("  [skip] graphsense (PyYAML not installed)")

    print(f"\n{len(passed)} passed, {len(failed)} failed")
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
