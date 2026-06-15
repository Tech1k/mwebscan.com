<?php http_response_code(404); ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="robots" content="noindex">
        <title>404 Not Found &middot; MWEBscan</title>
        <link rel="shortcut icon" href="/assets/favicon.png"/>
        <link rel="stylesheet" href="/assets/style.css?v=8">
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main" style="text-align:center;">
            <h1 style="font-size:3.5em; margin:30px 0 0;">404</h1>
            <p style="color:var(--muted);">That page does not exist. It may have moved, or it never did.</p>
            <p style="margin-top:20px;">
                <a class="toggle-button" href="/">Home</a>
                <a class="toggle-button" href="/trace">Trace</a>
                <a class="toggle-button" href="/methodology">Methodology</a>
            </p>
        </div>
        <?php require __DIR__ . '/lib/footer.php'; ?>
    </body>
</html>
