<?php
// Shared top navigation, included by every page after <body>.
$_nav = [
    '/' => 'Home',
    '/trace.php' => 'Trace',
    '/charts.php' => 'Charts',
    '/about.php' => 'About',
    '/methodology.php' => 'Methodology',
    '/api-docs.php' => 'API',
    '/donate.php' => 'Donate',
];
$_cur = $_SERVER['PHP_SELF'] ?? '';
$_items = [];
foreach ($_nav as $_href => $_label) {
    if ($_href === '/') {
        $_active = ($_cur === '/' || substr($_cur, -10) === '/index.php');
    } else {
        $_active = (substr($_cur, -strlen($_href)) === $_href);
    }
    $_items[] = '<a href="' . $_href . '"' . ($_active ? ' aria-current="page" style="font-weight:600;"' : '') . '>' . $_label . '</a>';
}
// Apply a saved theme before paint to avoid a flash; no choice = follow the OS.
echo '<script>(function(){try{var t=localStorage.getItem("theme");if(t)document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
echo '<nav class="topnav">' . implode(' &middot; ', $_items)
    . ' <button class="theme-toggle" id="themeToggle" type="button" aria-label="Toggle light or dark theme">Theme</button>'
    . '</nav>';
