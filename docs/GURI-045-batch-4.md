# GURI-045 batch 4: Lore and resilient Venal access

Six linked public lore briefings cover Fatal, the Rabbit, Venal, Crielere, Zarzakh/Deathless and insurgencies. Each links an official source, a related practical tool and the next dossier. Summaries are fan-written, not new canon. Uncertain accounts of Fatal remain uncertain. Sources were checked October 7, 2026. No invented portraits or historical completion rewards.

Production browser diagnosis: Three.js fails to create a WebGL context before the existing fetch handler. The error is specific to this browser's graphics environment; it does not establish that the site or ESI is down. A classic startup script now catches module import and renderer failures, reports the problem, and points to a usable alternative. The system directory link is available before JavaScript starts, including when the CDN, module script or graphics support fails. Slow startup and data requests have explicit handling.

`/venal/systems/` renders 95 systems from the official SDE build 3579973, retrieved October 6. Server-side search works with JavaScript disabled. Optional activity loading uses the existing Venal API, times out, labels old/undated/future timestamps, and never equates zero reported kills with safety. Geography does not depend on an external request. Refresh failures clear old activity cells instead of silently retaining old counts.

Homepage: remove the blocking connection sequence; link the sourced dossiers from the lore section. Primary Lore navigation opens the real directory. Signals links the staff-curated broadcast archive from batch 3. Discovery links use the graphics-independent Venal directory.

Validation: cumulative PHP syntax; public page rendering, directory search and escaped query inputs; activity freshness tests; JavaScript syntax and deployment script syntax; HTTP checks for public routes and invalid dossier 404. Existing community/industry/campaign tests retained. Production graphics failure observed directly in cloud browser. New code has not yet received a deployed visual review.

## Still open

See `docs/GURI-045-public-inventory.md`. In particular, batch 3 implemented editorial external-link publishing, not the requested image upload/moderation pipeline, an internal ordered comic reader, structured quantity reservation, or verified aggregate contribution progress. These remain software work, not media blockers. This batch does not mark those promises completed. Additional audio versions, approved artwork/comic episodes, real fleet schedules and real supply terms remain named content inputs. Funding and private enrichment stay in their separate tickets.

The installer accepts reviewed earlier batch files and the original baseline. It can install the cumulative package even if an earlier batch introduced files not yet present locally. Unexpected existing edits still stop installation. Publishing commits only the manifest, merges to main and runs backed-up deployment. Runtime data and server secrets remain preserved.
