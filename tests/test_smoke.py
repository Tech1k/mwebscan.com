"""Smoke test / node-readiness check for MWEBscan.

Builds a small synthetic database, runs the real analysis + monitor scripts
against it, asserts the derived tables look right, and -- if `php` is on PATH --
lints every PHP file and exercises the trace engine + API through PHP itself.

Run from anywhere:  python3 test_smoke.py
Exit code 0 = all good, 1 = something failed. No node or real data required.
"""

import os
import sys
import shutil
import sqlite3
import subprocess
import tempfile
import json

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # repo root (tests/ -> ..)
PHP = shutil.which('php')

passed, failed = [], []


def check(name, ok, detail=''):
    (passed if ok else failed).append(name)
    mark = 'ok  ' if ok else 'FAIL'
    print(f"  [{mark}] {name}" + (f" -- {detail}" if detail and not ok else ''))


def build_db(path):
    c = sqlite3.connect(path)
    c.executescript('''
        CREATE TABLE mweb_pegins (txid TEXT, vout INTEGER, block_height INTEGER, block_time INTEGER,
            amount REAL, source_address TEXT, input_count INTEGER, PRIMARY KEY(txid,vout));
        CREATE TABLE mweb_pegouts (txid TEXT, vout INTEGER, block_height INTEGER, block_time INTEGER,
            amount REAL, address TEXT, PRIMARY KEY(txid,vout));
        CREATE TABLE pegin_inputs (pegin_txid TEXT, address TEXT, value REAL, PRIMARY KEY(pegin_txid,address));
        CREATE TABLE mweb_blocks (block_height INTEGER PRIMARY KEY, block_time INTEGER, block_hash TEXT,
            hogex_txid TEXT, supply REAL, pegin_count INTEGER, pegin_amount REAL, pegout_count INTEGER, pegout_amount REAL);
        CREATE TABLE scan_progress (id INTEGER PRIMARY KEY, last_scanned_block INTEGER);
        CREATE TABLE labels (address TEXT PRIMARY KEY, entity TEXT, category TEXT, confidence REAL, source TEXT);
    ''')
    # A rare unique round trip funded by a mixer, exiting to a labelled exchange.
    c.executemany('INSERT INTO mweb_pegins VALUES (?,?,?,?,?,?,?)', [
        ('PIN_RARE', 0, 1000, 1000, 13.37245678, 'mixerAddr', 1),
        ('PIN_COMMON', 0, 1001, 1001, 1.0, 'aliceAddr', 2),
    ] + [(f'PIN_C{i}', 0, 1002 + i, 1002 + i, 1.0, None, 1) for i in range(15)])
    c.executemany('INSERT INTO mweb_pegouts VALUES (?,?,?,?,?,?)', [
        ('POUT_RARE', 1, 1100, 1100, 13.37245678, 'binanceWD'),  # exact match -> exchange
        ('POUT_REUSE', 1, 1101, 1101, 9.9, 'aliceAddr'),         # reuse of a peg-in funder
        ('POUT_COMMON', 1, 1102, 1102, 1.0, 'unknownAddr'),
    ])
    c.executemany('INSERT INTO pegin_inputs VALUES (?,?,?)', [
        ('PIN_COMMON', 'aliceAddr', 0.6), ('PIN_COMMON', 'aliceAddr2', 0.4),  # sanctioned cluster
        ('PIN_C0', 'carolAddr', 0.5), ('PIN_C0', 'carolAddr2', 0.5),          # exchange cluster
    ])
    c.executemany('INSERT INTO labels VALUES (?,?,?,?,?)', [
        ('mixerAddr', 'ExampleMixer', 'mixer', 1.0, 'test'),
        ('binanceWD', 'Binance', 'exchange', 1.0, 'test'),
        # aliceAddr is sanctioned: must NOT propagate to its cluster (aliceAddr2).
        ('aliceAddr', 'OFAC Sanctioned Person', 'sanctioned', 1.0, 'test'),
        # carolAddr is an exchange: SHOULD propagate to its cluster (carolAddr2).
        ('carolAddr', 'CarolExchange', 'exchange', 0.9, 'test'),
    ])
    # A few days of per-block snapshots for the time-series / charts.
    rows = []
    for i in range(6):
        h = 1000 + i
        rows.append((h, 1_700_000_000 + i * 86400, f'hash{h}', f'hog{h}',
                     100.0 + i, 1, 1.0, 0, 0.0))
    c.executemany('INSERT INTO mweb_blocks VALUES (?,?,?,?,?,?,?,?,?)', rows)
    c.execute('INSERT INTO scan_progress VALUES (1, 1102)')
    c.commit()
    c.close()


def run(script, *args, cwd=None):
    return subprocess.run([sys.executable, os.path.join(REPO, script), *args],
                          cwd=cwd, capture_output=True, text=True)


def main():
    tmp = tempfile.mkdtemp(prefix='mwebscan_smoke_')
    db = os.path.join(tmp, 'mwebscan.db')
    try:
        print("Building synthetic database...")
        build_db(db)

        print("\nPython: analysis pass")
        r = run('mwebanalysis.py', cwd=tmp)
        check('mwebanalysis.py runs', r.returncode == 0, r.stderr.strip())

        conn = sqlite3.connect(db)

        def count(sql):
            return conn.execute(sql).fetchone()[0]

        check('round-trip links produced', count('SELECT COUNT(*) FROM mweb_links') > 0)
        check('rare exact match is high confidence',
              count("SELECT COUNT(*) FROM mweb_links WHERE pegout_txid='POUT_RARE' AND confidence>=0.9") == 1)
        check('wallet cluster built (aliceAddr+aliceAddr2)',
              count("SELECT COUNT(DISTINCT cluster_id) FROM address_attribution WHERE cluster_id IS NOT NULL") >= 1)
        check('institutional label propagates across cluster (carolAddr2 = CarolExchange via cluster)',
              count("SELECT COUNT(*) FROM address_attribution WHERE address='carolAddr2' AND entity='CarolExchange' AND via='cluster'") == 1)
        check('sanctioned label does NOT propagate across cluster (aliceAddr2 stays unlabelled)',
              count("SELECT COUNT(*) FROM address_attribution WHERE address='aliceAddr2' AND entity IS NOT NULL") == 0)
        check('sanctioned label still applies directly (aliceAddr)',
              count("SELECT COUNT(*) FROM address_attribution WHERE address='aliceAddr' AND entity='OFAC Sanctioned Person' AND via='direct'") == 1)
        check('entity attribution present (Binance)',
              count("SELECT COUNT(*) FROM address_attribution WHERE entity='Binance'") == 1)
        check('address-reuse link found (aliceAddr)',
              count("SELECT COUNT(*) FROM address_reuse_links WHERE address='aliceAddr'") >= 1)
        check('entity flows aggregated',
              count("SELECT COUNT(*) FROM entity_flows") > 0)
        check('peg-in privacy scores written',
              count('SELECT COUNT(*) FROM pegin_scores') == 17)
        check('rare peg-in scores low privacy',
              count("SELECT privacy_score FROM pegin_scores WHERE txid='PIN_RARE'") < 20)
        check('common peg-in scores higher than rare',
              count("SELECT privacy_score FROM pegin_scores WHERE txid='PIN_COMMON'") >
              count("SELECT privacy_score FROM pegin_scores WHERE txid='PIN_RARE'"))
        check('peg-out risk scores written',
              count('SELECT COUNT(*) FROM pegout_scores') == 3)
        check('exchange-exit-from-mixer scores high risk',
              count("SELECT risk_score FROM pegout_scores WHERE txid='POUT_RARE'") >= 70)
        check('analysis_stats populated',
              count('SELECT COUNT(*) FROM analysis_stats') > 0)
        check('homepage aggregates cached',
              count("SELECT COUNT(*) FROM cache WHERE key IN ('pegin_dist_rounded','pegin_dist_exact','timeseries_daily')") == 3)
        check('recommendations cached',
              count("SELECT COUNT(*) FROM cache WHERE key='recommendations'") == 1)
        rec = json.loads(conn.execute("SELECT json FROM cache WHERE key='recommendations'").fetchone()[0])
        check('recommendations suggest best amounts', len(rec.get('best_pegin_amounts', [])) > 0)
        check('recommendations include a wait time', rec.get('recommended_wait_blocks', 0) > 0)
        conn.close()

        print("\nPython: watchlist monitor")
        run('mwebmonitor.py', 'add', 'binanceWD', '', 'Binance dep', cwd=tmp)
        r = run('mwebmonitor.py', 'check', cwd=tmp)
        check('mwebmonitor check runs', r.returncode == 0, r.stderr.strip())
        conn = sqlite3.connect(db)
        check('watch hit recorded',
              conn.execute("SELECT COUNT(*) FROM watch_hits WHERE address='binanceWD'").fetchone()[0] >= 1)
        conn.close()

        print("\nPHP checks" + ("" if PHP else " -- SKIPPED (php not on PATH)"))
        if PHP:
            for f in ['index.php', 'trace.php', 'api.php', 'charts.php', 'methodology.php',
                      'terms.php', 'privacy.php', 'disclaimer.php', 'about.php', 'api-docs.php',
                      'lib/trace_engine.php', 'lib/db.php', 'lib/nav.php']:
                r = subprocess.run([PHP, '-l', os.path.join(REPO, f)], capture_output=True, text=True)
                check(f'php -l {f}', r.returncode == 0, r.stdout.strip() + r.stderr.strip())

            # Exercise the trace engine + API through PHP against the synthetic DB.
            harness = (
                'require "%s/lib/trace_engine.php";'
                '$db=new PDO("sqlite:%s");'
                '$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);'
                'echo json_encode(["trace_txid"=>mwebscan_trace($db,"POUT_RARE")["type"],'
                '"trace_amount"=>mwebscan_trace($db,"1.0")["type"],'
                '"follow"=>count(mwebscan_follow($db,"aliceAddr",3)),'
                '"privacy"=>mwebscan_amount_privacy($db,1.0)["privacy_score"]]);'
            ) % (REPO, db)
            r = subprocess.run([PHP, '-r', harness], capture_output=True, text=True)
            ok = r.returncode == 0 and r.stdout.strip().startswith('{')
            check('php trace/privacy engine executes', ok, r.stdout + r.stderr)
            if ok:
                data = json.loads(r.stdout)
                check('php trace classifies txid', data.get('trace_txid') == 'pegout_tx')
                check('php trace classifies amount', data.get('trace_amount') == 'amount')
                check('php multi-hop follow executes', isinstance(data.get('follow'), int) and data.get('follow') >= 1)

            # Exercise an API endpoint end-to-end via CLI.
            api = (
                '$_GET=["endpoint"=>"stats"];chdir("%s");'
                'ob_start();include "%s/api.php";' % (tmp, REPO)
            )
            r = subprocess.run([PHP, '-r', api], capture_output=True, text=True)
            check('php api stats endpoint executes',
                  r.returncode == 0 and '"version"' in r.stdout, r.stdout[:200] + r.stderr[:200])
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    print(f"\n{'='*48}\n{len(passed)} passed, {len(failed)} failed")
    if failed:
        print("FAILED: " + ", ".join(failed))
        sys.exit(1)
    print("All smoke tests passed.")


if __name__ == '__main__':
    main()
