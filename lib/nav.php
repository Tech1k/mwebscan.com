<?php
// Shared top navigation, included by every page after <body>.
require_once __DIR__ . '/network.php';
$_nav = [
    '/' => 'Home',
    '/trace' => 'Trace',
    '/charts' => 'Charts',
    '/about' => 'About',
    '/methodology' => 'Methodology',
    '/api-docs' => 'API',
    '/donate' => 'Donate',
];
// Served script keeps its .php (e.g. /trace.php) even on a clean /trace URL, so
// strip it to compare against the extensionless nav hrefs.
$_cur = preg_replace('#\.php$#', '', $_SERVER['PHP_SELF'] ?? '');
$_items = [];
foreach ($_nav as $_href => $_label) {
    if ($_href === '/') {
        $_active = ($_cur === '/' || substr($_cur, -6) === '/index');
    } else {
        $_active = (substr($_cur, -strlen($_href)) === $_href);
    }
    $_items[] = '<a href="' . $_href . '"' . ($_active ? ' aria-current="page" style="font-weight:600;"' : '') . '>' . $_label . '</a>';
}
// Apply a saved theme before paint to avoid a flash; no choice = follow the OS.
echo '<script>(function(){try{var t=localStorage.getItem("theme");if(t)document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
if (mwebscan_is_testnet()) {
    echo '<div style="background:#b5532a; color:#fff; text-align:center; padding:5px 10px; font-size:0.85em; font-weight:600;">TESTNET - test data only, coins have no value.</div>';
}
echo '<nav class="topnav">' . implode(' &middot; ', $_items)
    . ' <button class="theme-toggle" id="themeToggle" type="button" aria-label="Toggle light or dark theme">Theme</button>'
    . '</nav>';
