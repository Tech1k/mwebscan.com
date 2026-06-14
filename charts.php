<?php
require __DIR__ . '/lib/db.php';

$db = mwebscan_db();

$series = mwebscan_cached($db, 'timeseries_daily',
    "SELECT CAST(block_time/86400 AS INT) AS day, MAX(block_height) AS height, AVG(supply) AS supply,
            SUM(pegin_amount) AS pegin, SUM(pegout_amount) AS pegout,
            SUM(pegin_count) AS pegins, SUM(pegout_count) AS pegouts
     FROM mweb_blocks WHERE block_time IS NOT NULL GROUP BY day ORDER BY day");

$dates   = array_map(fn($r) => date('M j, Y', (int)$r['day'] * 86400), $series);
$supply  = array_map(fn($r) => (float)($r['supply'] ?? 0), $series);
$netflow = array_map(fn($r) => (float)($r['pegin'] ?? 0) - (float)($r['pegout'] ?? 0), $series);
$pegins  = array_map(fn($r) => (int)($r['pegins'] ?? 0), $series);
$pegouts = array_map(fn($r) => (int)($r['pegouts'] ?? 0), $series);

/**
 * Render a dependency-free SVG line chart inside a titled card. Per-point
 * geometry, values and dates are embedded as JSON for the hover handler below.
 */
function render_chart($title, $values, $dates, $cls, $unit, $decimals, $fill = false)
{
    $title_esc = htmlspecialchars($title, ENT_QUOTES);
    $n = count($values);
    if ($n < 2) {
        return '<div class="chart-card"><div class="chart-head"><span class="chart-title">' . $title_esc
            . '</span></div><p class="chart-empty">Not enough data yet for this chart.</p></div>';
    }

    $w = 900; $h = 280; $pL = 66; $pR = 20; $pT = 24; $pB = 36;
    $plotW = $w - $pL - $pR; $plotH = $h - $pT - $pB;
    $vals = array_values($values);
    $min = min($vals); $max = max($vals); $range = ($max - $min) ?: 1;

    $xat = fn($i) => $pL + ($i / ($n - 1)) * $plotW;
    $yat = fn($v) => $pT + (1 - (($v - $min) / $range)) * $plotH;

    $pts = []; $jsPts = [];
    foreach ($vals as $i => $v) {
        $x = round($xat($i), 1); $y = round($yat($v), 1);
        $pts[] = "$x,$y";
        $jsPts[] = [$x, $y, $v, $dates[$i]];
    }
    $poly = implode(' ', $pts);

    // Horizontal gridlines with value labels on the Y axis.
    $grid = '';
    $ticks = 4;
    for ($t = 0; $t <= $ticks; $t++) {
        $val = $min + $range * $t / $ticks;
        $y = round($yat($val), 1);
        $grid .= '<line x1="' . $pL . '" y1="' . $y . '" x2="' . ($w - $pR) . '" y2="' . $y
            . '" stroke="#eef0f4" stroke-width="1"/>';
        $grid .= '<text x="' . ($pL - 8) . '" y="' . ($y + 3) . '" font-size="13" fill="#6b7280" text-anchor="end">'
            . number_format($val, $decimals) . '</text>';
    }

    // Date labels at start / middle / end of the X axis.
    $xl = '';
    $mid = intdiv($n - 1, 2);
    foreach ([[0, 'start'], [$mid, 'middle'], [$n - 1, 'end']] as [$i, $anchor]) {
        $x = round($xat($i), 1);
        if ($anchor === 'start') { $x = $pL; }
        if ($anchor === 'end') { $x = $w - $pR; }
        $xl .= '<text x="' . $x . '" y="' . ($h - 12) . '" font-size="13" fill="#6b7280" text-anchor="' . $anchor . '">'
            . htmlspecialchars($dates[$i], ENT_QUOTES) . '</text>';
    }

    $latest = end($vals);
    $sign = ($unit === 'LTC' && $latest > 0 && strpos($title, 'Flow') !== false) ? '+' : '';

    $card = '<div class="chart-card"><div class="chart-head"><span class="chart-title">' . $title_esc
        . '</span><span class="chart-latest cl-' . $cls . '">' . $sign
        . number_format($latest, $decimals) . ' <span class="chart-unit">' . htmlspecialchars($unit, ENT_QUOTES)
        . '</span></span></div>';

    // The line/area/marker inherit the cl-* class colour via currentColor, so they
    // brighten on dark automatically (cl-* maps to the themed --accent/--ok/--warn).
    $svg = '<svg class="chart-svg cl-' . $cls . '" viewBox="0 0 ' . $w . ' ' . $h . '"'
        . " data-points='" . json_encode($jsPts) . "'"
        . ' data-unit="' . htmlspecialchars($unit, ENT_QUOTES) . '" data-decimals="' . $decimals . '">';
    $svg .= $grid;
    if ($fill) {
        $svg .= '<polygon points="' . $pL . ',' . ($h - $pB) . ' ' . $poly . ' ' . ($w - $pR) . ',' . ($h - $pB)
            . '" fill="currentColor" fill-opacity="0.13"/>';
    }
    $svg .= '<polyline points="' . $poly . '" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linejoin="round"/>';
    $svg .= $xl;
    $svg .= '<g class="chart-hover" style="display:none">'
        . '<line class="chart-cross" y1="' . $pT . '" y2="' . ($h - $pB)
        . '" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>'
        . '<circle class="chart-dot" r="4.5" fill="currentColor" stroke="var(--card)" stroke-width="2"/></g>';
    $svg .= '<rect class="chart-overlay" x="' . $pL . '" y="' . $pT . '" width="' . $plotW . '" height="'
        . $plotH . '" fill="transparent"/>';
    $svg .= '</svg></div>';

    return $card . $svg;
}

$currentSupply = $supply ? end($supply) : 0;
$totPeginAmt   = array_sum(array_column($series, 'pegin'));
$totPegoutAmt  = array_sum(array_column($series, 'pegout'));
$totPegins     = array_sum($pegins);
$totPegouts    = array_sum($pegouts);
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="description" content="Litecoin MWEB over time: supply, net flow and peg-in/peg-out activity, charted daily with interactive tooltips.">
        <link rel="canonical" href="https://mwebscan.com/charts.php"/>
        <title>Charts &middot; MWEBscan</title>
        <meta property="og:title" content="MWEBscan Charts - Litecoin MWEB over time"/>
        <meta property="og:description" content="MWEB supply, net flow and peg activity charted over time."/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/charts.php"/>
        <meta property="og:site_name" content="MWEBscan"/>
        <meta property="og:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <meta property="og:image:width" content="1200"/>
        <meta property="og:image:height" content="630"/>
        <meta property="og:image:alt" content="MWEBscan - open Litecoin MWEB explorer and privacy intelligence"/>
        <meta name="twitter:card" content="summary_large_image"/>
        <meta name="twitter:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
        <style>
            .chart-summary { display:flex; flex-wrap:wrap; gap:12px; justify-content:center; max-width:920px; margin:0 auto 26px; }
            .chart-summary .csum { flex:1 1 150px; background:var(--card); border-radius:10px; box-shadow:0 1px 4px var(--shadow); padding:14px 16px; text-align:center; }
            .chart-summary .csum .v { font-size:1.35em; font-weight:700; color:var(--text); }
            .chart-summary .csum .l { font-size:0.78em; color:var(--muted); margin-top:3px; text-transform:uppercase; letter-spacing:0.03em; }
            .chart-card { background:var(--card); border-radius:10px; box-shadow:0 1px 4px var(--shadow); padding:14px 16px 6px; margin-bottom:22px; }
            .chart-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:2px; }
            .chart-title { font-weight:600; color:var(--text-soft); font-size:0.96em; }
            .chart-latest { font-weight:700; font-size:1.05em; }
            .chart-latest .chart-unit { font-size:0.7em; font-weight:600; color:var(--faint); }
            .chart-svg { width:100%; height:auto; display:block; touch-action:none; }
            /* Line/area colour per series; uses themed vars so it brightens on dark. */
            .cl-blue { color: var(--accent); }
            .cl-green { color: var(--ok); }
            .cl-orange { color: var(--warn); }
            /* CSS wins over the SVG presentation attributes, so axis grid + labels theme. */
            .chart-svg > line { stroke: var(--grid); }
            .chart-svg text { fill: var(--muted); }
            .chart-overlay { cursor:crosshair; }
            .chart-empty { color:var(--muted); text-align:center; padding:34px 0; }
            .chart-tip { position:absolute; z-index:60; background:#15171d; color:#fff; padding:6px 9px; border-radius:6px; font-size:12px; line-height:1.4; pointer-events:none; box-shadow:0 2px 10px rgba(0,0,0,0.28); white-space:nowrap; transform:translateY(-50%); }
            .chart-tip .d { color:#aeb4c2; }
        </style>
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main" style="text-align:center;">
            <h1><a href="/" style="text-decoration:none; color:inherit;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">MWEBscan</a></h1>
            <h2>MWEB over time: supply, flows and activity (daily). Hover any chart for exact values.</h2>
        </div>
        <div style="max-width:920px; margin:0 auto;">
            <?php if (empty($series)): ?>
                <p style="text-align:center; color:var(--muted);">No time-series data yet. Run the scanner and analysis pass first.</p>
            <?php else: ?>
                <div class="chart-summary">
                    <div class="csum"><div class="v"><?php echo number_format($currentSupply, 0); ?></div><div class="l">MWEB Supply (LTC)</div></div>
                    <div class="csum"><div class="v"><?php echo number_format($totPeginAmt, 0); ?></div><div class="l">Total Pegged In (LTC)</div></div>
                    <div class="csum"><div class="v"><?php echo number_format($totPegoutAmt, 0); ?></div><div class="l">Total Pegged Out (LTC)</div></div>
                    <div class="csum"><div class="v"><?php echo number_format($totPegins); ?> / <?php echo number_format($totPegouts); ?></div><div class="l">Peg-ins / Peg-outs</div></div>
                </div>

                <?php echo render_chart('MWEB Supply', $supply, $dates, 'blue', 'LTC', 0, true); ?>
                <?php echo render_chart('Daily Net Flow (peg-in minus peg-out)', $netflow, $dates, 'green', 'LTC', 1); ?>
                <?php echo render_chart('Daily Peg-in Count', $pegins, $dates, 'blue', 'peg-ins', 0); ?>
                <?php echo render_chart('Daily Peg-out Count', $pegouts, $dates, 'orange', 'peg-outs', 0); ?>

                <p style="text-align:center; color:var(--muted); font-size:0.85em; margin:8px 0 20px;">
                    <?php echo count($series); ?> days of data. Larger daily peg counts mean larger anonymity sets.
                </p>
            <?php endif; ?>
        </div>
        <?php require __DIR__ . '/lib/footer.php'; ?>

        <script>
        (function () {
            var tip = document.createElement('div');
            tip.className = 'chart-tip';
            tip.style.display = 'none';
            document.body.appendChild(tip);

            document.querySelectorAll('.chart-svg').forEach(function (svg) {
                var pts = JSON.parse(svg.getAttribute('data-points'));
                var unit = svg.getAttribute('data-unit');
                var dec = parseInt(svg.getAttribute('data-decimals'), 10);
                var hover = svg.querySelector('.chart-hover');
                var cross = svg.querySelector('.chart-cross');
                var dot = svg.querySelector('.chart-dot');
                var overlay = svg.querySelector('.chart-overlay');

                function fmt(v) {
                    return v.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec });
                }

                function move(clientX, pageX, pageY) {
                    var p = svg.createSVGPoint();
                    p.x = clientX; p.y = 0;
                    var loc = p.matrixTransform(svg.getScreenCTM().inverse());
                    var best = 0, bd = Infinity;
                    for (var i = 0; i < pts.length; i++) {
                        var d = Math.abs(pts[i][0] - loc.x);
                        if (d < bd) { bd = d; best = i; }
                    }
                    var pt = pts[best];
                    cross.setAttribute('x1', pt[0]); cross.setAttribute('x2', pt[0]);
                    dot.setAttribute('cx', pt[0]); dot.setAttribute('cy', pt[1]);
                    hover.style.display = '';
                    tip.style.display = 'block';
                    tip.innerHTML = '<strong>' + fmt(pt[2]) + ' ' + unit + '</strong><br><span class="d">' + pt[3] + '</span>';
                    // Flip left of the cursor if it would run off the right edge.
                    var left = pageX + 16;
                    if (left + tip.offsetWidth > window.innerWidth - 8) {
                        left = pageX - tip.offsetWidth - 16;
                    }
                    if (left < 4) { left = 4; }
                    tip.style.left = left + 'px';
                    tip.style.top = pageY + 'px';
                }

                function hide() { hover.style.display = 'none'; tip.style.display = 'none'; }

                overlay.addEventListener('mousemove', function (e) { move(e.clientX, e.pageX, e.pageY); });
                overlay.addEventListener('mouseleave', hide);
                overlay.addEventListener('touchmove', function (e) {
                    var t = e.touches[0];
                    move(t.clientX, t.pageX, t.pageY);
                    e.preventDefault();
                }, { passive: false });
                overlay.addEventListener('touchend', hide);
            });
        })();
        </script>
    </body>
</html>
