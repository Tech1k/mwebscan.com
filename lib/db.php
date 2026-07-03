<?php
/**
 * Database connection and error handling.
 *
 * Define MWEBSCAN_DEBUG = true before including to show errors. In production
 * errors and DB internals are not echoed to visitors.
 */

if (!defined('MWEBSCAN_DEBUG')) {
    define('MWEBSCAN_DEBUG', false);
}

if (MWEBSCAN_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
    // Turn any uncaught exception (e.g. a failed DB connection: missing
    // pdo_sqlite extension, or a locked/unreadable file) into a clean 503
    // page instead of a blank 500. Debug mode lets it surface normally.
    set_exception_handler(function ($e) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 120');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Temporarily unavailable - MWEBscan</title>'
            . '<link rel="stylesheet" href="/assets/style.css?v=8"></head>'
            . '<body><div id="main" style="text-align:center; padding-top:48px;">'
            . '<h1>Temporarily unavailable</h1>'
            . '<p style="color:var(--muted);">The data service is unavailable right now. Please try again shortly.</p>'
            . '</div></body></html>';
        exit;
    });
}

require_once __DIR__ . '/network.php';

/** Open the SQLite database with exceptions enabled. */
function mwebscan_db($path = null)
{
    $path = $path ?: mwebscan_db_path();
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Wait up to 5s for the writer instead of erroring while the analysis
    // pass holds the lock.
    $db->exec('PRAGMA busy_timeout = 5000');
    return $db;
}

/**
 * Read a precomputed aggregate from the cache table, falling back to a live
 * query if the analysis pass hasn't populated it.
 */
function mwebscan_cached($db, $key, $fallback_sql)
{
    try {
        $st = $db->prepare("SELECT json FROM cache WHERE key = ?");
        $st->execute([$key]);
        $json = $st->fetchColumn();
        if ($json !== false) {
            return json_decode($json, true);
        }
    } catch (Exception $e) {
        // cache table missing; fall through to live query
    }
    return $db->query($fallback_sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Read a cache entry as decoded JSON, or null if absent. */
function mwebscan_cache_get($db, $key)
{
    try {
        $st = $db->prepare("SELECT json FROM cache WHERE key = ?");
        $st->execute([$key]);
        $json = $st->fetchColumn();
        return $json !== false ? json_decode($json, true) : null;
    } catch (Exception $e) {
        return null;
    }
}
