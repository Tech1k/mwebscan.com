# Contributing to MWEBscan

Thanks for helping. The most valuable contributions are sourced address labels
and bug fixes. The project is AGPL-3.0 (see [LICENSING.md](LICENSING.md)).

By submitting a contribution (pull request, patch, label data, or similar) you
agree that:

- you license your contribution to the public under the **AGPL-3.0** (inbound = outbound);
- you also grant Tech1k (the copyright holder) a perpetual, worldwide, irrevocable
  right to use, modify, and relicense your contribution under other terms, including
  commercial or non-AGPL licenses (this is what keeps the project's dual-licensing
  possible); and
- the contribution is your own original work, or you otherwise have the right to
  submit it under these terms.

If you would rather not grant the relicensing right, note that in your pull request
and we can discuss alternatives before merging.

## Address labels (the highest-impact contribution)

Litecoin is under-labelled, so good labels are the project's real moat.

- **Mining pools:** add coinbase tags / payout addresses to
  [`tools/pools.json`](tools/pools.json). These are easy to verify (the pool
  stamps its tag in the coinbase).
- **Exchanges / services / mixers / sanctioned:** submit a CSV
  (`address,entity,category,confidence,source`) - see the importers in `tools/`.
- **Golden rule:** only submit sourced attributions. Every label needs a
  real `source` (a proof-of-reserves page, OFAC entry, dataset, etc.). A wrong
  label is worse than no label: it can defame a real person or entity. When
  unsure, leave it out.

Categories: `exchange, service, pool, mixer, gambling, merchant, sanctioned, other`.

**MWEBscan prefers fewer sourced labels over broad unsourced attribution:** a small
accurate set beats a large sloppy one. Do not force coverage.

Submit a new label, a correction, or a removal by opening a
[label submission issue](https://github.com/Tech1k/mwebscan.com/issues/new?template=label_submission.md)
or emailing hello@tech1k.com with: address, entity, category, source URL or evidence,
confidence (if known), and whether it is new / a correction / a removal. A label is a
contribution under the grant above, so by submitting you confirm you have the right to
submit it and grant Tech1k/MWEBscan permission to publish, modify, redistribute,
sublicense, and include it in MWEBscan datasets, APIs, and exports. Labels are heuristic
metadata, not proof of ownership or wrongdoing; we may edit, merge, or remove them.

## Code

- **Python:** the scanner (`mwebscan.py`), analysis (`mwebanalysis.py`), monitor,
  label loader, and `tools/`. Standard-library-first; `requests` for RPC,
  `pyyaml` only for the GraphSense importer.
- **PHP:** the site + API. Read the SQLite DB only; never write user data.
  Always use prepared statements and escape output (`htmlspecialchars`).
- **Style:** match the surrounding code. Keep functions small and commented.

## Run the tests (no node needed)

```bash
python3 tests/test_scanner.py   # block-parsing / reorg / batch-gap
python3 tests/test_labels.py    # label-importer parsing
python3 tests/test_p2p.py       # node-less P2P parser (PoW, merkle, malformed input)
python3 tests/test_smoke.py     # full analysis + monitor pipeline (+ PHP lint if php-cli)
```

Please keep these green and add a test when you change parsing/analysis logic.

## Reporting an incorrect attribution

If a label about you or your entity is wrong, open an issue or email the contact
on the site's [disclaimer](disclaimer.php) - we'll review and correct or remove it.
