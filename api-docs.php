<?php require __DIR__ . '/lib/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#5271ff">
        <meta name="description" content="MWEBscan JSON API reference: free, read-only endpoints for Litecoin MWEB peg-in/peg-out data, privacy scores, traces and links.">
        <link rel="canonical" href="https://mwebscan.com/api-docs"/>
        <title>API &middot; MWEBscan</title>
        <meta property="og:title" content="MWEBscan API - free Litecoin MWEB data"/>
        <meta property="og:description" content="Free, read-only JSON API for Litecoin MWEB peg-in/peg-out data, privacy scores, traces and links."/>
        <meta property="og:type" content="website"/>
        <meta property="og:url" content="https://mwebscan.com/api-docs"/>
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
            .ep { background:var(--card); box-shadow:0 0 10px var(--shadow); border-radius:8px; padding:14px 18px; margin:14px 0; }
            .ep h3 { margin:0 0 6px; word-break:break-word; overflow-wrap:anywhere; }
            code, pre { font-family:monospace; }
            pre { background:var(--surface); padding:10px; border-radius:6px; overflow-x:auto; max-width:100%; font-size:0.85em; }
            .ep .params { color:var(--muted); font-size:0.92em; }
            .get { color:var(--ok); font-weight:700; }
            .tryit { margin-top:10px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
            .tryit input[type="text"] { box-sizing:border-box; flex:1 1 260px; min-width:0; width:auto; margin:0; font-family:monospace; font-size:0.85em; }
            .tryit .tryit-btn { margin:0; }
            .tryit-out { box-sizing:border-box; width:100%; margin-top:6px; max-height:340px; overflow:auto; white-space:pre-wrap; word-break:break-word; }
        </style>
    </head>
    <body>
        <?php require __DIR__ . '/lib/nav.php'; ?>
        <div id="main">
            <h1 style="text-align:center;"><img src="/assets/mwebscan-logo.png" alt="MWEBscan" width="40" height="40" style="margin-right:5px; vertical-align:middle;">MWEBscan API</h1>

            <?php if (substr($_SERVER['HTTP_HOST'] ?? '', -6) === '.onion'): ?>
            <p style="background:var(--surface); border-left:3px solid var(--warn); padding:8px 12px;">Note: the live API is disabled over Tor (per-IP rate limiting can't work on an onion service). The endpoints and the "Try it" buttons below work on the clearnet site at <code>mwebscan.com</code>.</p>
            <?php endif; ?>

            <p>A free, <strong>read-only</strong> JSON API over the same engine the site uses. No key or sign-up for normal use.</p>
            <ul>
                <li><strong>Base URL:</strong> <code><?php echo mwebscan_base_url(); ?>/api</code> (<code>/api.php</code> also works if a deployment lacks URL rewriting)</li>
                <li><strong>Routing:</strong> path <code>/api/&lt;endpoint&gt;</code> (e.g. <code>/api/stats</code>), or query <code>?endpoint=&lt;name&gt;</code>.</li>
                <li><strong>Format:</strong> JSON by default; add <code>&amp;format=csv</code> to <code>trace</code>, <code>links</code>, <code>pegin_amounts</code> to download a CSV.</li>
                <li><strong>Rate limit:</strong> ~60 requests/minute per IP (HTTP <code>429</code> if exceeded). Use is subject to the <a href="/terms">Terms</a>.</li>
                <li><strong>CORS:</strong> open (<code>Access-Control-Allow-Origin: *</code>), so it works from the browser.</li>
                <li><strong>License:</strong> API output is MWEBscan-generated data under <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a> (attribution required; third-party labels keep their own terms). See <a href="https://github.com/Tech1k/mwebscan.com/blob/HEAD/LICENSING.md" target="_blank" rel="noopener">LICENSING.md</a>.</li>
                <li><strong>Attribution:</strong> public products displaying MWEBscan data, links, scores, labels, heuristics, or API-derived results must include attribution such as <em>"Data from MWEBscan by Tech1k"</em> or <em>"Powered by MWEBscan"</em>, with a link to <code>mwebscan.com</code>. Do not imply endorsement unless written permission is granted. Full terms: <a href="https://github.com/Tech1k/mwebscan.com/blob/HEAD/ATTRIBUTION.md" target="_blank" rel="noopener">ATTRIBUTION.md</a>.</li>
            </ul>
            <p style="background:var(--surface); border-left:3px solid var(--warn); padding:8px 12px;">All links and attributions are <strong>heuristic, not proof</strong> (public-chain inference only). See the <a href="/methodology">methodology</a>.</p>
            <p><strong>Need a higher limit?</strong> You have two paths: self-host (the whole stack is open-source under the AGPL, so your own instance is not subject to the public API rate limit), or email <a href="mailto:hello@tech1k.com">hello@tech1k.com</a> with your use case and rough volume. Options include a raised per-key limit, bulk data exports, hosted/dedicated instances, or commercial (non-AGPL) terms.</p>

            <h2>Endpoints</h2>
            <p style="color:var(--muted); font-size:0.9em;">Examples below are abbreviated; every response also includes <code>"version"</code>. Field meanings (<code>confidence</code>, <code>block_gap</code>, <code>candidate_count</code>, privacy/risk scores) are defined in the <a href="/methodology">methodology</a>.</p>

            <div class="ep">
                <h3><span class="get">GET</span> /api/health</h3>
                <p>Sync height and data freshness (how far the scanner has got, and how long ago the analysis last ran).</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/health"</pre>
                <pre>{
  "health": {
    "ok": true, "last_scanned_block": 2870431,
    "analysis_updated": 1733800000, "analysis_age_seconds": 540,
    "pegins": 51234, "pegouts": 48910
  },
  "version": "1"
}</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/stats</h3>
                <p>Headline analysis counts (totals, linkable peg-outs, labelled addresses, scores...).</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/stats"</pre>
                <pre>{
  "stats": {
    "total_pegins": 51234, "total_pegouts": 48910,
    "linkable_pegouts": 312, "high_confidence_links": 88,
    "labeled_addresses": 64, "avg_privacy_score": 47.2,
    "high_risk_pegouts": 19
  },
  "version": "1"
}</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/privacy?amount=&lt;ltc&gt;</h3>
                <p class="params"><strong>amount</strong>: positive <?php echo mwebscan_unit(); ?> value.</p>
                <p>Wallet pre-flight check: how well a peg-in of this amount blends in (anonymity set + privacy score + advice). Amount only, so no address leaves the client.</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/privacy?amount=1.0"</pre>
                <pre>{
  "privacy": {
    "amount": 1.0, "rounded": 1.0,
    "rounded_set": 842, "exact_set": 137,
    "privacy_score": 71, "rating": "Excellent",
    "advice": "A very common amount; you blend into a large anonymity set."
  },
  "version": "1"
}</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/recommendations</h3>
                <p>Live privacy guidance: best peg-in amounts right now, suggested wait before pegging out, and tips.</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/recommendations"</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/trace?q=&lt;address|txid&gt;</h3>
                <p class="params"><strong>q</strong>: a Litecoin address, peg-in txid, or peg-out txid. &nbsp;Optional <strong>format=csv</strong>.</p>
                <p>Full one-hop trace across the MWEB hop: funders -> peg-in -> linked peg-outs -> destinations, with entity tags and confidences.</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/trace?q=<?php echo mwebscan_addr_example(); ?>"</pre>
                <pre>{
  "trace": {
    "query": "<?php echo mwebscan_addr_example(); ?>", "type": "address",
    "attribution": { "entity": "Binance", "category": "exchange", "via": "direct" },
    "pegins": [ { "txid": "d32a...", "amount": 64.50828331,
                  "links": [ { "pegout_txid": "ae02...", "confidence": 1.0,
                               "pegout_entity": null } ] } ],
    "pegouts": []
  },
  "version": "1"
}</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/follow?q=&lt;address&gt;&amp;depth=3</h3>
                <p class="params"><strong>q</strong>: address. &nbsp;<strong>depth</strong>: hops to follow (1-10, default 3).</p>
                <p>Multi-hop "follow the money": chains repeated peg cycles together. Multiply the per-hop confidences for the chain total.</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/follow?q=<?php echo mwebscan_addr_example(); ?>&depth=4"</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/address?q=&lt;address&gt;</h3>
                <p class="params"><strong>q</strong>: address.</p>
                <p>Summary for an address: entity attribution plus peg-outs received and peg-ins funded.</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/address?q=<?php echo mwebscan_addr_example(); ?>"</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/links?min_confidence=0.5&amp;limit=100</h3>
                <p class="params"><strong>min_confidence</strong>: 0-1 (default 0.5). &nbsp;<strong>limit</strong>: 1-500 (default 100). &nbsp;Optional <strong>format=csv</strong>.</p>
                <p>The round-trip links: peg-outs matched to earlier peg-ins, with confidence, AML risk and reasons.</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/links?min_confidence=0.9"</pre>
                <pre>{
  "min_confidence": 0.9, "count": 1,
  "links": [
    { "pegout_txid": "ae02...", "pegout_amount": 64.50821921, "pegout_entity": null,
      "pegin_txid": "d32a...", "pegin_amount": 64.50828331,
      "confidence": 1.0, "risk_score": 40, "block_gap": 1, "candidate_count": 1,
      "reasons": ["exact amount match", "1 candidate peg-in", "1 block after peg-in"] }
  ],
  "version": "1"
}</pre>
            </div>

            <div class="ep">
                <h3><span class="get">GET</span> /api/pegin_amounts?limit=100</h3>
                <p class="params"><strong>limit</strong>: 1-500 (default 100). &nbsp;Optional <strong>format=csv</strong>.</p>
                <p>Most common (rounded) peg-in amounts and their counts (the "blend in with the crowd" data).</p>
                <pre>curl "<?php echo mwebscan_base_url(); ?>/api/pegin_amounts?limit=50"</pre>
            </div>

            <h2>Wallet integration</h2>
            <p>For a privacy wallet, the right call is a <strong>pre-flight check that never sends a user's address</strong>. Query <code>privacy</code> with the <em>amount</em> only, before a peg-in:</p>
            <pre>// nudge the user toward a more private amount
const r = await fetch(`<?php echo mwebscan_base_url(); ?>/api/privacy?amount=${amt}`);
const { privacy } = await r.json();
if (privacy.privacy_score &lt; 40) {
    warn(`This amount is easy to single out (score ${privacy.privacy_score}/100). ` +
         `Try a common rounded amount.`);
}</pre>
            <p>Even more privacy-preserving: fetch <code>pegin_amounts</code> and <code>recommendations</code> <strong>once, cache them, and evaluate locally</strong>, so no per-transaction request leaves the wallet at all. (You can also self-host the API so nothing touches a third party.)</p>

            <h2>Errors</h2>
            <p>JSON <code>{"error": "...", "version": "1"}</code> with an HTTP status: <code>400</code> (bad/missing params), <code>404</code> (unknown endpoint), <code>429</code> (rate limited), <code>503</code> (DB unavailable / analysis not yet run).</p>

            <p style="text-align:center; margin-top:24px;"><a class="toggle-button" href="/api.php" target="_blank" rel="noopener">Live endpoint index (JSON)</a></p>
        </div>
        <?php require __DIR__ . '/lib/footer.php'; ?>
        <script>
        // Live "Try it" for each endpoint card: pull the example URL out of the
        // curl line, drop in an editable field + button, fetch same-origin and
        // pretty-print the JSON response.
        (function () {
            function toFetchUrl(u) {
                try { var p = new URL(u, window.location.href); return p.pathname + p.search; }
                catch (e) { return u; }
            }
            var cards = document.querySelectorAll(".ep");
            for (var c = 0; c < cards.length; c++) {
                (function (ep) {
                    var pres = ep.querySelectorAll("pre");
                    var url = null;
                    for (var i = 0; i < pres.length; i++) {
                        var txt = pres[i].textContent || "";
                        var m = txt.match(/https?:\/\/[^\s"']+/);
                        if (txt.indexOf("curl") !== -1 && m) { url = m[0]; break; }
                    }
                    if (!url) return;

                    var wrap = document.createElement("div");
                    wrap.className = "tryit";

                    var input = document.createElement("input");
                    input.type = "text";
                    input.value = url;
                    input.spellcheck = false;
                    input.setAttribute("aria-label", "Request URL to try");

                    var btn = document.createElement("button");
                    btn.type = "button";
                    btn.className = "toggle-button tryit-btn";
                    btn.textContent = "Try it";

                    var out = document.createElement("pre");
                    out.className = "tryit-out";
                    out.style.display = "none";

                    btn.addEventListener("click", function () {
                        var u = toFetchUrl(input.value.trim());
                        out.style.display = "block";
                        out.textContent = "Loading " + u + " ...";
                        btn.disabled = true;
                        fetch(u, { headers: { "Accept": "application/json" } })
                            .then(function (r) {
                                return r.text().then(function (t) {
                                    var body;
                                    try { body = JSON.stringify(JSON.parse(t), null, 2); }
                                    catch (e) { body = t; }
                                    out.textContent = "HTTP " + r.status + " " + r.statusText + "\n\n" + body;
                                });
                            })
                            .catch(function (e) {
                                out.textContent = "Request failed: " + (e && e.message ? e.message : e);
                            })
                            .then(function () { btn.disabled = false; });
                    });

                    wrap.appendChild(input);
                    wrap.appendChild(btn);
                    ep.appendChild(wrap);
                    ep.appendChild(out);
                })(cards[c]);
            }
        })();
        </script>
    </body>
</html>
