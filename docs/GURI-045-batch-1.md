# GURI-045 Batch 1: Public Field Network & Signals

## Result

Public routes: `/war/guristas/`, `/missions/`, `/join/`, `/signals/`.
Visitors can read campaign intelligence, search 70 Guristas agent contacts, discover epic/COSMOS missions, follow enlistment steps, play two existing fan-music demos, read companion lyrics, load Twitch on demand and reach recording archives without login.

The dossier keeps personal standings/access, skills/readiness, location-based actions, FW career, kill records, romance progress, achievements and saved settings. General campaign explanations and special-mission references link to public pages. The FW dossier no longer fetches campaign/travel data solely to display reference text.

Homepage entry buttons lead to beginner enlistment and current campaigns. Disabled campaign entry and fabricated forecast meter are replaced. Campaign status comes from the existing public API, with fresh/stale/failure states. Twitch status is no longer falsely called offline. Scanner destinations lead to actual tools, labelled fictional discovery.

## Rules and sources checked October 7, 2026 UTC

- Pirate enlistment: newer Version 23.01 patch notes require 0.0 base standing. Older support text still says -2.0, so the guide explains the conflict and links the newer source.
- Version 24.01: maximum contribution 45%, maximum winning payout 1,500,000 LP, losing multiplier 0.4, spread stage 3, static Large ADV-1 with 40-minute respawn, 48-hour forecast.
- Public campaign feed already implemented in `FrontlinesService`, with EVE Ref fallback.
- NPC metadata uses the existing CCP static reference retrieved October 6, 2026.
- Fan-music demos and Fatal Mistake lyrics recovered from existing files. Demo labels retained; Good Mourning is not presented as a playable release.
- Federation Front Line Report destinations verified against its own podcast page.

The public map is a stargate schematic, not route safety intelligence. It does not choose an automatic destination without pilot location. Missing/old feeds cannot establish a current destination. Personal contribution, current sites and special-mission history remain unavailable.

## Subtask coverage

Implemented in this batch: campaign status and public feed/map, public FW instructions, public mission directory and special-mission guide, Guristas enlistment, practical Cozen contact path, radio playback, lyric sheet, optional animation, Twitch embed, broadcast destinations, scanner discovery links.

Partial: ship guidance links to the existing explorer; ship classification work stays in GURI-039. Broadcast archive is currently the Twitch recordings destination, not a permanent local episode catalog. Album contains the two recovered demos; additional track versions, credits, covers and annotations remain editorial work. No claim of fleet schedules, market stock, verified contributions or missing media is invented.

## Remaining named batches, keep GURI-045 open

1. **Industry, LP & Fulcrum:** authoritative blueprint calculations; market fees/prices/volume and travel context; public Commando Guri conversion and Guristas buy/sell tools; scoped Fulcrum stock/supply. Private enrichment tool remains separately access-controlled. Resolve source and station/structure coverage before claiming live shortages.
2. **Community publishing & operations:** staff publishing for fleets, after-action reports, supply jobs, contribution evidence/review, gallery, comics, propaganda and permanent episodes. Build workflows and meaningful empty states; publish assets only with the required files, attribution and metadata.
3. **Lore & final usability:** sourced dossiers, reusable links between content/tools, Venal failure diagnosis/list fallback, asset release selection, responsive production review and completion of the remaining 32-subtask inventory.

## Validation

Run `node tests/test_public_campaign.js`, `php tests/test_public_pages.php`, PHP lint on package PHP files, JavaScript syntax checks and `git diff --check`. The PHP test uses CLI subprocess rendering, checks unauthenticated pages, directory search, empty states and HTML escaping. Campaign tests check stale/future/missing timestamps, ended campaigns and filters.

Before marking ticket tasks complete, install locally, verify the public routes on desktop/mobile, then deploy and confirm the production feed, audio, Twitch and dossier links. External data and Twitch availability depend on those services. This package does not claim a completed production visual review.

## Install and deploy

Use the ZIP's `Install-GURI045.ps1` after creating `GURI-045-public-features`. It validates old/new file hashes (normalizing Windows text line endings), checks PHP syntax using XAMPP, saves a backup outside the repo and copies only listed files. A mismatch stops before copying, so share that error rather than forcing it.

Stage only `docs/GURI-045-batch-1-files.txt`. Commit and push the branch, merge it into current `main`, push `main`, then run `tools/deploy-public-features.ps1`. Deployment uses committed files, a checked manifest, a verified server backup, rollback on failure and public route health checks. Secrets, uploads and pilot records are preserved.
