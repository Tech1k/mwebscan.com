<?php
require __DIR__ . '/lib/db.php';

// Donation addresses: a transparent Litecoin address and an MWEB address.
// OpenAlias (donate@mwebscan.com) resolves to these via DNS for supporting wallets.
$LTC_TRANSPARENT = 'ltc1qesqzfuwfsdx8t3dmgnh2f79eflhav7qyrs7urn';
$LTC_MWEB        = 'ltcmweb1qqv2z6c6gu0csd454rlx6xp4rgu8dxska3wxypcr9m7cqu08h39rccqj4pr3j0e0lamu3358quqh84vlst7v9xa3q6h3u3u0mlj6uv0w6ag5mcucl';
$OPENALIAS       = 'donate@mwebscan.com';
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="description" content="Support MWEBscan development with Litecoin - a transparent address you can audit on-chain, or a private MWEB address even we cannot see.">
        <link rel="canonical" href="https://mwebscan.com/donate"/>
        <title>Donate &middot; MWEBscan</title>
        <meta property="og:title" content="Support MWEBscan"/>
        <meta property="og:description" content="Donate in Litecoin - transparent or private via MWEB. OpenAlias: donate@mwebscan.com"/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/donate"/>
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
            .donate-intro { max-width:680px; margin:0 auto 22px; text-align:center; color:var(--text-soft); line-height:1.55; }
            .openalias { max-width:600px; margin:26px auto 0; text-align:center; font-size:0.95em; color:var(--muted); }
            .openalias .oa { font-family:monospace; font-weight:700; color:var(--accent); }
            .openalias .oa-copy { margin-left:8px; margin-top:0; vertical-align:middle; }
            .openalias .oa-note { font-size:0.85em; }
            .donate-grid { display:flex; flex-wrap:wrap; gap:18px; justify-content:center; max-width:760px; margin:0 auto; }
            .donate-card { flex:1 1 320px; background:var(--card); border-radius:12px; box-shadow:0 1px 5px var(--shadow); padding:20px; text-align:center; }
            .donate-card h2 { margin:0 0 2px; font-size:1.12em; }
            .donate-card .tag { font-size:0.85em; color:var(--muted); margin-bottom:14px; min-height:34px; }
            .donate-card .qr { width:180px; height:180px; margin:0 auto 14px; background:#fff; padding:8px; border:1px solid var(--border); border-radius:8px; }
            .donate-card .qr img { width:100%; height:100%; display:block; }
            .addr { font-family:monospace; font-size:0.8em; word-break:break-all; background:var(--surface); border-radius:6px; padding:8px 10px; color:var(--text); }
            .copy-btn { margin-top:10px; cursor:pointer; border:1px solid var(--border2); background:var(--card); color:var(--text-soft); border-radius:6px; padding:6px 14px; font-size:0.85em; }
            .copy-btn:hover { background:var(--table-head); }
            .copy-btn.copied { background:#2a8a4a; color:#fff; border-color:#2a8a4a; }
            .badge { display:inline-block; font-size:0.72em; font-weight:700; padding:2px 8px; border-radius:20px; vertical-align:middle; margin-left:6px; }
            .badge.pub { background:#e7f0ff; color:#2f5bd0; }
            .badge.priv { background:#e9f7ee; color:#1f7a3d; }
        </style>
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main" style="text-align:center;">
            <h1><a href="/" style="text-decoration:none; color:inherit;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">MWEBscan</a></h1>
            <h2>Support development</h2>
        </div>

        <p class="donate-intro">
            Free and open-source. Donations fund development only and never influence what the tool reports. Thank you.
        </p>

        <div class="donate-grid">
            <div class="donate-card">
                <h2>LTC Address<span class="badge pub">public</span></h2>
                <div class="tag">A normal Litecoin address, auditable on-chain.</div>
                <div class="qr"><img src="/assets/qr-ltc.svg" alt="QR code for the transparent Litecoin donation address"></div>
                <div class="addr" id="addrLtc"><?php echo htmlspecialchars($LTC_TRANSPARENT, ENT_QUOTES); ?></div>
                <button class="copy-btn" data-copy="<?php echo htmlspecialchars($LTC_TRANSPARENT, ENT_QUOTES); ?>">Copy address</button>
            </div>

            <div class="donate-card">
                <h2>LTC MWEB Address<span class="badge priv">private</span></h2>
                <div class="tag">A MimbleWimble address; amounts and parties hidden.</div>
                <div class="qr"><img src="/assets/qr-ltc-mweb.svg" alt="QR code for the MWEB private Litecoin donation address"></div>
                <div class="addr" id="addrMweb"><?php echo htmlspecialchars($LTC_MWEB, ENT_QUOTES); ?></div>
                <button class="copy-btn" data-copy="<?php echo htmlspecialchars($LTC_MWEB, ENT_QUOTES); ?>">Copy address</button>
            </div>
        </div>

        <p class="openalias">
            <strong>OpenAlias:</strong>
            <span class="oa" id="oaText"><?php echo htmlspecialchars($OPENALIAS, ENT_QUOTES); ?></span>
            <button class="copy-btn oa-copy" data-copy="<?php echo htmlspecialchars($OPENALIAS, ENT_QUOTES); ?>">Copy</button>
            <br><span class="oa-note">Paste into a <a href="https://cyphertoshi.com/posts/openalias-wallets" target="_blank" rel="noopener">supporting wallet</a> instead of an address.</span>
        </p>

        <?php require __DIR__ . '/lib/footer.php'; ?>

        <script>
        document.querySelectorAll('.copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy');
                var done = function () {
                    var label = btn.textContent;
                    btn.textContent = 'Copied';
                    btn.classList.add('copied');
                    setTimeout(function () { btn.textContent = label; btn.classList.remove('copied'); }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    document.body.removeChild(ta); done();
                }
            });
        });
        </script>
    </body>
</html>
