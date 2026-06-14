<?php require __DIR__ . '/lib/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="robots" content="noindex">
        <title>Privacy Policy &middot; MWEBscan</title>
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main">
            <h1 style="text-align:center;">Privacy Policy</h1>
            <p style="color:var(--muted);"><em>Last updated June 10, 2026.</em></p>

            <h2>What we collect</h2>
            <ul>
                <li><strong>No accounts.</strong> The public site requires no sign-up and we do not ask for personal information.</li>
                <li><strong>Rate-limiting:</strong> the API stores a <strong>salted hash</strong> of your IP address (never the raw IP) for a rolling window of about two minutes, solely to enforce request limits. It is then discarded.</li>
                <li><strong>Server logs:</strong> standard web-server logs may record IP, time and request; configure your host to minimise and rotate these.</li>
                <li><strong>No third-party trackers, ads or analytics</strong>, and no advertising cookies.</li>
            </ul>

            <h2>Blockchain data</h2>
            <p>We analyse <strong>public</strong> Litecoin blockchain data. Wallet addresses and derived attributions may, in some cases, constitute personal data under laws such as the GDPR. We process it for legitimate interests in transparency, research and compliance, and we publish it as heuristic, non-definitive analysis. This legitimate-interests processing is balanced against your rights and freedoms; a summary of that balancing test is available on request.</p>

            <h2>Your rights</h2>
            <p>Depending on your jurisdiction (e.g. GDPR/UK GDPR/CCPA) you may have rights to access, correct or request deletion of personal data we hold about you, and to <strong>object to our legitimate-interests processing</strong>. We cannot alter the public blockchain, but we can correct or remove entries in <em>our</em> derived data and labels where appropriate, and we aim to respond within 30 days. The data controller is the operator of MWEBscan, reachable at the address below. Requests and objections: <a href="mailto:hello@tech1k.com">hello@tech1k.com</a>.</p>

            <h2>Retention</h2>
            <p>Rate-limit hashes expire within minutes. Derived analysis is recomputed from public data and contains no account information.</p>

            <h2>Changes</h2>
            <p>We may update this policy; the "last updated" date will change accordingly.</p>

            <p style="margin-top:24px;"><a href="/terms.php">Terms of Service</a> &middot; <a href="/disclaimer.php">Disclaimer</a></p>
        </div>
        <?php require __DIR__ . '/lib/footer.php'; ?>
    </body>
</html>
