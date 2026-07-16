"""MWEB chain-analysis pass.

Reads the peg-in / peg-out / block data from mwebscan.py, derives deanonymisation
heuristics, and writes them back to SQLite for the site. No node / RPC. Recomputes
all derived tables from scratch, so re-running is safe.

Published so users can see which peg-outs link to a prior peg-in, and why, and
pick amounts / timing / addresses that keep their anonymity set large.

Heuristics
----------
1. Round-trip amount matching: a peg-out of amount A matches an earlier peg-in of
   A..A+tol (peg-out = peg-in minus fees). Fewer sharers, more confident link.
2. Anonymity-set sizing: confidence drops as competing peg-ins / peg-outs grow.
   A unique amount (1 peg-in, 1 peg-out) is near-certain.
3. Timing proximity: a peg-out soon after its candidate peg-in scores higher.
4. Address reuse: a peg-out to an address that also funded a peg-in ties a
   public-chain identity to an MWEB exit. Strongest signal. Needs mwebscan.py run
   with TRACK_PEGIN_SOURCES = True.
"""

import sqlite3
import json
import time
from bisect import bisect_left, bisect_right

from network import PARAMS as _NET

DB_PATH = _NET['DB_FILENAME']

# Candidate peg-in amount is in [pegout, pegout + tol] (peg-out = peg-in - fees).
# Litoshi = 1e-8 LTC.
AMOUNT_TOLERANCE_LTC = 0.002
LITOSHI = 100_000_000
TOLERANCE = int(round(AMOUNT_TOLERANCE_LTC * LITOSHI))

# Above this many peg-ins sharing an amount, the anonymity set is large enough
# that we don't report a link.
BIG_ANON_SET = 200
# Skip peg-outs with more candidate peg-ins than this.
LINK_REPORT_MAX = 10
# Beyond this gap a peg-out gets the floor timing weight, not zero (coins can sit
# in MWEB indefinitely).
TIMING_WINDOW_BLOCKS = 4032  # ~1 week of Litecoin blocks
TIMING_FLOOR = 0.25

LINKABLE_THRESHOLD = 0.5
HIGH_CONF_THRESHOLD = 0.9

# Scoring. One model, two faces: per-peg-in privacy score (higher = better
# anonymity) and per-peg-out AML risk score (higher = more traceable). AML risk
# weight per entity category.
CATEGORY_RISK = {
    'sanctioned': 1.0,
    'mixer': 0.9,
    'gambling': 0.5,
    'service': 0.3,
    'pool': 0.3,
    'merchant': 0.3,
    'exchange': 0.2,   # KYC: low AML risk, but traceable to an identity
    'other': 0.2,
}
UNKNOWN_RISK = 0.1     # no attribution
# Anonymity set size at which the amount-blending factor saturates to 1.0.
ANON_TARGET = 500
RISK_HIGH_THRESHOLD = 70   # risk_score >= this is "high risk"


def to_litoshi(value):
    return int(round((value or 0.0) * LITOSHI))


def init_tables(cur):
    cur.execute('''
        CREATE TABLE IF NOT EXISTS mweb_links (
            pegout_txid TEXT,
            pegout_vout INTEGER,
            pegout_height INTEGER,
            pegout_amount REAL,
            pegout_address TEXT,
            pegout_entity TEXT,
            pegout_category TEXT,
            pegin_txid TEXT,
            pegin_vout INTEGER,
            pegin_height INTEGER,
            pegin_amount REAL,
            block_gap INTEGER,
            candidate_count INTEGER,
            pegout_share INTEGER,
            confidence REAL,
            reasons TEXT,
            PRIMARY KEY (pegout_txid, pegout_vout)
        )
    ''')
    cur.execute('''
        CREATE TABLE IF NOT EXISTS address_reuse_links (
            address TEXT,
            pegin_txid TEXT,
            pegin_height INTEGER,
            pegin_amount REAL,
            pegout_txid TEXT,
            pegout_vout INTEGER,
            pegout_height INTEGER,
            pegout_amount REAL,
            PRIMARY KEY (pegout_txid, pegout_vout, pegin_txid)
        )
    ''')
    # Cluster membership + effective entity label (direct, or propagated across a
    # common-input cluster).
    cur.execute('''
        CREATE TABLE IF NOT EXISTS address_attribution (
            address TEXT PRIMARY KEY,
            cluster_id INTEGER,
            entity TEXT,
            category TEXT,
            confidence REAL,
            via TEXT
        )
    ''')
    # Aggregate flow per known entity / category.
    cur.execute('''
        CREATE TABLE IF NOT EXISTS entity_flows (
            entity TEXT,
            category TEXT,
            direction TEXT,        -- 'pegout' (coins to entity) or 'pegin' (entity funded)
            tx_count INTEGER,
            total_amount REAL,
            PRIMARY KEY (entity, category, direction)
        )
    ''')
    cur.execute('''
        CREATE TABLE IF NOT EXISTS pegin_scores (
            txid TEXT,
            vout INTEGER,
            amount REAL,
            block_height INTEGER,
            privacy_score INTEGER,
            anonymity_set INTEGER,
            max_link_confidence REAL,
            funded_entity TEXT,
            reused INTEGER,
            reasons TEXT,
            PRIMARY KEY (txid, vout)
        )
    ''')
    cur.execute('''
        CREATE TABLE IF NOT EXISTS pegout_scores (
            txid TEXT,
            vout INTEGER,
            amount REAL,
            block_height INTEGER,
            risk_score INTEGER,
            dest_category TEXT,
            entity_risk REAL,
            traceability REAL,
            reused INTEGER,
            reasons TEXT,
            PRIMARY KEY (txid, vout)
        )
    ''')
    cur.execute('''
        CREATE TABLE IF NOT EXISTS analysis_stats (
            key TEXT PRIMARY KEY,
            value REAL
        )
    ''')
    # Precomputed homepage aggregates (JSON) so the site skips the heavy GROUP BYs
    # per page view.
    cur.execute('''
        CREATE TABLE IF NOT EXISTS cache (
            key TEXT PRIMARY KEY,
            json TEXT,
            updated INTEGER
        )
    ''')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_links_conf ON mweb_links(confidence)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_links_pegin ON mweb_links(pegin_txid)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_links_pegout_addr ON mweb_links(pegout_address)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_reuse_addr ON address_reuse_links(address)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_attr_cluster ON address_attribution(cluster_id)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_attr_entity ON address_attribution(entity)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_pegin_scores_privacy ON pegin_scores(privacy_score)')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_pegout_scores_risk ON pegout_scores(risk_score)')


# Derived tables the analysis fully rebuilds each pass. Wiped at the START of the
# atomic recompute transaction (see main), NOT inside init_tables -- so the wipe
# is never committed separately from the repopulate, and readers never see an
# empty table mid-pass.
DERIVED_TABLES = (
    'mweb_links', 'address_reuse_links', 'address_attribution', 'entity_flows',
    'pegin_scores', 'pegout_scores', 'analysis_stats', 'cache',
)


def wipe_derived_tables(cur):
    for table in DERIVED_TABLES:
        cur.execute('DELETE FROM ' + table)


def load_pegins(cur):
    cur.execute('''
        SELECT txid, vout, block_height, amount, source_address
        FROM mweb_pegins ORDER BY amount
    ''')
    rows = cur.fetchall()
    amounts = []   # sorted litoshi, parallel to recs
    recs = []
    for txid, vout, height, amount, source in rows:
        amounts.append(to_litoshi(amount))
        recs.append({'txid': txid, 'vout': vout, 'height': height,
                     'amount': amount, 'source': source})
    return amounts, recs


def load_pegout_litoshis(cur):
    cur.execute('SELECT amount FROM mweb_pegouts')
    return sorted(to_litoshi(r[0]) for r in cur.fetchall())


def window(sorted_vals, lo, hi):
    """Return (start, end) index range of values in [lo, hi]."""
    return bisect_left(sorted_vals, lo), bisect_right(sorted_vals, hi)


def timing_weight(gap):
    if gap <= 0:
        return TIMING_FLOOR
    return max(TIMING_FLOOR, 1.0 - gap / TIMING_WINDOW_BLOCKS)


def load_labels(cur):
    """Curated address -> label map. Empty until mweblabels.py has run."""
    try:
        cur.execute("SELECT address, entity, category, confidence FROM labels")
    except sqlite3.OperationalError:
        return {}
    return {a: {'entity': e, 'category': c, 'confidence': conf or 1.0}
            for a, e, c, conf in cur.fetchall()}


def compute_clusters(cur):
    """Union-find over pegin_inputs: addresses spent together as inputs of one
    peg-in are assumed to share an owner. Returns {address: cluster_id}, empty if
    peg-in inputs were never captured."""
    try:
        cur.execute("SELECT pegin_txid, address FROM pegin_inputs")
    except sqlite3.OperationalError:
        return {}

    by_tx = {}
    for txid, addr in cur.fetchall():
        by_tx.setdefault(txid, []).append(addr)

    parent = {}

    def find(x):
        parent.setdefault(x, x)
        root = x
        while parent[root] != root:
            root = parent[root]
        while parent[x] != root:
            parent[x], x = root, parent[x]
        return root

    def union(a, b):
        ra, rb = find(a), find(b)
        if ra != rb:
            parent[ra] = rb

    for addrs in by_tx.values():
        for a in addrs[1:]:
            union(addrs[0], a)

    clusters, root_to_id, next_id = {}, {}, 0
    for addr in parent:
        r = find(addr)
        if r not in root_to_id:
            root_to_id[r] = next_id
            next_id += 1
        clusters[addr] = root_to_id[r]
    return clusters


# Categories propagated across a co-spend cluster. Excludes 'sanctioned' and any
# person-level or unknown label: tying a named/sanctioned individual to an address
# linked only by a co-spend heuristic is unproven and potentially defamatory, so
# those stay on the directly-labelled address (via='direct') and never propagate.
CLUSTER_PROPAGATE_CATEGORIES = {
    'exchange', 'service', 'pool', 'mining', 'merchant', 'gambling', 'defi',
}


def build_attribution(cur, labels, clusters):
    """One effective attribution per address from clusters + labels. A direct
    label wins; else an institutional label propagates across the cluster at
    reduced confidence (see CLUSTER_PROPAGATE_CATEGORIES). Writes
    address_attribution and returns {address: {cluster_id, entity, category,
    confidence, via}}."""
    # Highest-confidence propagatable label per cluster.
    cluster_label = {}
    for addr, cid in clusters.items():
        lbl = labels.get(addr)
        if lbl and lbl['entity'] and lbl.get('category') in CLUSTER_PROPAGATE_CATEGORIES:
            best = cluster_label.get(cid)
            if best is None or lbl['confidence'] > best['confidence']:
                cluster_label[cid] = lbl

    addresses = set(clusters) | set(labels)
    cur.execute("SELECT DISTINCT address FROM mweb_pegouts WHERE address IS NOT NULL")
    addresses.update(a for (a,) in cur.fetchall())

    attribution, rows = {}, []
    for addr in addresses:
        cid = clusters.get(addr)
        direct = labels.get(addr)
        if direct and direct['entity']:
            entity, category, confidence, via = (
                direct['entity'], direct['category'], direct['confidence'], 'direct')
        elif cid is not None and cid in cluster_label:
            lbl = cluster_label[cid]
            entity, category, confidence, via = (
                lbl['entity'], lbl['category'], round(lbl['confidence'] * 0.9, 4), 'cluster')
        else:
            entity = category = via = None
            confidence = None
        attribution[addr] = {'cluster_id': cid, 'entity': entity,
                             'category': category, 'confidence': confidence, 'via': via}
        rows.append((addr, cid, entity, category, confidence, via))

    cur.executemany('''
        INSERT OR REPLACE INTO address_attribution
            (address, cluster_id, entity, category, confidence, via)
        VALUES (?, ?, ?, ?, ?, ?)
    ''', rows)
    return attribution


def compute_entity_flows(cur, attribution):
    """Aggregate peg-out value landing at known entities and peg-in value they
    funded."""
    flows = {}

    def add(entity, category, direction, amount):
        f = flows.setdefault((entity, category, direction), [0, 0.0])
        f[0] += 1
        f[1] += amount or 0.0

    cur.execute("SELECT address, amount FROM mweb_pegouts WHERE address IS NOT NULL")
    for addr, amount in cur.fetchall():
        attr = attribution.get(addr)
        if attr and attr['entity']:
            add(attr['entity'], attr['category'], 'pegout', amount)

    cur.execute("SELECT source_address, amount FROM mweb_pegins WHERE source_address IS NOT NULL")
    for addr, amount in cur.fetchall():
        attr = attribution.get(addr)
        if attr and attr['entity']:
            add(attr['entity'], attr['category'], 'pegin', amount)

    rows = [(e, c, d, v[0], v[1]) for (e, c, d), v in flows.items()]
    cur.executemany('''
        INSERT OR REPLACE INTO entity_flows
            (entity, category, direction, tx_count, total_amount)
        VALUES (?, ?, ?, ?, ?)
    ''', rows)
    return len(rows)


def compute_round_trip_links(cur, pin_amts, pin_recs, pout_amts, attribution):
    """For every peg-out, find amount-matching prior peg-ins and score the link."""
    cur.execute('''
        SELECT txid, vout, block_height, amount, address
        FROM mweb_pegouts ORDER BY block_height
    ''')
    pegouts = cur.fetchall()

    link_rows = []
    for txid, vout, height, amount, address in pegouts:
        a_out = to_litoshi(amount)

        # Candidate peg-ins: amount in [a_out, a_out + tol], earlier than this peg-out.
        lo, hi = window(pin_amts, a_out, a_out + TOLERANCE)
        if hi - lo == 0 or hi - lo > BIG_ANON_SET:
            continue  # no match, or common amount = large anonymity set

        candidates = [pin_recs[i] for i in range(lo, hi) if pin_recs[i]['height'] < height]
        n = len(candidates)
        if n == 0 or n > LINK_REPORT_MAX:
            continue

        # Peg-outs competing for the same peg-ins (symmetric amount window).
        plo, phi = window(pout_amts, a_out - TOLERANCE, a_out + TOLERANCE)
        pegout_share = phi - plo

        # Best candidate: smallest amount diff, then most recent before peg-out.
        best = min(candidates, key=lambda c: (to_litoshi(c['amount']) - a_out, height - c['height']))
        diff = to_litoshi(best['amount']) - a_out
        gap = height - best['height']

        denom = n + max(pegout_share, 1) - 1
        confidence = round((1.0 / denom) * timing_weight(gap), 4)

        attr = attribution.get(address) or {}
        pegout_entity = attr.get('entity')
        pegout_category = attr.get('category')

        reasons = []
        reasons.append("exact amount match" if diff == 0
                       else f"amount within {diff / LITOSHI:.8f} LTC")
        reasons.append(f"{n} candidate peg-in(s) before this peg-out")
        reasons.append(f"{pegout_share} peg-out(s) share this amount")
        reasons.append(f"peg-out {gap} block(s) after peg-in")
        if pegout_entity:
            reasons.append(f"peg-out lands at known entity: {pegout_entity}")

        link_rows.append((
            txid, vout, height, amount, address, pegout_entity, pegout_category,
            best['txid'], best['vout'], best['height'], best['amount'],
            gap, n, pegout_share, confidence, json.dumps(reasons),
        ))

    cur.executemany('''
        INSERT OR REPLACE INTO mweb_links
            (pegout_txid, pegout_vout, pegout_height, pegout_amount, pegout_address,
             pegout_entity, pegout_category,
             pegin_txid, pegin_vout, pegin_height, pegin_amount,
             block_gap, candidate_count, pegout_share, confidence, reasons)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ''', link_rows)
    return len(link_rows)


def compute_address_reuse(cur):
    """Link peg-outs to peg-ins funded by the same public address. Needs the
    scanner to have populated source_address."""
    cur.execute('''
        SELECT txid, vout, block_height, amount, source_address
        FROM mweb_pegins
        WHERE source_address IS NOT NULL
    ''')
    pin_by_source = {}
    for txid, vout, height, amount, source in cur.fetchall():
        pin_by_source.setdefault(source, []).append(
            {'txid': txid, 'height': height, 'amount': amount})

    if not pin_by_source:
        return 0

    cur.execute('''
        SELECT txid, vout, block_height, amount, address
        FROM mweb_pegouts
        WHERE address IS NOT NULL
    ''')
    rows = []
    for txid, vout, height, amount, address in cur.fetchall():
        for pin in pin_by_source.get(address, []):
            rows.append((address, pin['txid'], pin['height'], pin['amount'],
                         txid, vout, height, amount))

    cur.executemany('''
        INSERT OR IGNORE INTO address_reuse_links
            (address, pegin_txid, pegin_height, pegin_amount,
             pegout_txid, pegout_vout, pegout_height, pegout_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ''', rows)
    return len(rows)


def compute_scores(cur, attribution):
    """Score every peg-in (privacy) and peg-out (AML risk) from linkage,
    anonymity and entity data."""
    import math

    # Anonymity set: peg-ins sharing each rounded (0.1 LTC) amount. Bucket in
    # Python with the same round() used at lookup, so SQLite's ROUND
    # (half-away-from-zero) can't disagree with Python's at .x5 edges.
    anon_by_amount = {}
    for (amt,) in cur.execute("SELECT amount FROM mweb_pegins"):
        if amt is None:
            continue
        key = round(amt, 1)
        anon_by_amount[key] = anon_by_amount.get(key, 0) + 1
    anon_denom = math.log10(1 + ANON_TARGET)

    # Strongest link confidence per peg-in output and per peg-out output. Key BOTH
    # by (txid, vout): all peg-outs in a block share the HogEx txid, and one
    # funding tx can emit several peg-in outputs -- keying by txid alone would
    # bleed a link's confidence (and the "linked at N%" reason and depressed
    # privacy score) onto the unlinked sibling outputs, a false deanonymisation.
    pegin_max_conf = {}  # (pegin_txid, pegin_vout) -> strongest link confidence
    pegout_conf = {}     # (pegout_txid, pegout_vout) -> (confidence, pegin_txid)
    cur.execute("SELECT pegin_txid, pegin_vout, pegout_txid, pegout_vout, confidence FROM mweb_links")
    for pegin_txid, pegin_vout, pegout_txid, pegout_vout, conf in cur.fetchall():
        if conf is None:
            continue
        pin_key = (pegin_txid, pegin_vout)
        if conf > pegin_max_conf.get(pin_key, 0):
            pegin_max_conf[pin_key] = conf
        pegout_conf[(pegout_txid, pegout_vout)] = (conf, pegin_txid)

    # Address-reuse participants.
    reused_pegins, reused_pegouts = set(), set()
    cur.execute("SELECT pegin_txid, pegout_txid, pegout_vout FROM address_reuse_links")
    for pegin_txid, pegout_txid, pegout_vout in cur.fetchall():
        reused_pegins.add(pegin_txid)
        reused_pegouts.add((pegout_txid, pegout_vout))

    # Peg-in txid -> funding source, for risk carried through a link.
    cur.execute("SELECT txid, source_address FROM mweb_pegins WHERE source_address IS NOT NULL")
    pegin_source = {txid: src for txid, src in cur.fetchall()}

    def entity_risk(addr):
        attr = attribution.get(addr) if addr else None
        if attr and attr.get('category'):
            return CATEGORY_RISK.get(attr['category'], UNKNOWN_RISK), attr.get('entity')
        return UNKNOWN_RISK, None

    # --- Peg-in privacy scores ---
    cur.execute("SELECT txid, vout, amount, block_height, source_address FROM mweb_pegins")
    pin_rows = []
    for txid, vout, amount, height, source in cur.fetchall():
        anon = anon_by_amount.get(round(amount or 0.0, 1), 1)
        anon_factor = min(1.0, math.log10(1 + anon) / anon_denom)
        link_pen = pegin_max_conf.get((txid, vout), 0.0)
        reused = 1 if txid in reused_pegins else 0
        src_risk, src_entity = entity_risk(source)
        entity_pen = 0.3 if src_entity else 0.0

        score = 100.0 * anon_factor
        score *= (1 - 0.9 * link_pen)
        score *= (1 - 0.5 * reused)
        score *= (1 - entity_pen)
        score = max(0, min(100, round(score)))

        reasons = [f"anonymity set {anon} (rounded amount)"]
        if link_pen > 0:
            reasons.append(f"linked to a peg-out at {round(link_pen * 100)}% confidence")
        if reused:
            reasons.append("funding address reused on a peg-out")
        if src_entity:
            reasons.append(f"funded from known entity: {src_entity}")

        pin_rows.append((txid, vout, amount, height, score, anon,
                         round(link_pen, 4), src_entity, reused, json.dumps(reasons)))

    cur.executemany('''
        INSERT OR REPLACE INTO pegin_scores
            (txid, vout, amount, block_height, privacy_score, anonymity_set,
             max_link_confidence, funded_entity, reused, reasons)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ''', pin_rows)

    # --- Peg-out risk scores ---
    cur.execute("SELECT txid, vout, amount, block_height, address FROM mweb_pegouts")
    pout_rows = []
    for txid, vout, amount, height, address in cur.fetchall():
        dest_risk, _ = entity_risk(address)
        dest_attr = attribution.get(address) if address else None
        dest_category = dest_attr.get('category') if dest_attr else None

        conf, linked_pegin = pegout_conf.get((txid, vout), (0.0, None))
        src_risk = UNKNOWN_RISK
        if linked_pegin and linked_pegin in pegin_source:
            src_risk, _ = entity_risk(pegin_source[linked_pegin])

        # dest_risk is direct (the peg-out's own destination address); src_risk is
        # carried across an INFERRED round-trip link, so weight it by the link
        # confidence. Otherwise a coincidental amount-match to a sanctioned peg-in
        # stamps full sanctioned-tier risk on an otherwise-unremarkable peg-out.
        entity_risk_val = max(dest_risk, src_risk * conf)
        reused = 1 if (txid, vout) in reused_pegouts else 0
        reuse_bump = 0.15 * reused

        risk = 100.0 * min(1.0, 0.55 * entity_risk_val + 0.35 * conf + reuse_bump)
        risk = max(0, min(100, round(risk)))

        reasons = []
        if dest_category:
            reasons.append(f"destination category: {dest_category}")
        if conf > 0:
            reasons.append(f"traceable to a peg-in at {round(conf * 100)}% confidence")
        if reused:
            reasons.append("address reused across peg-in and peg-out")
        if not reasons:
            reasons.append("no strong risk signals")

        pout_rows.append((txid, vout, amount, height, risk, dest_category,
                          round(entity_risk_val, 4), round(conf, 4), reused, json.dumps(reasons)))

    cur.executemany('''
        INSERT OR REPLACE INTO pegout_scores
            (txid, vout, amount, block_height, risk_score, dest_category,
             entity_risk, traceability, reused, reasons)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ''', pout_rows)

    return len(pin_rows), len(pout_rows)


def compute_cache(cur):
    """Materialise the homepage's heavy aggregate queries into the cache table."""
    queries = {
        'pegin_dist_rounded': "SELECT ROUND(amount,1) AS amount, COUNT(*) AS count FROM mweb_pegins GROUP BY ROUND(amount,1) ORDER BY count DESC, amount ASC",
        'pegin_dist_exact': "SELECT amount, COUNT(*) AS count FROM mweb_pegins GROUP BY amount ORDER BY count DESC, amount DESC LIMIT 2000",
        'pegout_dist_rounded': "SELECT ROUND(amount,1) AS amount, COUNT(*) AS count FROM mweb_pegouts GROUP BY ROUND(amount,1) ORDER BY count DESC, amount ASC",
        'pegout_dist_exact': "SELECT amount, COUNT(*) AS count FROM mweb_pegouts GROUP BY amount ORDER BY count DESC, amount DESC LIMIT 2000",
        'top_pegout_addresses': "SELECT address, COUNT(*) AS count, SUM(amount) AS total FROM mweb_pegouts WHERE address IS NOT NULL GROUP BY address HAVING count > 1 ORDER BY count DESC, total DESC LIMIT 500",
        'timeseries_daily': "SELECT CAST(block_time/86400 AS INT) AS day, MAX(block_height) AS height, AVG(supply) AS supply, SUM(pegin_amount) AS pegin, SUM(pegout_amount) AS pegout, SUM(pegin_count) AS pegins, SUM(pegout_count) AS pegouts, MAX(mweb_txos) AS utxos, SUM(mweb_kernels) AS kernels FROM mweb_blocks WHERE block_time IS NOT NULL GROUP BY day ORDER BY day",
    }
    now = int(time.time())
    for key, sql in queries.items():
        cur.execute(sql)
        cols = [d[0] for d in cur.description]
        data = [dict(zip(cols, row)) for row in cur.fetchall()]
        cur.execute("INSERT OR REPLACE INTO cache (key, json, updated) VALUES (?, ?, ?)",
                    (key, json.dumps(data), now))
    return len(queries)


def compute_recommendations(cur):
    """Derive privacy guidance from current chain state, stored as a cache entry."""
    best = cur.execute('''
        SELECT ROUND(amount, 1) AS a, COUNT(*) AS c
        FROM mweb_pegins WHERE amount > 0
        GROUP BY a HAVING a > 0 ORDER BY c DESC LIMIT 12
    ''').fetchall()
    best_amounts = [{'amount': a, 'anonymity_set': c} for a, c in best]

    # Common peg-out amounts: blend your exit into a crowd too, so it is harder
    # to match an exit back to a specific peg-in.
    best_out = cur.execute('''
        SELECT ROUND(amount, 1) AS a, COUNT(*) AS c
        FROM mweb_pegouts WHERE amount > 0
        GROUP BY a HAVING a > 0 ORDER BY c DESC LIMIT 12
    ''').fetchall()
    best_pegout_amounts = [{'amount': a, 'anonymity_set': c} for a, c in best_out]

    gaps = [r[0] for r in cur.execute(
        f"SELECT block_gap FROM mweb_links WHERE confidence >= {HIGH_CONF_THRESHOLD} "
        f"AND block_gap IS NOT NULL ORDER BY block_gap"
    ).fetchall()]
    if gaps:
        median_gap = gaps[len(gaps) // 2]
        recommended_wait = max(1008, median_gap * 3)
    else:
        recommended_wait = 1008  # ~1.75 days of Litecoin blocks

    recommendations = {
        'best_pegin_amounts': best_amounts,
        'best_pegout_amounts': best_pegout_amounts,
        'recommended_wait_blocks': recommended_wait,
        'recommended_wait_hours': round(recommended_wait * 2.5 / 60, 1),  # ~2.5 min/block
        'recommended_internal_mixes': 2,
        'notes': [
            'Use a common, rounded amount from the list to blend into the largest anonymity set.',
            'Move or split funds inside MWEB before pegging out, so your exit amount does not closely match your entry.',
            'Never peg out to an address reused from your peg-in or otherwise linked to your public identity.',
            f'Wait at least ~{recommended_wait} blocks (~{round(recommended_wait * 2.5 / 60, 1)}h) before pegging out to avoid the quick round-trip pattern.',
        ],
    }
    cur.execute("INSERT OR REPLACE INTO cache (key, json, updated) VALUES ('recommendations', ?, ?)",
                (json.dumps(recommendations), int(time.time())))
    return recommendations


def compute_stats(cur, link_count, reuse_count, prev_updated=None):
    def scalar(sql):
        return cur.execute(sql).fetchone()[0] or 0

    stats = {
        'total_pegins': scalar('SELECT COUNT(*) FROM mweb_pegins'),
        'total_pegouts': scalar('SELECT COUNT(*) FROM mweb_pegouts'),
        # Peg-ins with an exact amount no other peg-in shares: most fingerprintable.
        'unique_amount_pegins': scalar('''
            SELECT COUNT(*) FROM (
                SELECT amount FROM mweb_pegins GROUP BY amount HAVING COUNT(*) = 1
            )
        '''),
        'linkable_pegouts': scalar(
            f'SELECT COUNT(*) FROM mweb_links WHERE confidence >= {LINKABLE_THRESHOLD}'),
        'high_confidence_links': scalar(
            f'SELECT COUNT(*) FROM mweb_links WHERE confidence >= {HIGH_CONF_THRESHOLD}'),
        'address_reuse_links': reuse_count,
        'reported_links': link_count,
        # Entity attribution.
        'labeled_addresses': scalar('SELECT COUNT(*) FROM address_attribution WHERE entity IS NOT NULL'),
        'address_clusters': scalar('SELECT COUNT(DISTINCT cluster_id) FROM address_attribution WHERE cluster_id IS NOT NULL'),
        'pegouts_to_known_entity': scalar(
            "SELECT COALESCE(SUM(tx_count),0) FROM entity_flows WHERE direction='pegout'"),
        'pegout_ltc_to_known_entity': scalar(
            "SELECT COALESCE(SUM(total_amount),0) FROM entity_flows WHERE direction='pegout'"),
        # Scoring.
        'avg_privacy_score': round(scalar('SELECT AVG(privacy_score) FROM pegin_scores'), 1),
        'high_risk_pegouts': scalar(
            f'SELECT COUNT(*) FROM pegout_scores WHERE risk_score >= {RISK_HIGH_THRESHOLD}'),
        'avg_pegout_risk': round(scalar('SELECT AVG(risk_score) FROM pegout_scores'), 1),
    }
    # Observed seconds between analysis passes, for the homepage cadence note.
    # Guard to a plausible band so first-run (prev_updated None) and ad-hoc
    # reruns close together don't skew the displayed interval; steady-state
    # timer runs settle to the real cadence.
    if prev_updated:
        interval = int(time.time()) - int(prev_updated)
        if 120 <= interval <= 21600:
            stats['refresh_sec'] = interval
    cur.executemany('INSERT OR REPLACE INTO analysis_stats (key, value) VALUES (?, ?)',
                    list(stats.items()))
    return stats


def main():
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    conn.execute('PRAGMA journal_mode = WAL;')
    # Match the scanner's 60s so a pass doesn't lose every race to it and die
    # mid-recompute with the derived tables already wiped. Same WAL backstop as
    # the scanner so a large recompute transaction can't leave the file bloated.
    conn.execute('PRAGMA busy_timeout = 60000;')
    conn.execute('PRAGMA journal_size_limit = 268435456;')   # 256 MiB

    # Observed refresh cadence: read the previous pass's write time before
    # wipe_derived_tables() clears the cache in the recompute below. cache.updated
    # is set to "now" on every pass, so the gap to this pass is the real interval
    # between analysis runs -- the homepage shows this instead of a hardcoded
    # guess, whatever the timer is.
    prev_updated = None
    if cur.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cache'").fetchone():
        prev_updated = cur.execute("SELECT MAX(updated) FROM cache").fetchone()[0]

    init_tables(cur)

    # The analysis reads mweb_txos / mweb_kernels from mweb_blocks. If the
    # scanner hasn't migrated the DB yet (e.g. the analysis timer fires before
    # the scanner restart), add the columns here so the queries don't fail.
    if cur.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='mweb_blocks'").fetchone():
        # Race-safe: ignore a duplicate if the scanner added the column first.
        for _col in ('mweb_kernels', 'mweb_txos'):
            try:
                cur.execute("ALTER TABLE mweb_blocks ADD COLUMN %s INTEGER" % _col)
            except sqlite3.OperationalError as _e:
                if 'duplicate column' not in str(_e).lower():
                    raise
        conn.commit()

    # ---- Atomic recompute --------------------------------------------------
    # Everything from the wipe to the single commit runs in ONE write
    # transaction: readers keep seeing the previous snapshot until commit (never
    # an empty or half-updated table), and any failure rolls back to the old data
    # instead of blanking the site. All DDL (init_tables + the column migration)
    # is already committed above, so nothing implicitly commits the wipe
    # mid-transaction. The DB-mediated reads (compute_scores / _recommendations /
    # _stats reading mweb_links etc.) still see the fresh rows, because a
    # connection sees its own uncommitted writes within the same transaction.
    try:
        wipe_derived_tables(cur)

        # Attribution first, so links can be tagged with destination entities.
        labels = load_labels(cur)
        clusters = compute_clusters(cur)
        attribution = build_attribution(cur, labels, clusters)
        print(f"Loaded {len(labels)} label(s); built {len(set(clusters.values()))} cluster(s) "
              f"over {len(clusters)} address(es).")

        pin_amts, pin_recs = load_pegins(cur)
        pout_amts = load_pegout_litoshis(cur)
        print(f"Loaded {len(pin_recs)} peg-ins, {len(pout_amts)} peg-outs.")

        link_count = compute_round_trip_links(cur, pin_amts, pin_recs, pout_amts, attribution)
        print(f"Reported {link_count} round-trip link(s).")

        reuse_count = compute_address_reuse(cur)
        print(f"Found {reuse_count} address-reuse link(s).")

        flow_count = compute_entity_flows(cur, attribution)
        print(f"Aggregated {flow_count} entity-flow row(s).")

        pin_scored, pout_scored = compute_scores(cur, attribution)
        print(f"Scored {pin_scored} peg-in(s) and {pout_scored} peg-out(s).")

        cache_count = compute_cache(cur)
        print(f"Cached {cache_count} homepage aggregate(s).")

        rec = compute_recommendations(cur)
        print(f"Built recommendations ({len(rec['best_pegin_amounts'])} suggested amounts).")

        stats = compute_stats(cur, link_count, reuse_count, prev_updated)
        conn.commit()
    except Exception:
        # Abandon the half-built recompute; the previous snapshot stays intact
        # and the timer retries next pass. Releases the writer lock immediately.
        conn.rollback()
        conn.close()
        raise
    conn.close()

    print("Analysis complete:")
    for k, v in stats.items():
        print(f"  {k}: {int(v)}")


if __name__ == '__main__':
    main()
