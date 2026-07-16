<?php
/**
 * MWEBscan trace engine.
 *
 * Data functions over the analysis tables. Everything returns plain arrays and
 * produces no output, so the HTML views and the JSON API share this logic.
 *
 * A trace follows coins across the MWEB hop:
 *   funding addresses -> peg-in -> [MWEB] -> peg-out -> destination
 * The MWEB hop is inferred (mwebanalysis.py), so every cross-hop edge carries
 * a confidence score.
 */

// Cap peg-in/peg-out nodes materialised per address trace so one query for a
// busy (reused/exchange) address can't fan out into tens of thousands of DB
// operations and exhaust the PHP-FPM pool. The amount branch already caps at 25.
if (!defined('MWEBSCAN_TRACE_MAX_NODES')) {
    define('MWEBSCAN_TRACE_MAX_NODES', 25);
}

// Round-trip amount tolerance in LTC, mirroring mwebanalysis.py
// (AMOUNT_TOLERANCE_LTC): a peg-out of X is linked to peg-ins with an amount in
// [X, X + tolerance]. Used to report how linkable an exit amount is.
if (!defined('MWEBSCAN_AMOUNT_TOLERANCE')) {
    define('MWEBSCAN_AMOUNT_TOLERANCE', 0.002);
}

/**
 * Privacy of pegging in a given amount: how well it blends into the peg-ins at
 * that rounded amount. Shared by the homepage tool and the API privacy endpoint.
 */
function mwebscan_amount_privacy($db, $amount)
{
    if ($amount <= 0) {
        return null;   // 0 / negative is not a valid peg-in amount
    }
    // Sargable range instead of ROUND(amount,1)=ROUND(?,1): wrapping the column
    // in ROUND() prevents idx_pegins_amount from being used and forces a full
    // table scan on every unauthenticated request. The 0.1-wide bucket centred
    // on the rounded amount matches the same rows via an index range seek.
    $bucket = round((float) $amount, 1);
    $st = $db->prepare("SELECT COUNT(*) FROM mweb_pegins WHERE amount >= ? AND amount < ?");
    $st->execute([$bucket - 0.05, $bucket + 0.05]);
    $rounded = (int) $st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM mweb_pegins WHERE amount = ?");
    $st->execute([$amount]);
    $exact = (int) $st->fetchColumn();

    $factor = $rounded > 0 ? min(1.0, log10(1 + $rounded) / log10(1 + 500)) : 0.0;
    $score = (int) round($factor * 100);

    if ($rounded >= 500)      { $rating = 'Excellent'; $advice = 'This is a very common amount; you blend into a large anonymity set.'; }
    elseif ($rounded >= 100)  { $rating = 'Good';      $advice = 'A common amount with a healthy anonymity set.'; }
    elseif ($rounded >= 20)   { $rating = 'Moderate';  $advice = 'Usable, but a rounder, more common amount gives better cover.'; }
    elseif ($rounded >= 5)    { $rating = 'Weak';      $advice = 'Few peg-ins share this amount. You are relatively easy to single out.'; }
    else                      { $rating = 'Very weak'; $advice = 'Almost nobody uses this amount. A round-trip would be highly linkable. Pick a common, rounded amount.'; }

    return [
        'amount' => (float) $amount,
        'rounded' => round($amount, 1),
        'rounded_set' => $rounded,
        'exact_set' => $exact,
        'privacy_score' => $score,
        'rating' => $rating,
        'advice' => $advice,
    ];
}

/**
 * Privacy of pegging OUT a given amount: the peg-out twin of
 * mwebscan_amount_privacy. Two dimensions: (1) exit anonymity -- how many
 * peg-outs share the rounded amount, so how well the exit blends in; and (2)
 * round-trip linkability -- how many peg-ins the amount would match, since an
 * exit that pins back to one or two entries is trivially traceable. The score
 * rewards exit blend-in and is tempered when the exit is easily linked back.
 */
function mwebscan_pegout_amount_privacy($db, $amount)
{
    $amount = (float) $amount;
    if ($amount <= 0) {
        return null;   // 0 / negative is not a real peg-out amount
    }

    // Exit anonymity set: peg-outs sharing the rounded (0.1) amount. Sargable
    // range so idx_pegouts_amount is used instead of a full-table scan.
    $bucket = round($amount, 1);
    $st = $db->prepare("SELECT COUNT(*) FROM mweb_pegouts WHERE amount >= ? AND amount < ?");
    $st->execute([$bucket - 0.05, $bucket + 0.05]);
    $exitSet = (int) $st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM mweb_pegouts WHERE amount = ?");
    $st->execute([$amount]);
    $exitExact = (int) $st->fetchColumn();

    // Round-trip linkability: peg-ins this exit amount could match (amount in
    // [X, X + tolerance], mirroring the linker). Few matches => the exit points
    // back to a specific entry; many => the round-trip is ambiguous.
    $st = $db->prepare("SELECT COUNT(*) FROM mweb_pegins WHERE amount >= ? AND amount <= ?");
    $st->execute([$amount, $amount + MWEBSCAN_AMOUNT_TOLERANCE]);
    $matchingPegins = (int) $st->fetchColumn();

    // Score on exit blend-in (same curve as the peg-in tool), then halve it when
    // only a handful of peg-ins match, since that makes the round-trip easy to
    // reconstruct regardless of how the exit amount blends in.
    $factor = $exitSet > 0 ? min(1.0, log10(1 + $exitSet) / log10(1 + 500)) : 0.0;
    $score = (int) round($factor * 100);
    if ($matchingPegins > 0 && $matchingPegins <= 3) {
        $score = (int) round($score * 0.5);
    }
    $score = max(0, min(100, $score));

    if ($score >= 80)      { $rating = 'Excellent'; }
    elseif ($score >= 55)  { $rating = 'Good'; }
    elseif ($score >= 30)  { $rating = 'Moderate'; }
    elseif ($score >= 10)  { $rating = 'Weak'; }
    else                   { $rating = 'Very weak'; }

    if ($matchingPegins === 0) {
        $advice = 'No peg-in matches this exit amount within tolerance, so a direct round-trip is unlikely; cover still depends on how many peg-outs share the amount.';
    } elseif ($matchingPegins <= 3) {
        $advice = 'Only ' . $matchingPegins . ' peg-in(s) match this exit amount, so a round-trip is easy to reconstruct. Move or split funds inside MWEB first, or exit a common, rounded amount.';
    } elseif ($exitSet >= 100) {
        $advice = 'A common exit amount with a healthy peg-out crowd; you blend in well on the way out.';
    } else {
        $advice = 'Usable, but a rounder, more common exit amount gives better cover among peg-outs.';
    }

    return [
        'amount' => $amount,
        'rounded' => round($amount, 1),
        'exit_set' => $exitSet,
        'exit_exact_set' => $exitExact,
        'matching_pegins' => $matchingPegins,
        'privacy_score' => $score,
        'rating' => $rating,
        'advice' => $advice,
    ];
}

function mwebscan_table_exists($db, $name)
{
    // Memoise per request: this is called in the trace hot loop (once per input,
    // source and node), and table existence cannot change within a request, so
    // the repeated sqlite_master probes are pure overhead.
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
    $st->execute([$name]);
    return $cache[$name] = (bool) $st->fetchColumn();
}

function mwebscan_attribution($db, $address)
{
    if ($address === null || $address === '') {
        return null;
    }
    if (!mwebscan_table_exists($db, 'address_attribution')) {
        return null;
    }
    $st = $db->prepare("
        SELECT address, entity, category, confidence, via, cluster_id
        FROM address_attribution WHERE address = ?
    ");
    $st->execute([$address]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    // Return the row even when unlabelled, so callers can use cluster_id.
    return $row ?: null;
}

/**
 * Batch form of mwebscan_attribution: one `WHERE address IN (...)` query for
 * many addresses, returning [address => row]. Used in the trace hot path so a
 * node's inputs don't each trigger their own lookup (the N+1 that made a busy
 * address an unauthenticated DoS vector).
 */
function mwebscan_attribution_map($db, array $addresses)
{
    $addresses = array_values(array_unique(array_filter(
        $addresses,
        static function ($a) { return $a !== null && $a !== ''; }
    )));
    if (!$addresses || !mwebscan_table_exists($db, 'address_attribution')) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($addresses), '?'));
    $st = $db->prepare(
        "SELECT address, entity, category, confidence, via, cluster_id
         FROM address_attribution WHERE address IN ($ph)"
    );
    $st->execute($addresses);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['address']] = $row;
    }
    return $map;
}

/** Decide what the query string refers to. */
function mwebscan_classify($db, $q)
{
    // A short positive numeric string is an amount; txids are 64 hex chars and
    // addresses contain non-digits. Zero/negative is not a real peg-in amount.
    if (is_numeric($q) && (float) $q > 0 && strlen($q) < 20) {
        return 'amount';
    }

    $checks = [
        ['mweb_pegins', 'txid', 'pegin_tx'],
        ['mweb_pegouts', 'txid', 'pegout_tx'],
        ['mweb_pegouts', 'address', 'address'],
        ['mweb_pegins', 'source_address', 'address'],
    ];
    foreach ($checks as [$table, $col, $type]) {
        $st = $db->prepare("SELECT 1 FROM $table WHERE $col = ? LIMIT 1");
        $st->execute([$q]);
        if ($st->fetchColumn()) {
            return $type;
        }
    }
    foreach (['pegin_inputs' => 'address', 'address_attribution' => 'address'] as $table => $col) {
        if (mwebscan_table_exists($db, $table)) {
            $st = $db->prepare("SELECT 1 FROM $table WHERE $col = ? LIMIT 1");
            $st->execute([$q]);
            if ($st->fetchColumn()) {
                return 'address';
            }
        }
    }
    return 'not_found';
}

function mwebscan_links_for_pegin($db, $txid)
{
    if (!mwebscan_table_exists($db, 'mweb_links')) {
        return [];
    }
    $st = $db->prepare("
        SELECT pegout_txid, pegout_vout, pegout_amount, pegout_address,
               pegout_entity, pegout_category, confidence, block_gap,
               candidate_count, reasons
        FROM mweb_links WHERE pegin_txid = ?
        ORDER BY confidence DESC
    ");
    $st->execute([$txid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mwebscan_links_for_pegout($db, $txid)
{
    if (!mwebscan_table_exists($db, 'mweb_links')) {
        return [];
    }
    $st = $db->prepare("
        SELECT pegin_txid, pegin_amount, pegin_height, confidence, block_gap,
               candidate_count, reasons
        FROM mweb_links WHERE pegout_txid = ?
        ORDER BY confidence DESC
    ");
    $st->execute([$txid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function mwebscan_pegin_node($db, $txid)
{
    $st = $db->prepare("
        SELECT txid, vout, block_height, block_time, amount, source_address, input_count
        FROM mweb_pegins WHERE txid = ? LIMIT 1
    ");
    $st->execute([$txid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return null;
    }

    $inputs = [];
    if (mwebscan_table_exists($db, 'pegin_inputs')) {
        $st = $db->prepare("
            SELECT address, value FROM pegin_inputs
            WHERE pegin_txid = ? ORDER BY value DESC LIMIT 50
        ");
        $st->execute([$txid]);
        $inputs = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Attribute every address in this node (inputs + funding source) in ONE
    // query rather than two per address, so a node with 50 inputs costs a single
    // lookup instead of ~100 -- the difference between a cheap trace and a DoS.
    $attrAddrs = array_column($inputs, 'address');
    $attrAddrs[] = $p['source_address'];
    $attrMap = mwebscan_attribution_map($db, $attrAddrs);
    foreach ($inputs as &$in) {
        $in['attribution'] = $attrMap[$in['address']] ?? null;
    }
    unset($in);

    $p['inputs'] = $inputs;
    $p['source_attribution'] = $attrMap[$p['source_address']] ?? null;
    $p['links'] = mwebscan_links_for_pegin($db, $txid);

    $p['score'] = null;
    if (mwebscan_table_exists($db, 'pegin_scores')) {
        $st = $db->prepare("
            SELECT privacy_score, anonymity_set, max_link_confidence, reasons
            FROM pegin_scores WHERE txid = ? AND vout = ? LIMIT 1
        ");
        $st->execute([$p['txid'], $p['vout']]);
        $p['score'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $p;
}

function mwebscan_pegout_node($db, $txid, $vout)
{
    $st = $db->prepare("
        SELECT txid, vout, block_height, block_time, amount, address
        FROM mweb_pegouts WHERE txid = ? AND vout = ? LIMIT 1
    ");
    $st->execute([$txid, $vout]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return null;
    }
    $p['attribution'] = mwebscan_attribution($db, $p['address']);
    $p['links'] = mwebscan_links_for_pegout($db, $txid);

    $p['score'] = null;
    if (mwebscan_table_exists($db, 'pegout_scores')) {
        $st = $db->prepare("
            SELECT risk_score, dest_category, traceability, reasons
            FROM pegout_scores WHERE txid = ? AND vout = ? LIMIT 1
        ");
        $st->execute([$p['txid'], $p['vout']]);
        $p['score'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $p;
}

/**
 * Multi-hop follow: from a public address to its peg-in, across the MWEB hop to
 * the best-linked peg-out, to that peg-out's destination, and repeat. Bounded by
 * $maxHops and a visited set. Multiply per-hop confidences for the chain total.
 */
function mwebscan_follow($db, $address, $maxHops = 3)
{
    $hops = [];
    $visited = [];
    $current = $address;

    for ($i = 0; $i < $maxHops; $i++) {
        if (!$current || isset($visited[$current])) {
            break;
        }
        $visited[$current] = true;

        $st = $db->prepare("
            SELECT txid, amount, block_height FROM mweb_pegins
            WHERE source_address = ? ORDER BY block_height LIMIT 1
        ");
        $st->execute([$current]);
        $pin = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pin) {
            break;
        }

        $links = mwebscan_links_for_pegin($db, $pin['txid']);
        $best = $links[0] ?? null;
        $hops[] = [
            'from' => $current,
            'pegin' => $pin,
            'pegout' => $best,
            'to' => $best['pegout_address'] ?? null,
            'to_attribution' => $best ? mwebscan_attribution($db, $best['pegout_address']) : null,
            'confidence' => $best['confidence'] ?? null,
        ];
        if (!$best || !$best['pegout_address']) {
            break;
        }
        $current = $best['pegout_address'];
    }

    return $hops;
}

/**
 * Build a one-hop-each-direction trace centred on the query (txid or address).
 * Returns: ['query', 'type', 'attribution', 'cluster', 'pegins'[], 'pegouts'[]].
 */
function mwebscan_trace($db, $q)
{
    $q = trim($q);
    $type = $q === '' ? 'not_found' : mwebscan_classify($db, $q);
    $result = [
        'query' => $q,
        'type' => $type,
        'attribution' => null,
        'cluster' => null,
        'amount_privacy' => null,
        'pegout_amount_privacy' => null,
        'pegins' => [],
        'pegouts' => [],
    ];

    if ($type === 'not_found') {
        return $result;
    }

    if ($type === 'amount') {
        $amount = (float) $q;
        $result['amount_privacy'] = mwebscan_amount_privacy($db, $amount);
        $result['pegout_amount_privacy'] = mwebscan_pegout_amount_privacy($db, $amount);

        $st = $db->prepare("SELECT txid FROM mweb_pegins WHERE amount = ? ORDER BY block_height DESC LIMIT 25");
        $st->execute([$amount]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $node = mwebscan_pegin_node($db, $r['txid']);
            if ($node) {
                $result['pegins'][] = $node;
            }
        }

        $st = $db->prepare("SELECT txid, vout FROM mweb_pegouts WHERE amount = ? ORDER BY block_height DESC LIMIT 25");
        $st->execute([$amount]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $node = mwebscan_pegout_node($db, $r['txid'], $r['vout']);
            if ($node) {
                $result['pegouts'][] = $node;
            }
        }
        return $result;
    }

    if ($type === 'address') {
        $result['attribution'] = mwebscan_attribution($db, $q);

        if ($result['attribution'] && $result['attribution']['cluster_id'] !== null) {
            $st = $db->prepare("
                SELECT address, entity, via FROM address_attribution
                WHERE cluster_id = ? LIMIT 200
            ");
            $st->execute([$result['attribution']['cluster_id']]);
            $result['cluster'] = [
                'id' => $result['attribution']['cluster_id'],
                'members' => $st->fetchAll(PDO::FETCH_ASSOC),
            ];
        }

        $peginTxids = [];
        $st = $db->prepare("SELECT txid FROM mweb_pegins WHERE source_address = ? ORDER BY block_height DESC LIMIT " . MWEBSCAN_TRACE_MAX_NODES);
        $st->execute([$q]);
        foreach ($st as $r) {
            $peginTxids[$r['txid']] = true;
        }
        if (count($peginTxids) < MWEBSCAN_TRACE_MAX_NODES && mwebscan_table_exists($db, 'pegin_inputs')) {
            $st = $db->prepare("SELECT DISTINCT pegin_txid FROM pegin_inputs WHERE address = ? LIMIT " . MWEBSCAN_TRACE_MAX_NODES);
            $st->execute([$q]);
            foreach ($st as $r) {
                $peginTxids[$r['pegin_txid']] = true;
            }
        }
        // Hard cap the number of full nodes built, regardless of how many txids
        // the two queries surfaced, so a heavily-reused address stays bounded.
        foreach (array_slice(array_keys($peginTxids), 0, MWEBSCAN_TRACE_MAX_NODES) as $txid) {
            $node = mwebscan_pegin_node($db, $txid);
            if ($node) {
                $result['pegins'][] = $node;
            }
        }

        $st = $db->prepare("
            SELECT txid, vout FROM mweb_pegouts WHERE address = ?
            ORDER BY block_height DESC LIMIT " . MWEBSCAN_TRACE_MAX_NODES);
        $st->execute([$q]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $node = mwebscan_pegout_node($db, $r['txid'], $r['vout']);
            if ($node) {
                $result['pegouts'][] = $node;
            }
        }
    } elseif ($type === 'pegin_tx') {
        $node = mwebscan_pegin_node($db, $q);
        if ($node) {
            $result['pegins'][] = $node;
        }
    } elseif ($type === 'pegout_tx') {
        $st = $db->prepare("SELECT txid, vout FROM mweb_pegouts WHERE txid = ? ORDER BY vout");
        $st->execute([$q]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $node = mwebscan_pegout_node($db, $r['txid'], $r['vout']);
            if ($node) {
                $result['pegouts'][] = $node;
            }
        }
    }

    return $result;
}
