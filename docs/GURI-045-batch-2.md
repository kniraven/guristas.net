# GURI-045 Batch 2: Industry, LP & Fulcrum

This cumulative patch includes Batch 1. Keep GURI-045 open for community publishing, lore and production usability verification.

## Public tools

`/industry/` has four sections:

- Production: 28 manufacturing recipes from the official JSONL SDE (build 3579973, October 6, 2026). Whole-job rounding, ME/facility factors, per-run single-unit floor, TE/Industry/Advanced Industry/time reductions, finished output, time estimate, priced material shopping list, job/BPC/acquisition and travel costs, fees and estimated surplus. User-triggered station pricing consumes the actual material quantities with at most two browser requests in flight. Missing depth stays missing; manual input remains available.
- Commando Guri LP: 374 snapshot offers with a public ESI refresh, required items and ISK costs, blueprint-production links, net proceeds and a complete conversion ledger. Requires entered costs; no personal balance or wallet access. Offers needing another currency are excluded from automatic valuation. Blueprint copies are distinguished from finished products. Actual BPC runs and efficiencies must be checked in EVE.
- Market comparison: supported Guristas/LP items and manufacturing inputs at Jita, Amarr, Dodixie, Hek, Rens and The Fulcrum. Complete quantity-weighted immediate buy/sell quotes, minimum buy volumes, available depth, pagination, freshness, recent regional turnover when available, and batch surplus after entered costs. Lowest/highest complete quotes are identified without claiming a safe/profitable travel plan. Changing item/quantity invalidates prior quotes.
- The Fulcrum: public NPC station stock and buy-order depth. Official SDE station ID 60015187, Zarzakh region ID 10001000. Public regional orders for a Worm were successfully retrieved. No new SSO scope is required for these station orders. A stock gap is not treated as a contract, guaranteed demand or verified contribution.

All four sections work without login. Shared public API caches contain market data only, never pilot records or tokens. The private universal enrichment tool in GURI-006/007 is not exposed or replaced by this public Guristas tool.

## Homepage and access

Build navigation goes to the public console. The disabled migration entry, sample hull counts, unverified Fulcrum ME/TE percentages and illustrative stock-progress meter are removed. Homepage cards lead directly to production, LP, market and Fulcrum views. Existing Batch 1 dossier/public separation is retained.

## Limits and remaining work

- No automatic installation fee calculation or structure-bonus lookup is claimed. Enter the actual job/BPC/facility costs from EVE. Compare the completed estimate with the in-game job before submission.
- Zero fees/other costs are explicitly labelled potentially gross estimates. Self-mined/owned inputs are not assumed free.
- Station-listed orders only. Ranged orders listed elsewhere and Upwell structure books are not included. All selected NPC-station order pages must be fetched, with a safety limit of 20 pages; failures do not become zero prices.
- Regional turnover is not station turnover. Missing history is unknown, not zero demand.
- Market books change during travel. Competition, route safety and actual future fills are unknown. No universal trade ranking, automated order placement or guaranteed income.
- Official recipes/offers can change. `tools/build-industry-reference.py` rebuilds the compact reference from an official JSONL SDE ZIP and a downloaded public offer list. Live offer refresh uses ESI; dated snapshot remains labelled when unavailable.
- Fulcrum supply-job priorities, posted rewards, claims and contribution evidence/review require the next Community Publishing & Operations batch. The site does not fabricate jobs, delivery credit, artwork or episodes.

## Validation

Run `node tests/test_public_industry.js`, `php tests/test_public_industry.php`, `php tests/test_public_pages.php`, `node tests/test_public_campaign.js`, PHP/JS syntax checks and `git diff --check`.

Tests cover job-level rounding, multiplicative efficiency, single-unit floor, invalid quantities, LP cost calculations, negative conversions, missing inputs, station filtering, weighted order depth, buy minimum volumes, thin books, paginated retrieval and cache reuse. HTTP smoke checks cover all public views and reject malformed quote requests. Live endpoint verification confirmed the public Commando Guri offer list and Fulcrum region order access. A production visual/Twitch/audio review remains required after deploying the cumulative package.

## Deployment

Create or resume `GURI-045-public-features` from current clean main. This ZIP's installer accepts reviewed original and Batch 1 file hashes, validates package files and PHP syntax, saves prior files outside the repo, and copies only the manifest entries. Unexpected differences stop for review.

Stage `docs/GURI-045-batch-2-files.txt`. Commit/push the branch, merge into updated main and push main. `tools/deploy-public-features.ps1` deploys the cumulative committed manifest with server backup, rollback and route checks including `/industry/`. Existing secrets, uploads and pilot records are preserved.
