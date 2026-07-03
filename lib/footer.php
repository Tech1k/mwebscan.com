<?php
// Shared site footer: disclaimer, nav, legal links and credit.
require_once __DIR__ . '/network.php';
?>
<footer style="margin-top:40px; border-top:1px solid var(--border); padding-top:16px;">
    <p style="text-align:center; font-size:0.9em; color:var(--muted);">Heuristic analysis of public Litecoin data: inferences, not proof. Not financial or legal advice.</p>
    <p style="text-align:center; font-size:0.9em;">
        <a href="/about">About</a> &middot;
        <a href="/trace">Trace</a> &middot;
        <a href="/charts">Charts</a> &middot;
        <a href="/methodology">Methodology</a> &middot;
        <a href="/api-docs">API</a> &middot;
        <a href="/methodology#labels">Submit labels</a> &middot;
        <a href="/donate">Donate</a> &middot;
        <a href="https://github.com/Tech1k/mwebscan.com" target="_blank" rel="noopener">Open-source</a> &middot;
        <a href="<?php echo mwebscan_other_network_url(); ?>"><?php echo mwebscan_other_network_label(); ?></a>
    </p>
    <p style="text-align:center; font-size:0.9em;">
        <a href="/terms">Terms</a> &middot;
        <a href="/privacy">Privacy</a> &middot;
        <a href="/disclaimer">Disclaimer</a>
    </p>
    <p style="text-align:center; font-size:0.85em; color:var(--muted);">Made by <a href="https://tech1k.com" target="_blank" rel="noopener">Tech1k</a>.</p>
    <p style="text-align:center; font-size:0.78em; color:var(--muted); word-break:break-all; margin:6px auto 0; max-width:560px;">Also on Tor: <a href="http://sd7u7gehbw3mgqnvibky2sfglwav4kdc7pzm4gtgp747d3eyjhgj5zqd.onion">sd7u7gehbw3mgqnvibky2sfglwav4kdc7pzm4gtgp747d3eyjhgj5zqd.onion</a></p>
</footer>
<script>
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var root = document.documentElement;
    function current() {
        return root.getAttribute('data-theme')
            || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    }
    var SUN = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    var MOON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    function render() {
        var dark = current() === 'dark';
        btn.innerHTML = dark ? SUN : MOON;   // show the theme you'll switch TO
        btn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
        btn.setAttribute('title', dark ? 'Light theme' : 'Dark theme');
    }
    render();
    btn.addEventListener('click', function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) {}
        render();
    });
})();
</script>
