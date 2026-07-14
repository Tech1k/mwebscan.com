<?php
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/trace_engine.php';

$db = mwebscan_db();
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Resolve the block by height (all digits) or 64-hex hash.
$block = null;
if ($q !== '') {
    if (ctype_digit($q)) {
        $st = $db->prepare("SELECT * FROM mweb_blocks WHERE block_height = ?");
        $st->execute([(int) $q]);
        $block = $st->fetch(PDO::FETCH_ASSOC);
    } elseif (preg_match('/^[0-9a-fA-F]{64}$/', $q)) {
        $st = $db->prepare("SELECT * FROM mweb_blocks WHERE block_hash = ?");
        $st->execute([strtolower($q)]);
        $block = $st->fetch(PDO::FETCH_ASSOC);
    }
}

// Overlay (linkage / risk / entity / scores) when the analysis pass has run.
$pegouts = $pegins = [];
$analysis = $block && mwebscan_table_exists($db, 'mweb_links');
if ($block) {
    $h = (int) $block['block_height'];
    if ($analysis) {
        $st = $db->prepare("
            SELECT po.txid, po.vout, po.amount, po.address,
                   l.pegin_txid, l.confidence, s.risk_score, a.entity, a.category
            FROM mweb_pegouts po
            LEFT JOIN mweb_links l ON l.pegout_txid = po.txid AND l.pegout_vout = po.vout
            LEFT JOIN pegout_scores s ON s.txid = po.txid AND s.vout = po.vout
            LEFT JOIN address_attribution a ON a.address = po.address
            WHERE po.block_height = ? ORDER BY po.vout");
    } else {
        $st = $db->prepare("SELECT txid, vout, amount, address FROM mweb_pegouts WHERE block_height = ? ORDER BY vout");
    }
    $st->execute([$h]);
    $pegouts = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($analysis) {
        $st = $db->prepare("
            SELECT pi.txid, pi.vout, pi.amount, pi.source_address,
                   ps.privacy_score, ps.anonymity_set, a.entity, a.category
            FROM mweb_pegins pi
            LEFT JOIN pegin_scores ps ON ps.txid = pi.txid AND ps.vout = pi.vout
            LEFT JOIN address_attribution a ON a.address = pi.source_address
            WHERE pi.block_height = ? ORDER BY pi.vout");
    } else {
        $st = $db->prepare("SELECT txid, vout, amount, source_address FROM mweb_pegins WHERE block_height = ? ORDER BY vout");
    }
    $st->execute([$h]);
    $pegins = $st->fetchAll(PDO::FETCH_ASSOC);
}

function blk_amt($v)
{
    return htmlspecialchars(number_format((float) $v, 8), ENT_QUOTES) . ' ' . mwebscan_unit();
}
function blk_entity($ent, $cat)
{
    if (empty($ent)) {
        return '';
    }
    return ' <span class="badge ' . htmlspecialchars((string) $cat, ENT_QUOTES) . '">'
        . htmlspecialchars((string) $ent, ENT_QUOTES) . '</span>';
}
function blk_conf($c)
{
    if ($c === null) {
        return '<span style="color:var(--muted);">&mdash;</span>';
    }
    $c = (float) $c;
    $col = $c >= 0.9 ? 'var(--risk)' : ($c >= 0.7 ? 'var(--warn)' : 'var(--muted)');
    return '<strong style="color:' . $col . ';">' . htmlspecialchars(number_format($c * 100, 1), ENT_QUOTES) . '%</strong>';
}
function blk_addr_link($addr)
{
    if (empty($addr)) {
        return '<span style="color:var(--muted);">unknown</span>';
    }
    $a = htmlspecialchars($addr, ENT_QUOTES);
    return '<a href="/trace?q=' . urlencode($addr) . '" class="addr">' . htmlspecialchars(substr($addr, 0, 24), ENT_QUOTES) . '...</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="robots" content="noindex">
        <title><?php echo $block ? 'Block ' . (int) $block['block_height'] : 'Block'; ?> &middot; MWEBscan</title>
        <meta name="description" content="MWEB activity for a Litecoin block: peg-ins, peg-outs, round-trip links, and MWEB kernel/UTXO activity.">
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main" style="text-align:center;">
            <h1><a href="/" style="text-decoration:none; color:inherit;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">MWEBscan</a></h1>
            <h2><?php echo mweb_icon(); ?>MWEB activity for a block. Enter a block height or hash.</h2>
        </div>

        <div class="search-box">
            <form method="get" action="/block">
                <input type="text" name="q" aria-label="Block height or hash" placeholder="block height or 64-char hash" value="<?php echo htmlspecialchars($q, ENT_QUOTES); ?>" autofocus>
                <button type="submit" class="toggle-button">View</button>
            </form>
        </div>

        <?php if ($q === ''): ?>
            <p style="text-align:center; color:var(--muted);">Enter a block height or hash to see its MWEB peg-ins, peg-outs and round-trip links.</p>
        <?php elseif (!$block): ?>
            <p style="text-align:center; color:var(--muted);">No MWEB block found for <strong><?php echo htmlspecialchars($q, ENT_QUOTES); ?></strong>. It may be pre-activation, unknown, or not yet scanned.</p>
        <?php else: ?>
            <?php $h = (int) $block['block_height']; ?>
            <div class="node" style="max-width:760px; margin:0 auto 16px;">
                <h3 style="margin-top:0;">
                    <a href="/block/<?php echo $h - 1; ?>" title="previous block">&larr;</a>
                    Block <?php echo number_format($h); ?>
                    <a href="/block/<?php echo $h + 1; ?>" title="next block">&rarr;</a>
                </h3>
                <p style="margin:4px 0; word-break:break-all; font-family:monospace; font-size:0.85em;"><?php echo htmlspecialchars($block['block_hash'], ENT_QUOTES); ?></p>
                <p style="margin:4px 0; color:var(--muted);"><?php echo $block['block_time'] ? htmlspecialchars(date('M j, Y H:i', (int) $block['block_time']), ENT_QUOTES) . ' UTC' : ''; ?></p>
                <div class="stats" style="margin-top:10px;">
                    <div class="stat"><div class="v"><?php echo is_numeric($block['supply']) ? number_format((float) $block['supply'], 2) : 'N/A'; ?></div><div class="l"><?php echo mweb_icon(); ?>MWEB supply (<?php echo mwebscan_unit(); ?>)</div></div>
                    <?php if ($block['mweb_txos'] !== null): ?><div class="stat"><div class="v"><?php echo number_format((int) $block['mweb_txos']); ?></div><div class="l"><?php echo mweb_icon(); ?>MWEB UTXO set</div></div><?php endif; ?>
                    <?php if ($block['mweb_kernels'] !== null): ?><div class="stat"><div class="v"><?php echo number_format((int) $block['mweb_kernels']); ?></div><div class="l"><?php echo mweb_icon(); ?>MWEB txns (kernels)</div></div><?php endif; ?>
                    <div class="stat"><div class="v"><?php echo count($pegins); ?> / <?php echo count($pegouts); ?></div><div class="l">Peg-ins / Peg-outs</div></div>
                </div>
            </div>

            <?php if ($pegins): ?>
            <h2 class="section-title">Peg-ins (into MWEB)</h2>
            <table>
                <thead><tr><th>Txid</th><th>Amount</th><th>Funding source</th><?php if ($analysis): ?><th>Privacy</th><th>Anon. set</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($pegins as $pi): ?>
                    <tr>
                        <td><a class="tx" href="<?php echo htmlspecialchars(mwebscan_tx_url($pi['txid']), ENT_QUOTES); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(substr($pi['txid'], 0, 16), ENT_QUOTES); ?>...</a></td>
                        <td class="amount"><?php echo blk_amt($pi['amount']); ?></td>
                        <td style="word-break:break-all;"><?php echo blk_addr_link($pi['source_address'] ?? null) . blk_entity($pi['entity'] ?? null, $pi['category'] ?? null); ?></td>
                        <?php if ($analysis): ?>
                        <td><?php echo isset($pi['privacy_score']) && $pi['privacy_score'] !== null ? (int) $pi['privacy_score'] . '/100' : '&mdash;'; ?></td>
                        <td><?php echo isset($pi['anonymity_set']) && $pi['anonymity_set'] !== null ? number_format((int) $pi['anonymity_set']) : '&mdash;'; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($pegouts): ?>
            <h2 class="section-title">Peg-outs (out of MWEB)</h2>
            <table>
                <thead><tr><th>Txid</th><th>Amount</th><th>Destination</th><?php if ($analysis): ?><th>Linked peg-in</th><th>Conf.</th><th>AML risk</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($pegouts as $po): ?>
                    <tr>
                        <td><a class="tx" href="<?php echo htmlspecialchars(mwebscan_tx_url($po['txid']), ENT_QUOTES); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(substr($po['txid'], 0, 16), ENT_QUOTES); ?>...</a></td>
                        <td class="amount"><?php echo blk_amt($po['amount']); ?></td>
                        <td style="word-break:break-all;"><?php echo blk_addr_link($po['address'] ?? null) . blk_entity($po['entity'] ?? null, $po['category'] ?? null); ?></td>
                        <?php if ($analysis): ?>
                        <td><?php echo !empty($po['pegin_txid']) ? '<a href="/trace?q=' . urlencode($po['pegin_txid']) . '">' . htmlspecialchars(substr($po['pegin_txid'], 0, 16), ENT_QUOTES) . '...</a>' : '<span style="color:var(--muted);">unlinked</span>'; ?></td>
                        <td><?php echo blk_conf($po['confidence'] ?? null); ?></td>
                        <td><?php echo isset($po['risk_score']) && $po['risk_score'] !== null ? (int) $po['risk_score'] : '&mdash;'; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!$pegins && !$pegouts): ?>
                <p style="text-align:center; color:var(--muted);">No peg-ins or peg-outs in this block (MWEB activity here was internal only, if any).</p>
            <?php endif; ?>

            <p style="text-align:center; color:var(--muted); font-size:0.85em; margin-top:16px;">Links across MWEB are <strong>heuristic, not proof</strong>: inferred from public-chain data only.</p>
        <?php endif; ?>

        <?php require __DIR__ . '/lib/footer.php'; ?>
    </body>
</html>
