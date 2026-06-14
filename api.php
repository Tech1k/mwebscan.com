<?php
/**
 * MWEBscan JSON API.
 *
 * Read-only public API over lib/trace_engine.php. Query-param routing:
 *   /api.php?endpoint=trace&q=<address|txid>
 *   /api.php?endpoint=privacy&amount=1.0
 *
 * Optional API-key gate, disabled by default.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-Key');

define('MWEBSCAN_DEBUG', false);
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/trace_engine.php';

const API_VERSION = '1';
const DEFAULT_LIMIT = 100;
const MAX_LIMIT = 500;

// Set to a secret string to require ?key= / X-API-Key on every request.
$REQUIRED_KEY = '';
// Per-IP request cap per 60s window (0 disables).
$RATE_LIMIT = 60;

function out($data, $code = 200)
{
    http_response_code($code);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $data['version'] = API_VERSION;
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function err($message, $code = 400)
{
    out(['error' => $message], $code);
}

function rate_limit($db, $limit, $window = 60)
{
    // Store a salted hash of the IP, never the raw address, and keep only the
    // current + previous window (rows expire within ~2 minutes).
    $raw = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    // Salt from MWEBSCAN_RATE_SALT, else a random salt persisted to .rate_salt,
    // else a per-process random salt. Hashes stay unreversible either way.
    $salt = getenv('MWEBSCAN_RATE_SALT');
    if (!$salt) {
        $saltFile = __DIR__ . '/.rate_salt';
        if (is_readable($saltFile)) {
            $salt = trim((string) @file_get_contents($saltFile));
        }
        if (!$salt) {
            $salt = bin2hex(random_bytes(32));
            if (@file_put_contents($saltFile, $salt, LOCK_EX) !== false) {
                @chmod($saltFile, 0600);
            }
        }
    }
    $ip = substr(hash('sha256', $raw . '|' . $salt), 0, 32);
    $now = time();
    $bucket = (int) ($now / $window);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS api_rate (ip TEXT, bucket INTEGER, count INTEGER, PRIMARY KEY(ip, bucket))");
        $st = $db->prepare("INSERT INTO api_rate (ip, bucket, count) VALUES (?, ?, 1)
                            ON CONFLICT(ip, bucket) DO UPDATE SET count = count + 1");
        $st->execute([$ip, $bucket]);
        // Sliding-window counter: current bucket plus the decaying tail of the
        // previous one, so a client cannot burst 2x across a window boundary.
        $st = $db->prepare("SELECT bucket, count FROM api_rate WHERE ip = ? AND bucket IN (?, ?)");
        $st->execute([$ip, $bucket, $bucket - 1]);
        $cur = $prev = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int) $r['bucket'] === $bucket) { $cur = (int) $r['count']; }
            else { $prev = (int) $r['count']; }
        }
        $db->prepare("DELETE FROM api_rate WHERE bucket < ?")->execute([$bucket - 2]);
        $estimated = $cur + $prev * (($window - ($now % $window)) / $window);
        if ($estimated > $limit) {
            header('Retry-After: ' . $window);
            err('rate limit exceeded', 429);
        }
    } catch (Exception $e) {
        // A limiter error must not take down the API.
    }
}

function csv_cell($c)
{
    // Prefix cells starting with a formula trigger to block spreadsheet
    // formula injection.
    if (is_string($c) && $c !== '' && strpos("=+-@\t\r\n", $c[0]) !== false) {
        return "'" . $c;
    }
    return $c;
}

function csv_out($filename, $header, $rows)
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $header);
    foreach ($rows as $row) {
        fputcsv($fh, array_map('csv_cell', $row));
    }
    fclose($fh);
    exit;
}

function clamp_limit($v)
{
    $v = (int) ($v ?: DEFAULT_LIMIT);
    return max(1, min(MAX_LIMIT, $v));
}

try {
    $db = mwebscan_db();
} catch (Exception $e) {
    err('database unavailable', 503);
}

if ($REQUIRED_KEY !== '') {
    $key = $_GET['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (!hash_equals($REQUIRED_KEY, (string) $key)) {
        err('invalid or missing API key', 401);
    }
}

if ($RATE_LIMIT > 0) {
    rate_limit($db, $RATE_LIMIT);
}

$endpoint = $_GET['endpoint'] ?? 'index';
$format = strtolower($_GET['format'] ?? 'json');

switch ($endpoint) {
    case 'index':
        out([
            'service' => 'MWEBscan API',
            'endpoints' => [
                'health' => 'GET ?endpoint=health  (sync height + data freshness)',
                'stats' => 'GET ?endpoint=stats',
                'trace' => 'GET ?endpoint=trace&q=<address|txid>',
                'follow' => 'GET ?endpoint=follow&q=<address>&depth=3  (multi-hop)',
                'privacy' => 'GET ?endpoint=privacy&amount=<ltc>  (wallet pre-flight)',
                'recommendations' => 'GET ?endpoint=recommendations  (live privacy guidance)',
                'address' => 'GET ?endpoint=address&q=<address>  (attribution + peg summary)',
                'links' => 'GET ?endpoint=links&min_confidence=0.5&limit=100',
                'pegin_amounts' => 'GET ?endpoint=pegin_amounts&limit=100',
            ],
            'disclaimer' => 'Cross-MWEB links are heuristic, not proof. Public-chain data only.',
        ]);

        // no break (out() exits)

    case 'health':
        $h = ['ok' => true];
        try {
            $h['last_scanned_block'] = (int) $db->query("SELECT last_scanned_block FROM scan_progress WHERE id=1")->fetchColumn();
        } catch (Exception $e) {
            $h['last_scanned_block'] = null;
        }
        try {
            $updated = $db->query("SELECT MAX(updated) FROM cache")->fetchColumn();
            $h['analysis_updated'] = $updated ? (int) $updated : null;
            $h['analysis_age_seconds'] = $updated ? max(0, time() - (int) $updated) : null;
        } catch (Exception $e) {
            $h['analysis_updated'] = null;
            $h['analysis_age_seconds'] = null;
        }
        try {
            $h['pegins'] = (int) $db->query("SELECT COUNT(*) FROM mweb_pegins")->fetchColumn();
            $h['pegouts'] = (int) $db->query("SELECT COUNT(*) FROM mweb_pegouts")->fetchColumn();
        } catch (Exception $e) {
            $h['pegins'] = $h['pegouts'] = null;
        }
        out(['health' => $h]);

    case 'stats':
        $stats = [];
        try {
            foreach ($db->query("SELECT key, value FROM analysis_stats") as $r) {
                $stats[$r['key']] = $r['value'] + 0;
            }
        } catch (Exception $e) {
            err('analysis has not been run yet', 503);
        }
        out(['stats' => $stats]);

    case 'privacy':
        if (!isset($_GET['amount']) || !is_numeric($_GET['amount']) || $_GET['amount'] <= 0) {
            err('amount (positive number) required');
        }
        out(['privacy' => mwebscan_amount_privacy($db, (float) $_GET['amount'])]);

    case 'recommendations':
        out(['recommendations' => mwebscan_cache_get($db, 'recommendations')]);

    case 'trace':
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if ($q === '') {
            err('q (address or txid) required');
        }
        $t = mwebscan_trace($db, $q);
        if ($format === 'csv') {
            $rows = [];
            foreach ($t['pegins'] as $pin) {
                if (!empty($pin['links'])) {
                    foreach ($pin['links'] as $lk) {
                        $rows[] = ['pegin', $pin['txid'], $pin['amount'], $lk['pegout_txid'],
                                   $lk['pegout_amount'], $lk['pegout_address'], $lk['pegout_entity'], $lk['confidence']];
                    }
                } else {
                    $rows[] = ['pegin', $pin['txid'], $pin['amount'], '', '', '', '', ''];
                }
            }
            foreach ($t['pegouts'] as $pout) {
                $lk = $pout['links'][0] ?? null;
                $rows[] = ['pegout', $lk['pegin_txid'] ?? '', $lk['pegin_amount'] ?? '', $pout['txid'],
                           $pout['amount'], $pout['address'], $pout['attribution']['entity'] ?? '', $lk['confidence'] ?? ''];
            }
            csv_out('mwebscan_trace_' . $q . '.csv',
                ['side', 'pegin_txid', 'pegin_amount', 'pegout_txid', 'pegout_amount', 'pegout_address', 'pegout_entity', 'confidence'],
                $rows);
        }
        out(['trace' => $t]);

    case 'follow':
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if ($q === '') {
            err('q (address) required');
        }
        $depth = isset($_GET['depth']) ? max(1, min(10, (int) $_GET['depth'])) : 3;
        out(['follow' => mwebscan_follow($db, $q, $depth)]);

    case 'address':
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if ($q === '') {
            err('q (address) required');
        }
        $st = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) total FROM mweb_pegouts WHERE address = ?");
        $st->execute([$q]);
        $pegouts = $st->fetch(PDO::FETCH_ASSOC);
        $st = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) total FROM mweb_pegins WHERE source_address = ?");
        $st->execute([$q]);
        $pegins = $st->fetch(PDO::FETCH_ASSOC);
        out([
            'address' => $q,
            'attribution' => mwebscan_attribution($db, $q),
            'pegouts_received' => ['count' => (int) $pegouts['c'], 'total_ltc' => $pegouts['total'] + 0],
            'pegins_funded' => ['count' => (int) $pegins['c'], 'total_ltc' => $pegins['total'] + 0],
        ]);

    case 'links':
        if (!mwebscan_table_exists($db, 'mweb_links')) {
            err('analysis has not been run yet', 503);
        }
        $minConf = isset($_GET['min_confidence']) ? (float) $_GET['min_confidence'] : 0.5;
        $limit = clamp_limit($_GET['limit'] ?? null);
        $st = $db->prepare("
            SELECT l.pegout_txid, l.pegout_amount, l.pegout_address, l.pegout_entity,
                   l.pegout_category, l.pegin_txid, l.pegin_amount, l.confidence,
                   l.block_gap, l.candidate_count, l.reasons, s.risk_score
            FROM mweb_links l
            LEFT JOIN pegout_scores s ON s.txid = l.pegout_txid AND s.vout = l.pegout_vout
            WHERE l.confidence >= ?
            ORDER BY l.confidence DESC, l.block_gap ASC
            LIMIT ?
        ");
        $st->execute([$minConf, $limit]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($format === 'csv') {
            $csv = [];
            foreach ($rows as $r) {
                $csv[] = [$r['confidence'], $r['risk_score'], $r['pegout_txid'], $r['pegout_amount'],
                          $r['pegout_address'], $r['pegout_entity'], $r['pegout_category'],
                          $r['pegin_txid'], $r['pegin_amount'], $r['block_gap'], $r['candidate_count']];
            }
            csv_out('mwebscan_links.csv',
                ['confidence', 'risk_score', 'pegout_txid', 'pegout_amount', 'pegout_address',
                 'pegout_entity', 'pegout_category', 'pegin_txid', 'pegin_amount', 'block_gap', 'candidate_count'],
                $csv);
        }
        foreach ($rows as &$row) {
            $row['reasons'] = json_decode($row['reasons'], true);
            $row['confidence'] = $row['confidence'] + 0;
        }
        unset($row);
        out(['min_confidence' => $minConf, 'count' => count($rows), 'links' => $rows]);

    case 'pegin_amounts':
        $limit = clamp_limit($_GET['limit'] ?? null);
        $st = $db->prepare("
            SELECT ROUND(amount, 1) AS amount, COUNT(*) AS count
            FROM mweb_pegins
            GROUP BY ROUND(amount, 1)
            ORDER BY count DESC, amount ASC
            LIMIT ?
        ");
        $st->execute([$limit]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($format === 'csv') {
            csv_out('mwebscan_pegin_amounts.csv', ['amount', 'count'],
                array_map(fn($r) => [$r['amount'], $r['count']], $rows));
        }
        foreach ($rows as &$row) {
            $row['amount'] = $row['amount'] + 0;
            $row['count'] = (int) $row['count'];
        }
        unset($row);
        out(['count' => count($rows), 'pegin_amounts' => $rows]);

    default:
        err('unknown endpoint: ' . $endpoint, 404);
}
