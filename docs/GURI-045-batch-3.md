# GURI-045 batch 3: Community Publishing & Operations

Public Operations and Transmissions boards require no login. Existing staff publish fleets, supply jobs, field reports, art, comics and recordings through `/admin/community/`. Drafts and archives remain private. Expired fleet departures and supply deadlines leave the public board automatically. Media opens on its approved external host; the site does not upload or embed unreviewed files.

Supply deliveries require the main login and CSRF token. Evidence stays private to the submitting pilot and staff. Duplicate pending deliveries are rejected. A different staff officer must verify delivery in EVE and enter a review note. This ledger is an attestation, not automatic contract verification, a payment system or a reservation system. Contact the organizer before hauling. Rewards and quantities belong in the job's instructions. No public leaderboard exposes character activity.

Staff edits use a locked, atomic persistent JSON ledger and a revision check. Runtime data resides in `storage/community`, excluded from application patches and included in the predeployment backup. Deployment creates the Apache writable directory without replacing its contents. No database migration or new ESI permissions.

## Remaining content blockers

- Fleets: real organizer, departure time, staging location, contact and beginner/ship instructions.
- Supply orders: responsible buyer, quantity, delivery destination, payment terms and review policy.
- Field reports: approved factual text from an organizer.
- Art: creator credit, permission and an approved HTTPS artwork or portfolio link.
- Comics: creator credit, permission and an approved HTTPS episode link. No new image files are required if an approved hosted episode already exists.
- Archives: approved recording link, title, description and creator credit. Live Twitch and radio remain on Signals.

These are editorial inputs, not missing application workflows. Nothing is published automatically from a placeholder. GURI-045 stays open: other batches and a signed-in production visual review remain outstanding. No fleet schedules, buyer promises or media were fabricated.

## Validation

PHP syntax, public page rendering, service checks for permissions, stale revision rejection, publication validation, persistence, duplicate delivery prevention, independent review, archive preservation and evidence exclusion. HTTP checks for both public boards and the staff sign-in gate. Browser visual review and authenticated production publishing remain required after deployment.
