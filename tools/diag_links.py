"""Diagnose why round-trip links are (or aren't) being produced.

Mirrors mwebanalysis.compute_round_trip_links' matching funnel and reports where
peg-outs fall out, so you can tell whether "zero confident matches" means:
  - analysis hasn't been run,
  - peg-out amounts genuinely don't match any peg-in (privacy working / tolerance),
  - or matches exist but the thresholds (LINK_REPORT_MAX / BIG_ANON_SET) filter them.

Run: python3 diag_links.py [mwebscan.db]
"""

import os
import sys
import sqlite3
import collections
from bisect import bisect_left, bisect_right

# network.py lives at the repo root (this file is in tools/).
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from network import PARAMS as _NET

# Keep these in sync with mwebanalysis.py
AMOUNT_TOLERANCE_LTC = 0.002
BIG_ANON_SET = 200
LINK_REPORT_MAX = 10
LITOSHI = 100_000_000
TOL = int(round(AMOUNT_TOLERANCE_LTC * LITOSHI))


def to_l(v):
    return int(round((v or 0.0) * LITOSHI))


def main():
    db = sys.argv[1] if len(sys.argv) > 1 else _NET['DB_FILENAME']
    c = sqlite3.connect(db).cursor()

    n_pin = c.execute("SELECT COUNT(*) FROM mweb_pegins").fetchone()[0]
    n_pout = c.execute("SELECT COUNT(*) FROM mweb_pegouts").fetchone()[0]
    print(f"peg-ins: {n_pin:,}   peg-outs: {n_pout:,}")
    try:
        n_links = c.execute("SELECT COUNT(*) FROM mweb_links").fetchone()[0]
        hi_conf = c.execute("SELECT COUNT(*) FROM mweb_links WHERE confidence>=0.5").fetchone()[0]
        print(f"mweb_links rows: {n_links:,}   (confidence>=0.5: {hi_conf:,})")
    except sqlite3.OperationalError:
        print("mweb_links table missing -> analysis pass has NOT been run. Run mwebanalysis.py.")
        return
    if n_pin == 0 or n_pout == 0:
        print("No peg-in/peg-out data yet - sync further before expecting links.")
        return

    rows = sorted((to_l(a), h) for a, h in c.execute("SELECT amount, block_height FROM mweb_pegins"))
    pin_amts = [a for a, _ in rows]
    pin_h = [h for _, h in rows]
    pegouts = c.execute("SELECT amount, block_height FROM mweb_pegouts").fetchall()

    for label, tol in [("EXACT amount", 0), (f"within {AMOUNT_TOLERANCE_LTC} LTC", TOL)]:
        b = collections.Counter()
        for amt, h in pegouts:
            a = to_l(amt)
            lo, hi = bisect_left(pin_amts, a), bisect_right(pin_amts, a + tol)
            window = hi - lo
            if window == 0:
                b["no matching peg-in amount"] += 1
                continue
            if window > BIG_ANON_SET:
                b[f"too common (>{BIG_ANON_SET} peg-ins, skipped)"] += 1
                continue
            n = sum(1 for i in range(lo, hi) if pin_h[i] < h)
            if n == 0:
                b["matched but no EARLIER peg-in"] += 1
            elif n <= LINK_REPORT_MAX:
                b[f"REPORTED (1-{LINK_REPORT_MAX} candidates)"] += 1
            else:
                b[f"skipped ({LINK_REPORT_MAX+1}-{BIG_ANON_SET}: n>LINK_REPORT_MAX)"] += 1
        print(f"\n--- {label} ---")
        for k, v in b.most_common():
            print(f"  {v:>8,}  {k}")


if __name__ == '__main__':
    main()
