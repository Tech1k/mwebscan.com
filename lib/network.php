<?php
/**
 * Active network selection (MWEBSCAN_NETWORK; default 'mainnet') and the
 * network-specific display helpers. An unset or unknown value resolves to
 * mainnet, so the live site is unchanged unless explicitly set to 'testnet'.
 *
 * The web process and the Python daemon must share the same MWEBSCAN_NETWORK so
 * the reader and writer agree on the database file.
 */

function mwebscan_network()
{
    $n = getenv('MWEBSCAN_NETWORK');
    if ($n === false || $n === '') {
        // Apache SetEnv reaches php-fpm via $_SERVER, not getenv(); check both.
        $n = $_SERVER['MWEBSCAN_NETWORK'] ?? '';
    }
    $n = strtolower(trim((string) $n));
    return $n === 'testnet' ? 'testnet' : 'mainnet';
}

function mwebscan_is_testnet()
{
    return mwebscan_network() === 'testnet';
}

/** Path to the active network's SQLite database (mainnet: mwebscan.db). */
function mwebscan_db_path()
{
    $file = mwebscan_is_testnet() ? 'mwebscan-testnet.db' : 'mwebscan.db';
    return __DIR__ . '/../' . $file;
}

/** Currency unit label for amounts (tLTC on testnet). */
function mwebscan_unit()
{
    return mwebscan_is_testnet() ? 'tLTC' : 'LTC';
}

/** Block-explorer transaction URL for the active network. */
function mwebscan_tx_url($txid)
{
    $base = mwebscan_is_testnet()
        ? 'https://litecoinspace.org/testnet/tx/'
        : 'https://litecoinspace.org/tx/';
    return $base . rawurlencode($txid);
}

/** Example bech32 address for input placeholders. */
function mwebscan_addr_example()
{
    return mwebscan_is_testnet() ? 'tltc1q...' : 'ltc1q...';
}

/**
 * Scheme + host of the current request, for runnable API examples (curl/fetch).
 * Follows whatever domain the site is served on, so the testnet deploy shows its
 * own host without hardcoding it. Falls back to the canonical host for an
 * unset/odd/spoofed Host header (the regex blocks any HTML-special character).
 */
function mwebscan_base_url()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'mwebscan.com';
    if (!preg_match('/^[A-Za-z0-9.:-]+$/', $host)) {
        $host = 'mwebscan.com';
    }
    return 'https://' . $host;
}
