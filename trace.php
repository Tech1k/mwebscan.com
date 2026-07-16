<?php
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/trace_engine.php';

$db = mwebscan_db();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$trace = $q !== '' ? mwebscan_trace($db, $q) : null;
$chain = ($trace && $trace['type'] === 'address') ? mwebscan_follow($db, $q, 6) : [];

function conf_class($c)
{
    if ($c >= 0.9) return 'conf-high';
    if ($c >= 0.5) return 'conf-med';
    return 'conf-low';
}

function privacy_color($s)
{
    if ($s >= 70) return 'var(--ok)';
    if ($s >= 40) return 'var(--warn)';
    return 'var(--risk)';
}

function risk_color($s)
{
    if ($s >= 70) return 'var(--risk)';
    if ($s >= 40) return 'var(--warn)';
    return 'var(--ok)';
}

function tx_link($txid)
{
    $short = htmlspecialchars(substr($txid, 0, 14), ENT_QUOTES) . '...';
    return '<a class="tx" href="' . htmlspecialchars(mwebscan_tx_url($txid), ENT_QUOTES) . '" target="_blank" rel="noopener">' . $short . '</a>';
}

function trace_link($value, $label = null)
{
    $label = $label ?? $value;
    return '<a href="?q=' . urlencode($value) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
}

function entity_badge($attr)
{
    if (!$attr || empty($attr['entity'])) {
        return '';
    }
    $cat = htmlspecialchars($attr['category'] ?? 'other', ENT_QUOTES);
    $via = ($attr['via'] ?? '') === 'cluster' ? ' (cluster)' : '';
    return ' <span class="badge ' . $cat . '">' . htmlspecialchars($attr['entity'], ENT_QUOTES) . $via . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="robots" content="noindex">
        <title>Trace &middot; MWEBscan</title>
        <meta name="description" content="Trace Litecoin MWEB peg-ins and peg-outs and follow the public money trail.">
        <meta property="og:title" content="MWEBscan - Trace Litecoin MWEB activity"/>
        <meta property="og:description" content="Follow peg-ins, peg-outs and the public money trail around Litecoin MWEB."/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/trace"/>
        <meta property="og:site_name" content="MWEBscan"/>
        <meta property="og:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <meta property="og:image:width" content="1200"/>
        <meta property="og:image:height" content="630"/>
        <meta property="og:image:alt" content="MWEBscan - open Litecoin MWEB explorer and privacy intelligence"/>
        <meta name="twitter:card" content="summary_large_image"/>
        <meta name="twitter:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main" style="text-align:center;">
            <h1><a href="/" style="text-decoration:none; color:inherit;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">MWEBscan</a></h1>
            <h2>Follow the money across the MWEB hop. Enter a Litecoin address, a peg-in txid, or a peg-out txid.</h2>
        </div>

        <div class="search-box">
            <form method="get" action="/trace">
                <input type="text" name="q" aria-label="Litecoin address or transaction id to trace" placeholder="address / peg-in txid / peg-out txid" value="<?php echo htmlspecialchars($q, ENT_QUOTES); ?>" autofocus>
                <button type="submit" class="toggle-button">Trace</button>
            </form>
            <p style="color:var(--muted); font-size:0.85em;">Links across MWEB are <strong>heuristic, not proof</strong>: inferred from public-chain data only.</p>
        </div>

        <?php if ($trace === null): ?>
            <p style="text-align:center; color:var(--muted);">Nothing traced yet. Try an address or txid above, or browse the <a href="/">linkable peg-outs</a>.</p>
        <?php elseif ($trace['type'] === 'not_found'): ?>
            <p style="text-align:center; color:var(--muted);">No MWEB peg activity found for <strong><?php echo htmlspecialchars($q, ENT_QUOTES); ?></strong>. It may not be a peg-in/peg-out address or txid (or the chain hasn't been fully scanned/analysed yet).</p>
        <?php else: ?>

            <?php if ($trace['attribution'] && !empty($trace['attribution']['entity'])): ?>
                <p style="text-align:center;">Address attributed to <?php echo entity_badge($trace['attribution']); ?>
                    <?php if ($trace['cluster']): ?>
                        (part of a wallet cluster of <strong><?php echo count($trace['cluster']['members']); ?></strong> address(es)).
                    <?php endif; ?>
                </p>
            <?php elseif ($trace['cluster']): ?>
                <p style="text-align:center;">Part of a wallet cluster of <strong><?php echo count($trace['cluster']['members']); ?></strong> co-spending address(es).</p>
            <?php endif; ?>

            <?php if ($trace['type'] === 'amount' && $trace['amount_privacy']): ?>
                <?php $ap = $trace['amount_privacy']; $pc = $ap['privacy_score'] >= 70 ? 'var(--ok)' : ($ap['privacy_score'] >= 40 ? 'var(--warn)' : 'var(--risk)'); ?>
                <div class="node" style="max-width:640px; margin:0 auto 10px; text-align:center;">
                    <h3>Peg-in amount privacy: <?php echo htmlspecialchars(number_format($ap['amount'], 8), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></h3>
                    <p style="margin:4px 0;">Privacy score: <strong style="color:<?php echo $pc; ?>;"><?php echo (int) $ap['privacy_score']; ?>/100</strong> (<?php echo htmlspecialchars($ap['rating'], ENT_QUOTES); ?>)</p>
                    <p style="margin:4px 0;">Anonymity set: <strong><?php echo number_format($ap['rounded_set']); ?></strong> peg-ins (rounded) &middot; <strong><?php echo number_format($ap['exact_set']); ?></strong> exact</p>
                    <p style="margin:6px 0 0; color:var(--muted);"><?php echo htmlspecialchars($ap['advice'], ENT_QUOTES); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($trace['type'] === 'amount' && !empty($trace['pegout_amount_privacy'])): ?>
                <?php $op = $trace['pegout_amount_privacy']; $oc = $op['privacy_score'] >= 70 ? 'var(--ok)' : ($op['privacy_score'] >= 40 ? 'var(--warn)' : 'var(--risk)'); ?>
                <div class="node" style="max-width:640px; margin:0 auto 10px; text-align:center;">
                    <h3>Peg-out amount privacy: <?php echo htmlspecialchars(number_format($op['amount'], 8), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></h3>
                    <p style="margin:4px 0;">Exit privacy score: <strong style="color:<?php echo $oc; ?>;"><?php echo (int) $op['privacy_score']; ?>/100</strong> (<?php echo htmlspecialchars($op['rating'], ENT_QUOTES); ?>)</p>
                    <p style="margin:4px 0;">Exit anonymity set: <strong><?php echo number_format($op['exit_set']); ?></strong> peg-outs (rounded) &middot; <strong><?php echo number_format($op['exit_exact_set']); ?></strong> exact</p>
                    <p style="margin:4px 0;">Peg-ins this exit could link back to: <strong><?php echo number_format($op['matching_pegins']); ?></strong></p>
                    <p style="margin:6px 0 0; color:var(--muted);"><?php echo htmlspecialchars($op['advice'], ENT_QUOTES); ?></p>
                </div>
            <?php endif; ?>

            <div style="text-align:center; margin:14px;">
                <a class="toggle-button" href="/api.php?endpoint=trace&amp;q=<?php echo urlencode($q); ?>" target="_blank" rel="noopener">Download JSON</a>
                <a class="toggle-button" href="/api.php?endpoint=trace&amp;format=csv&amp;q=<?php echo urlencode($q); ?>">Download CSV</a>
            </div>

            <?php if (!empty($trace['pegins'])): ?>
                <h2 class="section-title">Peg-ins -> MWEB -> linked peg-outs</h2>
                <?php foreach ($trace['pegins'] as $pin): ?>
                    <div class="flow">
                        <div class="node">
                            <h3>Funding sources</h3>
                            <?php if (!empty($pin['inputs'])): ?>
                                <?php foreach (array_slice($pin['inputs'], 0, 6) as $in): ?>
                                    <div style="margin-bottom:4px;">
                                        <span class="addr"><?php echo trace_link($in['address'], substr($in['address'], 0, 18) . '...'); ?></span><?php echo entity_badge($in['attribution']); ?>
                                        <br/><span style="color:var(--muted);"><?php echo htmlspecialchars(number_format($in['value'], 4), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif (!empty($pin['source_address'])): ?>
                                <span class="addr"><?php echo trace_link($pin['source_address']); ?></span><?php echo entity_badge($pin['source_attribution']); ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">unknown (run scanner with source tracking)</span>
                            <?php endif; ?>
                        </div>
                        <div class="arrow">-></div>
                        <div class="node" style="border:2px solid var(--accent);">
                            <h3>Peg-in</h3>
                            <div class="amt"><?php echo htmlspecialchars(number_format($pin['amount'], 8), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></div>
                            <div>block <?php echo htmlspecialchars($pin['block_height'], ENT_QUOTES); ?></div>
                            <?php echo tx_link($pin['txid']); ?>
                            <?php if (!empty($pin['score'])): ?>
                                <div style="margin-top:6px;">Privacy: <strong style="color:<?php echo privacy_color($pin['score']['privacy_score']); ?>;"><?php echo (int) $pin['score']['privacy_score']; ?>/100</strong></div>
                            <?php endif; ?>
                        </div>
                        <div class="hop">
                            <div><?php echo mweb_icon(); ?>MWEB</div>
                            <div>-></div>
                            <div style="font-size:0.85em;">private hop</div>
                        </div>
                        <div class="node">
                            <h3>Linked peg-outs</h3>
                            <?php if (!empty($pin['links'])): ?>
                                <?php foreach ($pin['links'] as $lk): ?>
                                    <div style="margin-bottom:6px;">
                                        <span class="<?php echo conf_class($lk['confidence']); ?>"><?php echo htmlspecialchars(number_format($lk['confidence'] * 100, 1), ENT_QUOTES); ?>%</span>
                                        &middot; <?php echo htmlspecialchars(number_format($lk['pegout_amount'], 8), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?><br/>
                                        <?php echo $lk['pegout_address'] ? trace_link($lk['pegout_address'], substr($lk['pegout_address'], 0, 18) . '...') : ''; ?>
                                        <?php echo entity_badge(['entity' => $lk['pegout_entity'], 'category' => $lk['pegout_category']]); ?>
                                        <br/><?php echo tx_link($lk['pegout_txid']); ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">no confident peg-out match: good privacy here</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($trace['pegouts'])): ?>
                <h2 class="section-title">Peg-outs &amp; their linked peg-ins</h2>
                <?php foreach ($trace['pegouts'] as $pout): ?>
                    <div class="flow">
                        <div class="node">
                            <h3>Linked peg-in</h3>
                            <?php if (!empty($pout['links'])): ?>
                                <?php $lk = $pout['links'][0]; ?>
                                <span class="<?php echo conf_class($lk['confidence']); ?>"><?php echo htmlspecialchars(number_format($lk['confidence'] * 100, 1), ENT_QUOTES); ?>%</span>
                                &middot; <?php echo htmlspecialchars(number_format($lk['pegin_amount'], 8), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?><br/>
                                <?php echo tx_link($lk['pegin_txid']); ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">no confident peg-in match</span>
                            <?php endif; ?>
                        </div>
                        <div class="hop">
                            <div><?php echo mweb_icon(); ?>MWEB</div>
                            <div>-></div>
                            <div style="font-size:0.85em;">private hop</div>
                        </div>
                        <div class="node" style="border:2px solid var(--warn);">
                            <h3>Peg-out</h3>
                            <div class="amt"><?php echo htmlspecialchars(number_format($pout['amount'], 8), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></div>
                            <div>block <?php echo htmlspecialchars($pout['block_height'], ENT_QUOTES); ?></div>
                            <?php echo tx_link($pout['txid']); ?>
                            <?php if (!empty($pout['score'])): ?>
                                <div style="margin-top:6px;">AML risk: <strong style="color:<?php echo risk_color($pout['score']['risk_score']); ?>;"><?php echo (int) $pout['score']['risk_score']; ?>/100</strong></div>
                            <?php endif; ?>
                        </div>
                        <div class="arrow">-></div>
                        <div class="node">
                            <h3>Destination</h3>
                            <?php if (!empty($pout['address'])): ?>
                                <span class="addr"><?php echo trace_link($pout['address'], substr($pout['address'], 0, 22) . '...'); ?></span>
                                <?php echo entity_badge($pout['attribution']); ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">unknown</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (count($chain) > 1): ?>
                <h2 class="section-title">Multi-hop chain (follow the money)</h2>
                <p style="text-align:center; color:var(--muted); max-width:760px; margin:0 auto;">Following the coins across repeated peg cycles. Each arrow is one inferred MWEB crossing; multiply the per-hop confidences for the chain's overall strength.</p>
                <div class="flow">
                    <?php foreach ($chain as $i => $h): ?>
                        <?php if ($i > 0): ?><div class="arrow">-></div><?php endif; ?>
                        <div class="node">
                            <h3>Hop <?php echo $i + 1; ?></h3>
                            <div class="addr"><?php echo trace_link($h['from'], substr($h['from'], 0, 14) . '...'); ?></div>
                            <div>peg-in <?php echo htmlspecialchars(number_format($h['pegin']['amount'], 4), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></div>
                            <?php if ($h['pegout']): ?>
                                <div class="<?php echo conf_class($h['confidence']); ?>"><?php echo htmlspecialchars(number_format($h['confidence'] * 100, 1), ENT_QUOTES); ?>% -></div>
                                <div>peg-out <?php echo htmlspecialchars(number_format($h['pegout']['pegout_amount'], 4), ENT_QUOTES); ?> <?php echo mwebscan_unit(); ?></div>
                                <div class="addr"><?php echo $h['to'] ? trace_link($h['to'], substr($h['to'], 0, 14) . '...') : ''; ?><?php echo entity_badge($h['to_attribution']); ?></div>
                            <?php else: ?>
                                <div style="color:var(--muted);">no confident exit</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <?php require __DIR__ . '/lib/footer.php'; ?>
    </body>
</html>
