# MWEBscan

> Open chain analysis and privacy intelligence for Litecoin's MimbleWimble
> Extension Block (MWEB). Site: https://mwebscan.com - License: AGPL-3.0

MWEBscan tracks peg-ins and peg-outs across the public boundary of Litecoin's MWEB
and correlates them with standard chain-analysis heuristics, published openly. The
same analysis powers a forensic view (who pegged out where, how linkable, to which
known entity) and a privacy view (how to keep your anonymity set large). Nothing
inside MWEB is visible to anyone, including us; only the public peg boundary is.

## Features

- Peg-in / peg-out tracking with a per-block supply, net-flow and activity time-series
- Round-trip linking, address-reuse detection, and per-amount privacy scoring
- Wallet clustering (common-input ownership), entity attribution, and entity flows
- "Follow the money" tracing across the MWEB hop by address, txid, or amount, including multi-hop
- Dual scoring: a privacy score per peg-in and an AML-risk score per peg-out
- Time-series charts and a live privacy-recommendation engine ("best amount / wait")
- Read-only JSON API, plus an address watchlist with webhook alerts
- Two ways to run: a full-node scanner, or a node-less daemon that needs no litecoind

## Quickstart

Node-less (no litecoind; the SQLite DB is the only storage):

```
python3 mwebp2p.py        # discover peers, sync + verify blocks, then poll
python3 mwebanalysis.py      # compute links / clusters / scores from the synced data
# then serve index.php with PHP, mwebscan.db alongside it
```

With a full Litecoin node (Core RPC):

```
export LTC_RPC_URL=... LTC_RPC_USER=... LTC_RPC_PASSWORD=...
python3 mwebscan.py          # create schema + sync from MWEB activation
python3 mwebanalysis.py      # analysis pass (re-run any time, or install mwebanalysis.timer)
python3 mweblabels.py        # optional: load entity labels
```

Run one block source - `mwebp2p.py` or `mwebscan.py`, not both (they share the
scan cursor). No node is needed to run the tests:
`python3 tests/test_scanner.py` / `test_labels.py` / `test_p2p.py` / `test_smoke.py`.

Node-less knobs: `MWEBSCAN_P2P_POLL` (poll seconds, default 60), `MWEBSCAN_P2P_COMMIT`
(blocks per commit, default 200), `MWEBSCAN_P2P_SOURCES` (`1` to resolve peg-in
sources via Electrum). Strategy is "bulk once, tail forever": seed the DB from a node,
then let the daemon keep up.

## How it works

A Python backend writes a SQLite DB; PHP reads it. No framework, no build step.

**Backend**
- `mwebscan.py` - full-node scanner (Core RPC): peg-ins, peg-outs (HogEx), supply/flow. Optional peg-in source capture (`TRACK_PEGIN_SOURCES`, needs `txindex=1`).
- `mwebp2p.py` - node-less scanner: fetches blocks from the P2P network (PoW + merkle verified), Electrum only for source-address lookups.
- `mwebanalysis.py` - analysis pass (pure SQLite): round-trip links, address reuse, clusters, attribution, scores, entity flows. Safe to re-run.
- `mwebmonitor.py` - address watchlist + webhook alerts (`add` / `list` / `remove` / `check`).
- `mweblabels.py` - load curated labels into the DB.

**Web** - `index.php` (homepage), `trace.php` (the explorer), `charts.php`, `api.php`
(JSON API), and `lib/` (shared DB layer, nav/footer, the reusable trace engine), all
reading the same DB. `methodology.php` documents every heuristic and its limits.

**Tools** (`tools/`) - the P2P + Electrum + parser library (`p2p_fetch.py`) and the
label importers (`label_ofac.py`, `label_pools.py`, `label_graphsense.py`).

Schema note: re-running the scanner against an older-schema DB fails. Delete
`mwebscan.db` and rescan after a schema change.

## API

Read-only JSON, path routed: `/api/<endpoint>` (e.g. `/api/stats`); the
`/api?endpoint=...` query form also works. Both need mod_rewrite;
`/api.php?endpoint=...` is the always-works fallback.

```
GET /api/stats
GET /api/privacy?amount=1.0          # wallet pre-flight privacy check
GET /api/recommendations             # live privacy guidance
GET /api/trace?q=<address|txid>      # full cross-MWEB trace graph
GET /api/follow?q=<address>&depth=3  # multi-hop follow-the-money
GET /api/address?q=<address>         # attribution + peg summary
GET /api/links?min_confidence=0.5&limit=100
GET /api/pegin_amounts?limit=100
```

Add `&format=csv` to `trace`, `links`, or `pegin_amounts` to download a CSV. Set
`$REQUIRED_KEY` in `api.php` to gate access (via `?key=` or `X-API-Key`) for a paid tier.

## Address labels

Attribution is only as good as your labels, and there is no big free bulk LTC label
set (LTC is under-labelled vs BTC/ETH), so most coverage is derived from the chain.
Load any `address,entity,category,confidence,source` CSV with
`python3 mweblabels.py <file.csv>` (it upserts, so files merge). Built-in importers:

- `tools/label_pools.py` - mining pools from `tools/pools.json` (coinbase tags + payout addresses)
- `tools/label_ofac.py` - OFAC SDN sanctioned addresses
- `tools/label_graphsense.py` - GraphSense TagPacks (needs `pip install pyyaml`)

Categories: `exchange, service, pool, mixer, gambling, merchant, sanctioned, other`.
For the full sourcing playbook (seed-and-cluster exchanges, external sources, the
golden rule on sourced-only labels) see [CONTRIBUTING.md](CONTRIBUTING.md).

## Deploy

See [DEPLOY.md](DEPLOY.md) for the production checklist, systemd units, Apache
hardening, and tuning. The essentials: serve over HTTPS, set `MWEBSCAN_RATE_SALT`,
keep `mwebscan.db` out of the webroot where possible, and run exactly one block source.

## License and Attribution

Code is AGPL-3.0 (see [LICENSE](LICENSE)): if you run a modified version as a network
service you must publish your source. MWEBscan-generated data and API output (computed
links, scores, entity flows, charts data, privacy recommendations, exported CSV/JSON,
and our own labels) are licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
unless otherwise noted; third-party labels remain subject to their original source terms.
See [LICENSING.md](LICENSING.md) (commercial, non-AGPL licensing is available). Site legal
pages: `terms.php`, `privacy.php`, `disclaimer.php`.

If you use MWEBscan publicly (in a wallet, explorer, dashboard, API, hosted service,
research report, or fork) you must give reasonable, visible attribution to MWEBscan and
Tech1k, such as "Data from MWEBscan by Tech1k" or "Powered by MWEBscan" with a link to
mwebscan.com or the upstream repository, and must not imply endorsement without written
permission. Full text: [ATTRIBUTION.md](ATTRIBUTION.md).

## Disclaimer

Links are heuristic, not proof: inferred from public-chain data only (amounts,
timing, anonymity-set size, address reuse, labels). Nothing inside MWEB is visible.
Do not treat attributions as fact.
