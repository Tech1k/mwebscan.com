<?php
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/trace_engine.php';

$db = mwebscan_db();

// Heavy aggregates come from the precomputed cache, with a live-query fallback
// when the cache isn't populated.
$standardizedPegins = mwebscan_cached($db, 'pegin_dist_rounded',
    "SELECT ROUND(amount, 1) as amount, COUNT(*) as count FROM mweb_pegins GROUP BY ROUND(amount, 1) ORDER BY count DESC, amount ASC");

$randomPegins = mwebscan_cached($db, 'pegin_dist_exact',
    "SELECT amount, COUNT(*) as count FROM mweb_pegins GROUP BY amount ORDER BY count DESC, amount DESC LIMIT 2000");

$standardizedPegouts = mwebscan_cached($db, 'pegout_dist_rounded',
    "SELECT ROUND(amount, 1) as amount, COUNT(*) as count FROM mweb_pegouts GROUP BY ROUND(amount, 1) ORDER BY count DESC, amount ASC");

// Reused peg-out addresses are a deanonymisation signal.
$topPegoutAddresses = mwebscan_cached($db, 'top_pegout_addresses',
    "SELECT address, COUNT(*) as count, SUM(amount) as total FROM mweb_pegouts WHERE address IS NOT NULL GROUP BY address HAVING count > 1 ORDER BY count DESC, total DESC LIMIT 100");

// --- Headline stats -------------------------------------------------------
// Guarded: a brand-new database has no scanner tables yet, so fall back to
// an empty state.
$peginCount = $pegoutCount = 0;
$peginTotal = $pegoutTotal = 0.0;
$latest = $syncHeight = null;
try {
    $peginCount = (int) $db->query("SELECT COUNT(*) FROM mweb_pegins")->fetchColumn();
    $pegoutCount = (int) $db->query("SELECT COUNT(*) FROM mweb_pegouts")->fetchColumn();
    $peginTotal = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM mweb_pegins")->fetchColumn();
    $pegoutTotal = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM mweb_pegouts")->fetchColumn();
    // Current MWEB supply is the latest block's hogaddr value.
    $latest = $db->query("
        SELECT block_height, supply FROM mweb_blocks ORDER BY block_height DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $syncHeight = $db->query("SELECT last_scanned_block FROM scan_progress")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tables not created yet; keep defaults.
}

$mwebTotalValue = $latest['supply'] ?? 'N/A';
$syncHeightValue = $syncHeight['last_scanned_block'] ?? ($latest['block_height'] ?? 'N/A');
$netFlow = $peginTotal - $pegoutTotal;

// --- Chain-analysis results (produced by mwebanalysis.py) ----------------
// These tables may not exist until the analysis pass has run; hide the
// analysis sections when they're missing.
$analysisAvailable = true;
$stats = [];
$topLinks = [];
$reuseSummary = [];
$entityFlows = [];
try {
    foreach ($db->query("SELECT key, value FROM analysis_stats") as $r) {
        $stats[$r['key']] = (float) $r['value'];
    }
    $topLinks = $db->query("
        SELECT l.pegout_txid, l.pegout_amount, l.pegout_address, l.pegout_entity, l.pegout_category,
               l.pegin_txid, l.pegin_amount, l.pegin_height, l.pegout_height,
               l.block_gap, l.candidate_count, l.confidence, l.reasons,
               s.risk_score
        FROM mweb_links l
        LEFT JOIN pegout_scores s ON s.txid = l.pegout_txid AND s.vout = l.pegout_vout
        WHERE l.confidence >= 0.5
        ORDER BY l.confidence DESC, l.block_gap ASC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    $entityFlows = $db->query("
        SELECT entity, category, direction, tx_count, total_amount
        FROM entity_flows
        ORDER BY total_amount DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $reuseSummary = $db->query("
        SELECT address, COUNT(DISTINCT pegin_txid) AS pegins,
               COUNT(DISTINCT pegout_txid) AS pegouts, SUM(pegout_amount) AS total
        FROM address_reuse_links
        GROUP BY address
        ORDER BY pegouts DESC, total DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $analysisAvailable = false;
}

// --- Privacy / anonymity-set lookup tool ---------------------------------
$lookupAmount = isset($_GET['amount']) && is_numeric($_GET['amount']) ? (float) $_GET['amount'] : null;
$lookup = ($lookupAmount !== null && $lookupAmount > 0)
    ? mwebscan_amount_privacy($db, $lookupAmount)
    : null;

$recommendations = mwebscan_cache_get($db, 'recommendations');

// Newest cache row, used for the freshness note.
$analysisUpdated = null;
try {
    $u = $db->query("SELECT MAX(updated) FROM cache")->fetchColumn();
    $analysisUpdated = $u ? (int) $u : null;
} catch (Exception $e) {
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <link rel="canonical" href="https://mwebscan.com/"/>
        <meta name="robots" content="index, follow">
        <meta name="description" content="MWEBscan: chain analysis & privacy intelligence for Litecoin's MimbleWimble Extension Block. Track peg-ins and peg-outs, see what leaks, and improve your privacy."/>
        <meta name="author" content="Tech1k">
        <title>MWEBscan - Litecoin MWEB Chain Analysis &amp; Privacy Intelligence</title>
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <meta property="og:title" content="MWEBscan - Litecoin MWEB Chain Analysis"/>
        <meta property="og:description" content="Chain analysis & privacy intelligence for Litecoin MWEB. Track peg-ins and peg-outs, see what leaks, and improve your privacy."/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/"/>
        <meta property="og:site_name" content="MWEBscan"/>
        <meta property="og:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <meta property="og:image:width" content="1200"/>
        <meta property="og:image:height" content="630"/>
        <meta property="og:image:alt" content="MWEBscan - open Litecoin MWEB explorer and privacy intelligence"/>
        <meta name="twitter:card" content="summary_large_image"/>
        <meta name="twitter:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main" style="text-align: center;">
            <h1><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="48" height="48" style="margin-right: 5px; vertical-align: middle;">MWEBscan</h1>
            <p style="max-width:760px; margin:6px auto 14px; color:var(--muted);">Public chain analysis of Litecoin's MimbleWimble Extension Block. Peg-ins and peg-outs are visible on the main chain: this site surfaces that data so you can see what leaks, blend into larger crowds, and improve your privacy. <a href="#faq">Learn more in the FAQs</a>.</p>
            <div class="stats">
                <div class="stat">
                    <div class="v"><?php echo is_numeric($mwebTotalValue) ? number_format((float) $mwebTotalValue, 2) : htmlspecialchars($mwebTotalValue, ENT_QUOTES); ?></div>
                    <div class="l">MWEB supply (LTC)</div>
                </div>
                <div class="stat">
                    <div class="v"><?php echo number_format($peginCount); ?></div>
                    <div class="l">Peg-ins &middot; <?php echo number_format($peginTotal, 0); ?> LTC in</div>
                </div>
                <div class="stat">
                    <div class="v"><?php echo number_format($pegoutCount); ?></div>
                    <div class="l">Peg-outs &middot; <?php echo number_format($pegoutTotal, 0); ?> LTC out</div>
                </div>
                <div class="stat">
                    <div class="v"><?php echo ($netFlow >= 0 ? '+' : ''); echo number_format($netFlow, 0); ?></div>
                    <div class="l">Net flow (LTC)</div>
                </div>
                <?php if ($analysisAvailable && !empty($stats)): ?>
                    <div class="stat warn">
                        <div class="v"><?php echo number_format((int) ($stats['linkable_pegouts'] ?? 0)); ?></div>
                        <div class="l">Linkable peg-outs &middot; <?php echo number_format((int) ($stats['high_confidence_links'] ?? 0)); ?> high-conf</div>
                    </div>
                    <div class="stat">
                        <div class="v"><?php echo number_format((int) ($stats['unique_amount_pegins'] ?? 0)); ?></div>
                        <div class="l">Uniquely-identifiable peg-ins</div>
                    </div>
                    <?php if (isset($stats['avg_privacy_score'])): ?>
                        <div class="stat">
                            <div class="v"><?php echo number_format((float) $stats['avg_privacy_score'], 0); ?><span style="font-size:0.55em;">/100</span></div>
                            <div class="l">Avg peg-in privacy</div>
                        </div>
                        <div class="stat risk">
                            <div class="v"><?php echo number_format((int) ($stats['high_risk_pegouts'] ?? 0)); ?></div>
                            <div class="l">High-risk peg-outs</div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($stats['pegouts_to_known_entity'])): ?>
                        <div class="stat">
                            <div class="v"><?php echo number_format((int) $stats['pegouts_to_known_entity']); ?></div>
                            <div class="l">Peg-outs to known entities</div>
                        </div>
                        <div class="stat">
                            <div class="v"><?php echo number_format((int) ($stats['labeled_addresses'] ?? 0)); ?></div>
                            <div class="l">Labelled addresses</div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <p style="color:var(--muted); font-size:0.82em;">Supply as of block <?php echo is_numeric($syncHeightValue) ? number_format((int) $syncHeightValue) : htmlspecialchars($syncHeightValue, ENT_QUOTES); ?>.<?php if ($analysisUpdated): ?> Analysis updated <?php $age = max(0, time() - $analysisUpdated); echo $age < 3600 ? max(1, round($age / 60)) . 'm' : round($age / 3600) . 'h'; ?> ago.<?php endif; ?></p>
        </div>
        <div class="search-box" style="max-width:680px;">
            <div style="background:var(--card); box-shadow:0 0 10px var(--shadow); border-radius:8px; padding:16px; text-align:left;">
                <form method="get" action="/trace" class="search-row">
                    <label for="q-trace">Trace an address or peg-in/peg-out txid</label>
                    <input id="q-trace" type="text" name="q" placeholder="ltc1q... or a transaction id">
                    <button type="submit" class="toggle-button">Trace</button>
                </form>
                <form method="get" action="/" id="privacyTool" class="search-row">
                    <label for="q-amount">Check how private a peg-in amount is</label>
                    <input id="q-amount" type="number" name="amount" step="0.00000001" min="0" placeholder="e.g. 1.0" value="<?php echo $lookupAmount !== null ? htmlspecialchars($lookupAmount, ENT_QUOTES) : ''; ?>">
                    <button type="submit" class="toggle-button">Check</button>
                </form>
            </div>
            <?php if ($lookup !== null): ?>
                <div style="background:var(--card); box-shadow:0 0 10px var(--shadow); padding:16px; margin-top:12px; border-radius:6px; text-align:left;">
                    <p style="margin:4px 0;"><strong><?php echo htmlspecialchars(number_format($lookup['amount'], 8), ENT_QUOTES); ?> LTC</strong></p>
                    <?php $ps = (int) $lookup['privacy_score']; $pc = $ps >= 70 ? 'var(--ok)' : ($ps >= 40 ? 'var(--warn)' : 'var(--risk)'); ?>
                    <p style="margin:4px 0;">Privacy score: <strong style="color:<?php echo $pc; ?>;"><?php echo $ps; ?>/100</strong> (<?php echo htmlspecialchars($lookup['rating'], ENT_QUOTES); ?>)</p>
                    <p style="margin:4px 0;">Anonymity set (rounded to <?php echo htmlspecialchars(number_format($lookup['rounded'], 1), ENT_QUOTES); ?> LTC): <strong><?php echo number_format($lookup['rounded_set']); ?></strong> peg-ins</p>
                    <p style="margin:4px 0;">Exact-amount matches: <strong><?php echo number_format($lookup['exact_set']); ?></strong> peg-ins</p>
                    <p style="margin:8px 0 0; color:var(--muted);"><?php echo htmlspecialchars($lookup['advice'], ENT_QUOTES); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($recommendations && !empty($recommendations['best_pegin_amounts'])): ?>
        <div id="recommendations" style="max-width:760px; margin:24px auto; background:var(--card); box-shadow:0 0 10px var(--shadow); border-radius:8px; padding:18px;">
            <h2 class="section-title" style="margin-top:0;">Privacy Recommendations (live)</h2>
            <p style="text-align:center; color:var(--muted);">Recommended peg-in amounts right now (largest crowds to hide in):</p>
            <p style="text-align:center;">
                <?php foreach ($recommendations['best_pegin_amounts'] as $b): ?>
                    <?php if ((float) $b['amount'] <= 0) continue; ?>
                    <a class="badge service" style="margin:3px; display:inline-block; text-decoration:none;" href="/trace?q=<?php echo urlencode($b['amount']); ?>">
                        <?php echo htmlspecialchars(number_format($b['amount'], 1), ENT_QUOTES); ?> LTC
                        <span style="opacity:0.7;">(<?php echo number_format($b['anonymity_set']); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </p>
            <?php if (!empty($recommendations['best_pegout_amounts'])): ?>
            <p style="text-align:center; color:var(--muted);">Recommended peg-out amounts right now (largest crowds to hide in):</p>
            <p style="text-align:center;">
                <?php foreach ($recommendations['best_pegout_amounts'] as $b): ?>
                    <?php if ((float) $b['amount'] <= 0) continue; ?>
                    <a class="badge service" style="margin:3px; display:inline-block; text-decoration:none;" href="/trace?q=<?php echo urlencode($b['amount']); ?>">
                        <?php echo htmlspecialchars(number_format($b['amount'], 1), ENT_QUOTES); ?> LTC
                        <span style="opacity:0.7;">(<?php echo number_format($b['anonymity_set']); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>
            <ul style="color:var(--text-soft); max-width:640px; margin:10px auto;">
                <?php foreach (($recommendations['notes'] ?? []) as $note): ?>
                    <li style="margin:4px 0;"><?php echo htmlspecialchars($note, ENT_QUOTES); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <nav style="text-align:center; margin:18px auto; font-size:0.9em; color:var(--muted);">
            Jump to:
            <a href="#pegin-amounts">Peg-in amounts</a> &middot;
            <a href="#pegout-amounts">Peg-out amounts</a>
            <?php if (!empty($topLinks)): ?> &middot; <a href="#linkable">Linkable peg-outs</a><?php endif; ?>
            <?php if (!empty($entityFlows)): ?> &middot; <a href="#entity-flows">Entity flows</a><?php endif; ?>
            &middot; <a href="#faq">FAQ</a>
        </nav>
        <div class="filters">
            <p style="margin:0 0 6px; color:var(--muted); font-size:0.9em;">Filter the peg-in amount tables below:</p>
            <label for="minAmount">Min LTC:</label>
            <input type="number" id="minAmount" step="0.01" min="0">
            <label for="maxAmount">Max LTC:</label>
            <input type="number" id="maxAmount" step="0.01" min="0">
            <label for="minOccurrences">Min Occurrences:</label>
            <input type="number" id="minOccurrences" min="1">
        </div>
        <h2 class="section-title" id="pegin-amounts">Common (Rounded) Peg-In Amounts</h2>
        <table id="standardizedTable">
            <thead>
                <tr>
                    <th scope="col">Amount (LTC)</th>
                    <th scope="col">Occurrences</th>
                </tr>
            </thead>
            <tbody id="standardizedMainBody">
                <?php foreach ($standardizedPegins as $row): ?>
                    <?php if ($row['count'] >= 300 && $row['amount'] != 0.0): ?>
                        <tr>
                            <td class="amount"><?php echo htmlspecialchars(number_format($row['amount'], 1), ENT_QUOTES); ?></td>
                            <td class="count"><?php echo htmlspecialchars($row['count'], ENT_QUOTES); ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="section-title">
            <button id="toggleRareStandardized" class="toggle-button">Show Low Occurrence (<300 count) Peg-Ins</button>
        </div>
        <div id="rareStandardizedContainer" style="display: none;">
            <table id="rareStandardizedTable">
                <thead>
                    <tr>
                        <th>Amount (LTC)</th>
                        <th>Occurrences</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($standardizedPegins as $row): ?>
                        <?php if ($row['count'] < 300 && $row['amount'] != 0.0): ?>
                            <tr>
                                <td class="amount"><?php echo htmlspecialchars(number_format($row['amount'], 1), ENT_QUOTES); ?></td>
                                <td class="count"><?php echo htmlspecialchars($row['count'], ENT_QUOTES); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="section-title">
            <button id="toggleRandomTable" class="toggle-button">Show Unique (non-rounded) Peg-Ins</button>
        </div>
        <div id="randomTableContainer" style="display: none;">
            <p style="text-align:center; color:var(--muted); font-size:0.85em;">Showing up to the 2,000 most frequent unique amounts.</p>
            <table id="randomTable">
                <thead>
                    <tr>
                        <th>Amount (LTC)</th>
                        <th>Occurrences</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($randomPegins as $row): ?>
                        <tr>
                            <td class="amount"><?php echo htmlspecialchars(number_format($row['amount'], 8), ENT_QUOTES); ?></td>
                            <td class="count"><?php echo htmlspecialchars($row['count'], ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h2 class="section-title" id="pegout-amounts">Common (Rounded) Peg-Out Amounts</h2>
        <table id="standardizedPegoutTable">
            <thead>
                <tr>
                    <th scope="col">Amount (LTC)</th>
                    <th scope="col">Occurrences</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($standardizedPegouts as $row): ?>
                    <?php if ($row['count'] >= 300 && $row['amount'] != 0.0): ?>
                        <tr>
                            <td class="amount"><?php echo htmlspecialchars(number_format($row['amount'], 1), ENT_QUOTES); ?></td>
                            <td class="count"><?php echo htmlspecialchars($row['count'], ENT_QUOTES); ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">
            <button id="toggleRarePegout" class="toggle-button">Show Low Occurrence (<300 count) Peg-Outs</button>
        </div>
        <div id="rarePegoutContainer" style="display: none;">
            <table id="rarePegoutTable">
                <thead>
                    <tr>
                        <th>Amount (LTC)</th>
                        <th>Occurrences</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($standardizedPegouts as $row): ?>
                        <?php if ($row['count'] < 300 && $row['amount'] != 0.0): ?>
                            <tr>
                                <td class="amount"><?php echo htmlspecialchars(number_format($row['amount'], 1), ENT_QUOTES); ?></td>
                                <td class="count"><?php echo htmlspecialchars($row['count'], ENT_QUOTES); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section-title">
            <button id="togglePegoutAddresses" class="toggle-button">Show Most-Reused Peg-Out Addresses</button>
        </div>
        <div id="pegoutAddressContainer" style="display: none;">
            <p style="text-align: center; color:var(--muted); max-width: 700px; margin: 0 auto;">
                Addresses that received more than one peg-out. Reusing a peg-out address (especially one already linked to your public-chain identity) is one of the easiest ways to undo MWEB's privacy.
            </p>
            <table id="pegoutAddressTable">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Peg-Outs</th>
                        <th>Total (LTC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topPegoutAddresses as $row): ?>
                        <tr>
                            <td style="word-break: break-all; font-family: monospace; text-align: left;"><?php echo htmlspecialchars($row['address'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($row['count'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['total'], 4), ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($analysisAvailable && !empty($topLinks)): ?>
        <h2 class="section-title" id="linkable">Linkable Peg-Outs (Round-Trip Analysis)</h2>
        <p style="text-align:center; color:var(--muted); max-width:760px; margin:0 auto;">
            Peg-outs whose amount matches an earlier peg-in closely enough, with a small enough anonymity set, to be a likely round trip through MWEB. Higher confidence = easier to deanonymise. This is exactly the kind of analysis to defend against: use common, rounded amounts and let coins mix before pegging out.
        </p>
        <p style="text-align:center;">
            <a class="toggle-button" href="/api.php?endpoint=links&amp;limit=500&amp;format=csv">Export CSV</a>
            <a class="toggle-button" href="/api.php?endpoint=links&amp;limit=500" target="_blank" rel="noopener">Export JSON</a>
        </p>
        <table id="linksTable">
            <thead>
                <tr>
                    <th>Confidence</th>
                    <th>AML Risk</th>
                    <th>Peg-Out</th>
                    <th>Destination</th>
                    <th>Matched Peg-In</th>
                    <th>Gap (blocks)</th>
                    <th>Anon. set</th>
                    <th>Why</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topLinks as $link): ?>
                    <?php $reasons = json_decode($link['reasons'], true) ?: []; ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(number_format($link['confidence'] * 100, 1), ENT_QUOTES); ?>%</strong></td>
                        <td>
                            <?php if (isset($link['risk_score'])): ?>
                                <?php $rs = (int) $link['risk_score']; $rc = $rs >= 70 ? 'var(--risk)' : ($rs >= 40 ? 'var(--warn)' : 'var(--muted)'); ?>
                                <strong style="color:<?php echo $rc; ?>;"><?php echo $rs; ?></strong>
                            <?php else: ?>
                                <span style="color:var(--muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars(number_format($link['pegout_amount'], 8), ENT_QUOTES); ?> LTC<br/>
                            <a href="/trace?q=<?php echo urlencode($link['pegout_txid']); ?>" style="font-family:monospace; font-size:0.8em;"><?php echo htmlspecialchars(substr($link['pegout_txid'], 0, 12), ENT_QUOTES); ?>...</a>
                        </td>
                        <td style="word-break:break-all;">
                            <?php if (!empty($link['pegout_address'])): ?>
                                <a href="/trace?q=<?php echo urlencode($link['pegout_address']); ?>" style="font-family:monospace; font-size:0.8em;"><?php echo htmlspecialchars(substr($link['pegout_address'], 0, 18), ENT_QUOTES); ?>...</a>
                                <?php if (!empty($link['pegout_entity'])): ?>
                                    <br/><strong><?php echo htmlspecialchars($link['pegout_entity'], ENT_QUOTES); ?></strong>
                                    <span style="font-size:0.8em; color:var(--muted);">(<?php echo htmlspecialchars($link['pegout_category'], ENT_QUOTES); ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars(number_format($link['pegin_amount'], 8), ENT_QUOTES); ?> LTC<br/>
                            <a href="/trace?q=<?php echo urlencode($link['pegin_txid']); ?>" style="font-family:monospace; font-size:0.8em;"><?php echo htmlspecialchars(substr($link['pegin_txid'], 0, 12), ENT_QUOTES); ?>...</a>
                        </td>
                        <td><?php echo htmlspecialchars(number_format($link['block_gap']), ENT_QUOTES); ?></td>
                        <td><?php echo htmlspecialchars($link['candidate_count'], ENT_QUOTES); ?></td>
                        <td style="text-align:left; font-size:0.85em; color:var(--muted);"><?php echo htmlspecialchars(implode('; ', $reasons), ENT_QUOTES); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($analysisAvailable && !empty($reuseSummary)): ?>
        <div class="section-title">
            <button id="toggleReuse" class="toggle-button">Show Address-Reuse Links (Public Identity <-> MWEB Exit)</button>
        </div>
        <div id="reuseContainer" style="display:none;">
            <p style="text-align:center; color:var(--muted); max-width:760px; margin:0 auto;">
                Addresses that both <em>funded a peg-in</em> and later <em>received a peg-out</em>. Reusing a public-chain address on both sides of MWEB directly ties your entry and exit together: the single strongest deanonymisation signal.
            </p>
            <table id="reuseTable">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Peg-Ins Funded</th>
                        <th>Peg-Outs Received</th>
                        <th>Total Out (LTC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reuseSummary as $row): ?>
                        <tr>
                            <td style="word-break:break-all; font-family:monospace; text-align:left;"><?php echo htmlspecialchars($row['address'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($row['pegins'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars($row['pegouts'], ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars(number_format($row['total'], 4), ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($analysisAvailable && !empty($entityFlows)): ?>
        <h2 class="section-title" id="entity-flows">Entity Flows (Known Exchanges &amp; Services)</h2>
        <p style="text-align:center; color:var(--muted); max-width:760px; margin:0 auto;">
            Peg activity tied to labelled entities. <strong>Pegging out straight to a KYC exchange</strong> - or funding a peg-in from one - links your MWEB activity to an identity that knows who you are. The privacy move is to keep entry and exit away from labelled services.
        </p>
        <table id="entityFlowTable">
            <thead>
                <tr>
                    <th>Entity</th>
                    <th>Category</th>
                    <th>Direction</th>
                    <th>Transactions</th>
                    <th>Total (LTC)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entityFlows as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['entity'], ENT_QUOTES); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?></td>
                        <td><?php echo $row['direction'] === 'pegout' ? 'Peg-out -> entity' : 'Entity -> peg-in'; ?></td>
                        <td><?php echo number_format($row['tx_count']); ?></td>
                        <td><?php echo htmlspecialchars(number_format($row['total_amount'], 4), ENT_QUOTES); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <section id="faq">
            <div class="maincol">
                <hr/>
                <h2>FAQs</h2>
                <h3>What is this site?</h3>
                <p>This site displays commonly used peg-in amounts for Litecoin's MimbleWimble Extension Block (MWEB). Peg-ins are public events on the Litecoin blockchain where coins are moved into the private MWEB sidechain.</p>
                <h3>What is MWEB?</h3>
                <p>MWEB (MimbleWimble Extension Block) is an optional privacy and scalability upgrade for Litecoin. It allows users to send and receive confidential transactions by moving coins into a separate sidechain within the Litecoin blockchain where amounts and addresses are hidden from public view.</p>
                <p>To learn more about MWEB, check out the <a href="https://litecoin.com/learning-center/litecoin-and-mweb-what-it-is-and-how-to-use-it" target="_blank" rel="noopener noreferrer">official MWEB overview</a>.</p>
                <h3>How do common peg-in amounts help with privacy?</h3>
                <p>Using common peg-in amounts makes it harder for outside observers to link specific peg-ins to individual users. If everyone uses similar amounts when moving funds into MWEB, it becomes more difficult to distinguish between different transactions, improving the overall obfuscation of the network.</p>
                <h3>You mention obfuscation a lot, what about increasing privacy?</h3>
                <p>While obfuscation is a part of privacy, it's not everything. To increase your privacy before pegging-in, you should use a new address and receive coins not linked to you on the public chain. When you want to move back to the main chain, you should move your coins in MWEB at least once to "mix" your coins before pegging-out to increase your privacy and if possible, not peg-out to the same address your used to peg-in with.</p>
                <h3>I thought MWEB was private - how can you see these peg-in amounts?</h3>
                <p>While transactions inside MWEB are private, the act of pegging coins into <em>and out of</em> MWEB happens on the regular Litecoin blockchain and is visible. A peg-in shows the amount being transferred into MWEB; a peg-out shows the amount and destination address coming back out. This site tracks those public events and correlates them; it cannot see anything that happens <em>inside</em> MWEB.</p>
                <h3>How can you link a peg-out to a peg-in if MWEB is private?</h3>
                <p>We can't see inside MWEB, so these links are <strong>heuristic, not proof</strong>. They are inferred entirely from public-chain data: matching amounts, timing, anonymity-set size, and reused addresses. A high confidence score means a peg-out <em>looks</em> like a round trip from an earlier peg-in: exactly the kind of inference a blockchain-surveillance firm would make. We publish it so you can see what leaks and avoid it: use common rounded amounts, let coins mix inside MWEB before pegging out, wait before pegging out, and never reuse an address across a peg-in and a peg-out.</p>
                <h3>Can I peg-out back to regular Litecoin?</h3>
                <p>Yes! You can peg-out from MWEB back to the regular Litecoin main chain at any time. When you peg-out, the transaction amount and recipient address are again visible on the public blockchain, but your activity while inside MWEB remains private.</p>
                <h3>Is there a fee for pegging into or out of MWEB?</h3>
                <p>Yes, just like any Litecoin transaction, peg-in and peg-out transactions require a standard network fee to be processed by miners. The fee is usually small, but it depends on network conditions and the size of your transaction.</p>
                <h3>How can I support this site?</h3>
                <p>Donations help fund development. See the <a href="/donate">donate page</a> for Litecoin and MWEB addresses (and OpenAlias).</p>
            </div>
        </section>
        <?php require __DIR__ . '/lib/footer.php'; ?>
        <script>
            // Collapse long tables to a few rows with a "Show all" toggle. Respects
            // rows hidden by the amount filter so the two compose.
            function truncateTable(tableId, limit) {
                var table = document.getElementById(tableId);
                if (!table || !table.tBodies.length) return function () {};
                var rows = Array.prototype.slice.call(table.tBodies[0].rows);
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "toggle-button";
                btn.style.display = "none";
                var wrap = document.createElement("div");
                wrap.style.cssText = "text-align:center; margin:6px 0 24px;";
                wrap.appendChild(btn);
                table.parentNode.insertBefore(wrap, table.nextSibling);
                var expanded = false;
                function apply() {
                    var shown = 0, hidden = 0;
                    rows.forEach(function (r) {
                        if (r.classList.contains("filter-hidden")) { r.classList.remove("trunc-hidden"); return; }
                        if (!expanded && shown >= limit) { r.classList.add("trunc-hidden"); hidden++; }
                        else { r.classList.remove("trunc-hidden"); shown++; }
                    });
                    if (expanded || hidden > 0) {
                        btn.style.display = "inline-block";
                        btn.textContent = expanded ? "Show less" : ("Show all (" + hidden + " more)");
                    } else {
                        btn.style.display = "none";
                    }
                }
                btn.addEventListener("click", function () { expanded = !expanded; apply(); });
                apply();
                return apply;
            }

            // Page long tables (Prev/Next) so expanding one does not dump
            // hundreds of rows at once. Pages only the rows the amount filter
            // leaves visible, via inline display so it composes with .filter-hidden.
            function paginateTable(tableId, pageSize) {
                var table = document.getElementById(tableId);
                if (!table || !table.tBodies.length) return { render: function () {} };
                var allRows = Array.prototype.slice.call(table.tBodies[0].rows);

                var bar = document.createElement("div");
                bar.style.cssText = "text-align:center; margin:6px 0 24px;";
                var prev = document.createElement("button");
                prev.type = "button"; prev.className = "toggle-button"; prev.textContent = "Prev";
                var next = document.createElement("button");
                next.type = "button"; next.className = "toggle-button"; next.textContent = "Next";
                var label = document.createElement("span");
                label.style.cssText = "margin:0 10px; color:var(--muted); font-size:0.9em;";
                bar.appendChild(prev); bar.appendChild(label); bar.appendChild(next);
                table.parentNode.insertBefore(bar, table.nextSibling);

                var page = 0;
                function render() {
                    var rows = allRows.filter(function (r) { return !r.classList.contains("filter-hidden"); });
                    var pages = Math.max(1, Math.ceil(rows.length / pageSize));
                    if (page > pages - 1) page = pages - 1;
                    if (page < 0) page = 0;
                    var start = page * pageSize, end = start + pageSize;
                    rows.forEach(function (r, i) { r.style.display = (i >= start && i < end) ? "" : "none"; });
                    label.textContent = "Page " + (page + 1) + " / " + pages + " (" + rows.length + " rows)";
                    prev.disabled = page === 0;
                    next.disabled = page >= pages - 1;
                    prev.style.opacity = prev.disabled ? "0.45" : "";
                    next.style.opacity = next.disabled ? "0.45" : "";
                    bar.style.display = rows.length > pageSize ? "" : "none";
                }
                prev.addEventListener("click", function () { page = page - 1; render(); });
                next.addEventListener("click", function () { page = page + 1; render(); });
                render();
                return { render: render };
            }

            var reapplyStd = truncateTable("standardizedTable", 12);
            truncateTable("standardizedPegoutTable", 12);
            truncateTable("entityFlowTable", 12);

            // Big expandable tables get pagination instead of a dump-it-all toggle.
            var randomPager = paginateTable("randomTable", 50);
            paginateTable("rareStandardizedTable", 50);
            paginateTable("rarePegoutTable", 50);
            paginateTable("pegoutAddressTable", 50);
            paginateTable("reuseTable", 50);
            paginateTable("linksTable", 25);

            var standardizedTableRows = document.querySelectorAll("#standardizedTable tbody tr");
            var randomTableRows = document.querySelectorAll("#randomTable tbody tr");
            var minAmountInput = document.getElementById("minAmount");
            var maxAmountInput = document.getElementById("maxAmount");
            var minOccurrencesInput = document.getElementById("minOccurrences");

            function filterTables() {
                var minAmount = parseFloat(minAmountInput.value) || 0;
                var maxAmount = parseFloat(maxAmountInput.value) || Infinity;
                var minOccurrences = parseInt(minOccurrencesInput.value) || 1;
                function f(rows) {
                    rows.forEach(function (row) {
                        var amount = parseFloat(row.querySelector(".amount").textContent);
                        var count = parseInt(row.querySelector(".count").textContent);
                        var match = amount >= minAmount && amount <= maxAmount && count >= minOccurrences;
                        row.classList.toggle("filter-hidden", !match);
                    });
                }
                f(standardizedTableRows);
                f(randomTableRows);
                reapplyStd();
                randomPager.render();
            }

            minAmountInput.addEventListener("input", filterTables);
            maxAmountInput.addEventListener("input", filterTables);
            minOccurrencesInput.addEventListener("input", filterTables);

            filterTables();

            // Collapsible data tables. Wires aria-expanded / aria-controls for
            // screen readers.
            [
                ["toggleRandomTable", "randomTableContainer", "Unique (non-rounded) Peg-Ins"],
                ["toggleRareStandardized", "rareStandardizedContainer", "Low Occurrence (<300 count) Peg-Ins"],
                ["toggleRarePegout", "rarePegoutContainer", "Low Occurrence (<300 count) Peg-Outs"],
                ["togglePegoutAddresses", "pegoutAddressContainer", "Most-Reused Peg-Out Addresses"],
                ["toggleReuse", "reuseContainer", "Address-Reuse Links (Public Identity <-> MWEB Exit)"],
            ].forEach(function (cfg) {
                var btn = document.getElementById(cfg[0]);
                var box = document.getElementById(cfg[1]);
                if (!btn || !box) return;
                btn.setAttribute("aria-controls", cfg[1]);
                btn.setAttribute("aria-expanded", box.style.display !== "none");
                btn.addEventListener("click", function () {
                    var show = box.style.display === "none";
                    box.style.display = show ? "block" : "none";
                    btn.setAttribute("aria-expanded", show);
                    btn.textContent = (show ? "Hide " : "Show ") + cfg[2];
                });
            });
        </script>
    </body>
</html>
