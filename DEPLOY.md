# Deploying MWEBscan

## Pre-launch checklist

- [ ] Sync the chain: run `mwebp2p.py` (node-less) or `mwebscan.py` (full node) until caught up to the tip.
- [ ] Run `python3 mwebanalysis.py` so the links / clusters / scores / entity-flow tables exist. The site is mostly empty until this runs.
- [ ] Load labels (`tools/label_ofac.py`, `tools/label_pools.py`) or attribution will be sparse.
- [ ] Deploy to a server and open every page in a browser: homepage, trace a real address, charts, the API (`/api?endpoint=stats`), the dark-mode toggle, and a phone width. PHP only runs on a real server, so this is the real smoke test.
- [ ] Set `MWEBSCAN_RATE_SALT` (or accept the auto-generated `.rate_salt`), serve over HTTPS.
- [ ] Confirm DNSSEC is on for the domain (the OpenAlias donation records depend on it).

## Hosting

- Point Apache (or your web server) at the repo directory. Pages and `/assets` are public; everything else is denied by `.htaccess`.
- `.htaccess` only applies if `AllowOverride` is enabled for the directory. After deploy, confirm `mwebscan.db`, the `.py` files, and `/.git/config` are not downloadable.
- Keep `mwebscan.db` outside the webroot if you can, and point `mwebscan_db()` (in `lib/db.php`) at its path. Otherwise it is protected only by `.htaccess`.
- Backups: the DB is reproducible from the chain (re-scan with `mwebscan.py`, or re-sync with `mwebp2p.py`), so DB backups are optional. Do back up your curated labels (`labels*.csv`), which are sourced and not reproducible.
- PHP 7.4+ (arrow functions) with the **`pdo_sqlite`** extension (package `php-sqlite3` or `php-pdo`). Without it, `new PDO('sqlite:...')` throws "could not find driver" and every DB-backed page returns 503. Check with `php -m | grep sqlite`.

## systemd

The shipped units (`mwebscan.service`, `mwebp2p.service`, `mwebanalysis.service` + `.timer`) are templates: edit the `/path/to/...` and `User=` values before enabling. `WorkingDirectory` must be the directory containing `mwebscan.db`, since the scripts open the DB by relative name (a wrong working directory silently creates a second, empty DB).

Run exactly one block source: `mwebscan.service` or `mwebp2p.service`, never both (they share the scan cursor). The `mwebanalysis.timer` runs the analysis pass every 15 minutes; run `mwebmonitor.py check` after each pass if you use watchlists.

## Secrets

- RPC credentials come from `LTC_RPC_URL` / `LTC_RPC_USER` / `LTC_RPC_PASSWORD`. Never commit real credentials.
- `MWEBSCAN_RATE_SALT` salts the IP hashes in the API rate limiter. If unset, a random salt is generated and stored in `.rate_salt` (gitignored).
- The rate limiter keys on `REMOTE_ADDR` (the direct client IP), not the spoofable `X-Forwarded-For`. Run Apache directly, or if you put MWEBscan behind a reverse proxy or CDN, configure a trusted-proxy real-IP module (such as `mod_remoteip`) so each client gets its own bucket instead of all clients sharing the proxy's IP.

## Tuning

- `getblock` (verbosity 2) is fetched in small `GETBLOCK_BATCH`-sized requests (default 4), concurrently across `RPC_WORKERS` threads. Large batches are avoided: ~100 blocks in one response exceeds 1 GB and breaks the connection.
- `BATCH_SIZE` is blocks per pass (bounds peak memory, a few MB each). Defaults suit ~4 GB hosts; see the knob comments in `mwebscan.py` for big-box and Raspberry Pi presets.
- `pip install orjson` for faster JSON decoding (auto-detected).
- Secondary indexes are built once after the initial catch-up (`build_indexes()`), so index maintenance does not slow the bulk insert.
- Node side, a higher `-dbcache` and adequate `-rpcworkqueue` / `-rpcthreads` help block-read throughput. Make sure the node is fully synced first; `getblock` is only fast for blocks already on disk.
- Leave `TRACK_PEGIN_SOURCES` off during the initial sync; it adds an RPC lookup per peg-in input.

## Durability

The scanner uses WAL with `synchronous = NORMAL` (crash-safe) and advances its scan cursor only over contiguously-fetched blocks, so a transient failure is retried rather than leaving a gap. Reorgs up to `REORG_DEPTH` (12) blocks are detected and rolled back; the node-less daemon verifies proof-of-work and merkle roots and bounds rollbacks to the same depth.

## Known limitation

Amounts are stored as SQLite `REAL` (float); exact-match linking rounds to litoshi to compensate. Integer-litoshi storage would be more precise but needs a schema change and rescan.
