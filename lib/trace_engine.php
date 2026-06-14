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

/**
 * Privacy of pegging in a given amount: how well it blends into the peg-ins at
 * that rounded amount. Shared by the homepage tool and the API privacy endpoint.
 */
function mwebscan_amount_privacy($db, $amount)
{
    if ($amount <= 0) {
        return null;   // 0 / negative is not a valid peg-in amount
    }
    $st = $db->prepare("SELECT COUNT(*) FROM mweb_pegins WHERE ROUND(amount, 1) = ROUND(?, 1)");
    $st->execute([$amount]);
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

function mwebscan_table_exists($db, $name)
{
    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
    $st->execute([$name]);
    return (bool) $st->fetchColumn();
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
        foreach ($inputs as &$in) {
            $in['attribution'] = mwebscan_attribution($db, $in['address']);
        }
        unset($in);
    }

    $p['inputs'] = $inputs;
    $p['source_attribution'] = mwebscan_attribution($db, $p['source_address']);
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
        'pegins' => [],
        'pegouts' => [],
    ];

    if ($type === 'not_found') {
        return $result;
    }

    if ($type === 'amount') {
        $amount = (float) $q;
        $result['amount_privacy'] = mwebscan_amount_privacy($db, $amount);

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
        $st = $db->prepare("SELECT txid FROM mweb_pegins WHERE source_address = ? LIMIT 200");
        $st->execute([$q]);
        foreach ($st as $r) {
            $peginTxids[$r['txid']] = true;
        }
        if (mwebscan_table_exists($db, 'pegin_inputs')) {
            $st = $db->prepare("SELECT DISTINCT pegin_txid FROM pegin_inputs WHERE address = ? LIMIT 200");
            $st->execute([$q]);
            foreach ($st as $r) {
                $peginTxids[$r['pegin_txid']] = true;
            }
        }
        foreach (array_keys($peginTxids) as $txid) {
            $node = mwebscan_pegin_node($db, $txid);
            if ($node) {
                $result['pegins'][] = $node;
            }
        }

        $st = $db->prepare("
            SELECT txid, vout FROM mweb_pegouts WHERE address = ?
            ORDER BY block_height DESC LIMIT 200
        ");
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
