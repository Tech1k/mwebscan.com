"""Fetch OFAC SDN sanctioned digital-currency addresses into labels.csv.

The US Treasury publishes the Specially Designated Nationals (SDN) list with
crypto addresses tagged by currency (e.g. "Digital Currency Address - LTC").
A public, structured source for the `sanctioned` category. Run it, then load
the result:

    python3 label_ofac.py                 # writes labels_ofac.csv (LTC addresses)
    python3 mweblabels.py labels_ofac.csv # load into the DB

Usage: python3 label_ofac.py [CURRENCY] [out.csv]   (default: LTC labels_ofac.csv)
"""

import sys
import csv
import requests
import xml.etree.ElementTree as ET

# Legacy SDN XML (follows redirects to the current host). Swap if OFAC moves it.
SDN_URL = "https://www.treasury.gov/ofac/downloads/sdn.xml"


def localname(tag):
    """Strip XML namespace so we can match on plain element names."""
    return tag.rsplit('}', 1)[-1]


def extract(xml_bytes, currency):
    root = ET.fromstring(xml_bytes)
    seen = set()
    rows = []
    for entry in root.iter():
        if localname(entry.tag) != 'sdnEntry':
            continue
        first = last = ''
        ids = []
        for child in entry:
            ln = localname(child.tag)
            if ln == 'firstName':
                first = (child.text or '').strip()
            elif ln == 'lastName':
                last = (child.text or '').strip()
            elif ln == 'idList':
                for idn in child:
                    idtype = addr = ''
                    for f in idn:
                        fl = localname(f.tag)
                        if fl == 'idType':
                            idtype = (f.text or '').strip()
                        elif fl == 'idNumber':
                            addr = (f.text or '').strip()
                    if idtype.startswith('Digital Currency Address') and addr:
                        ids.append((idtype, addr))

        name = (first + ' ' + last).strip() or last or 'OFAC SDN entity'
        for idtype, addr in ids:
            cur = idtype.rsplit('-', 1)[-1].strip().upper()
            if cur == currency.upper() and addr not in seen:
                seen.add(addr)
                rows.append({
                    'address': addr, 'entity': name, 'category': 'sanctioned',
                    'confidence': '1.0', 'source': 'OFAC SDN',
                })
    return rows


def main():
    currency = sys.argv[1] if len(sys.argv) > 1 else 'LTC'
    out = sys.argv[2] if len(sys.argv) > 2 else 'labels_ofac.csv'

    print(f"Downloading OFAC SDN list from {SDN_URL} ...")
    xml_bytes = requests.get(SDN_URL, timeout=180).content
    rows = extract(xml_bytes, currency)

    with open(out, 'w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=['address', 'entity', 'category', 'confidence', 'source'])
        w.writeheader()
        w.writerows(rows)

    print(f"Wrote {len(rows)} sanctioned {currency} address(es) to {out}.")
    if not rows:
        print("(None found - most OFAC crypto designations are BTC/ETH/XMR; LTC is rare but worth tracking.)")


if __name__ == '__main__':
    main()
