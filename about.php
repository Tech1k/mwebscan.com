<?php require __DIR__ . '/lib/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="description" content="Why MWEBscan exists: Litecoin MWEB peg-ins and peg-outs are public, so they can be correlated. We publish that analysis openly so users can see what leaks and protect their privacy.">
        <link rel="canonical" href="https://mwebscan.com/about"/>
        <title>About &middot; MWEBscan</title>
        <meta property="og:title" content="Why MWEBscan? - Litecoin MWEB privacy intelligence"/>
        <meta property="og:description" content="MWEB hides what happens inside it, but peg-ins and peg-outs are public. MWEBscan shows what leaks so you can stay private."/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/about"/>
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
        <div id="main">
            <h1 style="text-align:center;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">Why MWEBscan?</h1>

            <p style="font-size:1.1em; text-align:center; color:var(--muted); max-width:720px; margin:0 auto 10px;">
                MWEB is Litecoin's privacy layer. But the way coins <em>enter and leave</em> it is public, and public data can be correlated. MWEBscan shows you exactly how, so you can stay private.
            </p>

            <h2>The short version</h2>
            <p>The MimbleWimble Extension Block (MWEB) hides amounts and addresses <em>inside</em> it. What it can't hide is the <strong>doorway</strong>: every <strong>peg-in</strong> (coins moving into MWEB) and <strong>peg-out</strong> (coins coming back out) happens on the normal Litecoin blockchain, in the clear: amount, address, block and time all visible.</p>
            <p>That means a peg-out can sometimes be tied back to an earlier peg-in by matching amounts, timing, reused addresses, and known entities. A surveillance firm can do this quietly. <strong>MWEBscan does it in the open.</strong></p>

            <h2>One engine, two faces</h2>
            <p>The same analysis points two directions:</p>
            <ul>
                <li><strong>The privacy face</strong> (for you): "is this amount easy to single out? how big is my anonymity set? how do I blend in?" Check an amount, get a privacy score and live recommendations.</li>
                <li><strong>The forensic face</strong> (the thing to defend against): linkable peg-outs, entity attribution, risk scores: the deanonymisation a Chainalysis-style firm would run, published so it isn't a secret weapon.</li>
            </ul>
            <p>Publishing the surveillance <em>is</em> the privacy tool. You can't defend against an attack you can't see.</p>

            <h2>Who it's for</h2>
            <ul>
                <li><strong>Privacy-conscious users</strong>: pick amounts and timing that keep your anonymity set large before you peg in or out.</li>
                <li><strong>Wallets &amp; developers</strong>: integrate a client-side "is this peg safe?" check so users get nudged toward privacy (see the <a href="/api-docs">API</a>).</li>
                <li><strong>Researchers &amp; journalists</strong>: an open, transparent view of how MWEB actually gets used.</li>
                <li><strong>The simply curious</strong>: watch MWEB supply, flows and activity over time.</li>
            </ul>

            <h2>What it does (and doesn't) do</h2>
            <p>MWEBscan analyses <strong>only public Litecoin blockchain data</strong>. It cannot see anything inside MWEB; nobody can. Every link, attribution and score is a <strong>heuristic inference, never proof</strong>: a confidence estimate from public-side signals. Treat results as leads, not facts. See the <a href="/methodology">methodology</a> for exactly how each signal works and its limits.</p>

            <h2>Open by design</h2>
            <p>The code is open-source (AGPL-3.0). The point isn't to sell surveillance; it's to make the leak surface visible so the community can shrink it. The more people who use common amounts, mix inside MWEB, wait before pegging out, and avoid address reuse, the weaker every one of these heuristics becomes.</p>

            <p style="text-align:center; margin-top:28px;">
                <a class="toggle-button" href="/#privacyTool">Check an amount's privacy</a>
                <a class="toggle-button" href="/trace">Trace an address or txid</a>
            </p>
        </div>
        <?php require __DIR__ . '/lib/footer.php'; ?>
    </body>
</html>
