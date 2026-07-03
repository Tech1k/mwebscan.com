<?php require __DIR__ . '/lib/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="description" content="How MWEBscan analyses Litecoin MWEB peg-ins and peg-outs: the heuristics, scores, and their limitations.">
        <link rel="canonical" href="https://mwebscan.com/methodology"/>
        <meta property="og:title" content="MWEBscan Methodology"/>
        <meta property="og:description" content="How MWEBscan analyses Litecoin MWEB peg-ins and peg-outs: the heuristics, scores, and their limitations."/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/methodology"/>
        <meta property="og:site_name" content="MWEBscan"/>
        <meta property="og:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <meta property="og:image:width" content="1200"/>
        <meta property="og:image:height" content="630"/>
        <meta property="og:image:alt" content="MWEBscan - open Litecoin MWEB explorer and privacy intelligence"/>
        <meta name="twitter:card" content="summary_large_image"/>
        <meta name="twitter:image" content="https://mwebscan.com/assets/og-banner.png"/>
        <title>Methodology &middot; MWEBscan</title>
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main">
            <h1 style="text-align:center;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">Methodology</h1>

            <p><strong>MWEBscan analyses only public Litecoin blockchain data.</strong> Nothing inside MWEB is visible to anyone, including us. Every link between a peg-in and a peg-out is an <em>inference</em> from public-side signals: a heuristic confidence score, not a calibrated statistical probability, and never a proof. Treat confidence scores as investigative leads, not facts.</p>

            <h2>What we observe directly</h2>
            <p>Peg-ins are <code>witness_mweb_pegin</code> outputs on the public chain. Peg-outs are the non-<code>witness_mweb_hogaddr</code> outputs of each block's HogEx (integrating) transaction; the <code>hogaddr</code> output's value is the total <?php echo mwebscan_unit(); ?> held in MWEB. These amounts, addresses, blocks and times are facts. Everything below is derived from them.</p>

            <h2>Round-trip linking</h2>
            <p>A peg-out of amount <em>A</em> is matched against earlier peg-ins of amount <em>A</em> to <em>A + fee tolerance</em> (a peg-out equals an earlier peg-in minus small fees). Confidence rises when:</p>
            <ul>
                <li><strong>Small anonymity set</strong>: few peg-ins and peg-outs share that amount. A unique amount with one candidate each side is a high-confidence candidate, but still not proof; a common rounded amount is effectively unlinkable.</li>
                <li><strong>Timing proximity</strong>: the peg-out follows the peg-in within a short window.</li>
            </ul>
            <p>Confidence ~ <code>1 / (candidate_pegins + competing_pegouts &minus; 1)</code>, scaled by a timing weight. Common, mixed, well-spaced amounts score low, which is the privacy-preserving case.</p>

            <h2>Wallet clustering</h2>
            <p>Addresses that co-spend as inputs of the same peg-in transaction are treated as one owner (the common-input-ownership heuristic). This only applies to peg-in funding transactions, where we can see the inputs.</p>

            <h2>Entity attribution</h2>
            <p>Curated, sourced address labels (exchanges, services, mixing services, sanctioned entities, and so on) are applied directly, then propagated across a wallet cluster at slightly reduced confidence. A label is only as good as its source; a wrong label is worse than none, so we never publish unverified attributions.</p>

            <h2>Scores</h2>
            <p><strong>Peg-in privacy score (0-100, higher is better):</strong> anonymity-set size, reduced if the peg-in is heuristically linked to a peg-out, if its funding address is reused on a peg-out, or if it is funded from a known entity.</p>
            <p><strong>Peg-out AML risk score (0-100, higher is more concerning):</strong> the risk weight of the destination entity, the risk of the funding entity carried <em>through</em> the inferred MWEB hop, and the traceability (link confidence), plus an address-reuse bump.</p>

            <h2>Multi-hop tracing</h2>
            <p>Following coins across more than one peg cycle multiplies per-hop confidences, so uncertainty compounds quickly. A three-hop chain at 80% per hop is roughly 50% overall.</p>

            <h2>Limitations &amp; false positives</h2>
            <ul>
                <li>Internal MWEB transactions are invisible: splits, merges and mixing can break amount matching entirely.</li>
                <li>Coincidental amount matches happen, especially for popular amounts (hence the anonymity-set weighting).</li>
                <li>Clustering only covers peg-in funders, not the wider chain.</li>
                <li>Attribution depends on label quality and coverage.</li>
            </ul>

            <h2 id="labels">Address labels</h2>
            <p>Attribution depends on curated, sourced labels, and Litecoin is heavily under-labelled, so coverage is intentionally sparse. <strong>MWEBscan prefers fewer sourced labels over broad unsourced attribution</strong>: a small accurate set is more trustworthy than a large speculative one. The other signals (amounts, timing, anonymity-set size, address reuse, clustering) work regardless of label coverage. See the <a href="https://github.com/Tech1k/mwebscan.com" target="_blank" rel="noopener">repository README</a> for where to obtain labels and how to import them.</p>
            <p><strong>Submit or correct a label.</strong> MWEBscan accepts <em>sourced</em>, publicly verifiable labels (exchanges, services, pools, merchants, sanctioned entities, public donation addresses, and the like); unsourced or speculative attributions are not. Open a <a href="https://github.com/Tech1k/mwebscan.com/issues/new?template=label_submission.md" target="_blank" rel="noopener">label submission issue</a> or email <a href="mailto:hello@tech1k.com">hello@tech1k.com</a> with the address, entity, category, a source, and whether it is new, a correction, or a removal. By submitting you grant Tech1k/MWEBscan the right to publish, modify, redistribute, and sublicense it as part of the MWEBscan label set (full terms in <a href="https://github.com/Tech1k/mwebscan.com/blob/HEAD/CONTRIBUTING.md" target="_blank" rel="noopener">CONTRIBUTING</a>). Labels are heuristic metadata, not proof of ownership or wrongdoing; we may edit or remove them.</p>
        </div>
        <?php require __DIR__ . '/lib/footer.php'; ?>
    </body>
</html>
