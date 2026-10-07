# Guristas dossier usability update

Reviewed signed-in production account after GURI-002 deployment (7658ab5).

The initial screen prioritized character creation details and preferences over progression. Repeated threshold cards, long agent calculations, and the full standings explorer made the page difficult to scan. Enlisted players received redundant enlistment guidance. Some corporation cards claimed ordinary mission thresholds where no ordinary agents are recorded.

The revised page begins with a compact identity header, faction trust, one next recognition, one context-sensitive action, and a current recognition shelf. Profile/settings, relationship tracks, mission-agent shortlist, calculations, rules, and standings explorer are native disclosures. All details remain available with JavaScript disabled. Hash links enhance opening their destination when JavaScript is available.

Site recognition uses raw standings and is explicitly current evidence, not permanent achievement history. Effective values are used for mission eligibility. The progress bar measures the current recognition stage, rather than progress from zero. Recommendations prefer higher ordinary agent levels, with complete checks preferred at the same level. Incomplete relationships remain labeled. The shortlist is not a nearest-agent recommendation. Corporations without relevant ordinary agents get no invented mission unlocks. Stale standings do not verify recognition or produce a new recommendation.

No authentication changes, additional scopes, database migrations, or stored achievement history. Existing public/private data and missing-response handling remain.

Validation: changed PHP files linted and seven PHP suites plus the JavaScript suite pass on PHP 8.3. Regression checks cover enlisted guidance, missing/stale data, raw vs effective goals, stage progress, agent priorities, collapsed detail and dossier-before-settings order. A rendered HTML preview was generated, but this cloud browser cannot access the local preview server. The updated layout still needs a local browser check on desktop and narrow width before committing/deploying.


## Version 2

Five server-rendered navigation views: Overview, Standings & Agents, Faction Warfare, Achievements, Settings. Links retain browser history and direct URLs, and work without JavaScript. Settings saves return to Settings and that view skips private ESI reads.

Primary recommendations exclude incomplete personal/corporation/faction access checks. Every ordinary Guristas agent is evaluated, rather than only the displayed alphabetical shortlist. Highest mission level wins among fully evaluated candidates, then strongest reported personal relationship, then alphabetical order. Ordinary level 1 remains the fallback; travel distance, safety, readiness and payout are explicitly not ranked. The recommended agent is included in the display list. Incomplete access is labeled Check access first.

Agent profiles add recorded race and locator-service availability from CCP npcCharacters.jsonl; affiliations, portraits, division, level and public station-name resolution remain available. No invented biography. Aakie and Haatoluppa have no biography field in the reference inspected.

Seven PHP fixture suites and the JavaScript suite pass. Regression checks include rejecting incomplete higher-level agents, evaluating agents outside the old shortlist, per-view content, stale evidence and settings order. Local visual review and PHP 8.0 checks remain required.


## Version 3

Recommended action includes NPC portrait, recorded race, division work, affiliation, base and selection rationale. No biography is fabricated. Official EVE standing rules opens in a new tab with noopener/noreferrer. Fenris Creations announced its rebrand on May 6, 2026 (https://fenris.com/news/2026/studio-behind-eve-online-goes-independent-rebrands-as-fenris-creations-enters-research-partnership-with-google-deepmind). The existing EVE support standings URL remains the rules source.

Overview has a compact achievement shelf, Standings & Agents has relationship achievements, and FW has current enlistment plus next kill/contribution goals. Achievements collects all 13 definitions. Relationship progress shows raw-standing or agent-count distances. FW uses career total/yesterday/last-week kills and victory points, enlistment date and rank fields already authorized. FW goals require current ESI-reported Guristas enlistment; totals may include other militias and are explicitly not Guristas-only kills or LP. No new ESI scopes, inferred plex completions, permanent achievement history, or invented medals.

Regression checks cover exact remaining FW totals, stale-FW rejection, contextual shelves and safe external link. All seven PHP suites and JavaScript checks pass.

## Revision 4
Overview holds mission, FW and epic-arc availability actions. It shows a brief accomplishment summary; sections use milestone rows and the Achievements view uses full cards. Standing recognition extends through +7 and the +10 upper bound. FW contribution extends through one million and adds higher decimal milestones as needed; there is no claimed personal maximum. Epic availability is checked in The Agency, not inferred from ordinary-agent standing rules.

Future evidence: epic/COSMOS completions need explicitly player-reported or reviewed records; current ESI has no mission completion journal. Killmail ingestion can verify participation while flying a Guristas hull by matching attacker character and ship IDs, deduplicating killmail IDs, excluding losses, and storing evidence. Recent ESI killmail history is 90 days, not lifetime. A future required killmail permission belongs in the main login with its purpose explained. Romance would be optional site-authored NPC fiction with independent affinity and persistent choices, never presented as official EVE lore or agent access.

## Revision 5: illustrated actions, live campaigns, combat and romance

- Every Overview action has an icon or NPC portrait. Secondary story/combat/epic cards use a compact grid. Action recommendations remain in Overview.
- The existing official war-report feed (community mirror fallback) now retains campaign state, FOB, start/end fields and win targets. Freshness must be within 15 minutes with no stale flag before a destination is suggested. Active advice prioritizes unfinished, unsuppressed systems approaching corruption stage 3; it explains this heuristic and does not predict the next expansion system, safe routes or fit readiness. Forecast live-start times are labelled estimates based on 48 hours after the forecast start field. No announcement means no invented next start.
- September 22, 2026 rules: stage 3 spread; static Large ADV-1, 40-minute respawn; seven-day campaigns; 48-hour forecasting. Maximum contribution is **45% personal corruption/suppression**, paying up to **1,500,000 LP on a win**. Career FW victory points are a separate counter and have no claimed personal cap. Personal contribution is not exposed by the feed/current ESI and is never inferred from career statistics.
- Epic action shows the recorded Yada Vinjivas profile and actual Guristas faction/corporation standing evidence, with availability left to The Agency. Current ESI exposes no personal epic cooldown, mission journal or COSMOS completion/prerequisite endpoint. Wallet or standings changes are not substituted for proof.
- Required main-login scope: `esi-killmails.read_killmails.v1`, explained in the login modal and enforced by the existing all-required consent policy. Existing sessions must sign in again. Ensure that scope is enabled for the registered EVE developer application before testing login. No new mission-related permissions are requested.
- Guristas Hulls imports recent ESI killmails when that section is visited. Other views use an authorized saved snapshot without starting an import. It checks the newest and one historical page per visit, with up to 12 unseen details fetched in a public batch (six concurrent). Recent history is 90 days, not lifetime; repeated visits continue imports. Errors leave pending entries retryable. Only a matching player attacker in a hull classified as Guristas by the existing ship reference earns credit. Player losses, NPC/structure kills and other hulls are excluded. Participation and final blows are distinct, and killmail IDs are deduplicated. Totals are recorded evidence, never an assumed complete career total.
- Combat achievements cover overall thresholds plus first blood in each recorded Guristas hull. They retain verified evidence after ESI history expires, subject to continuing authorization.
- Romance is an optional original adult NPC story, Ren Vey: three scenes, private choices, affinity and distinct endings. It grants site relationship achievements independently of EVE standings. Pause preserves progress. A submitted scene number must equal the saved chapter, preventing replay farming and stale-form double submissions.
- Persistent progress is outside the public document root at `storage/pilot-record/<token-table>/<character-id>.json`. It uses file locks, atomic writes and restrictive permissions; local/production namespaces differ. This directory must be writable by PHP and included in backups. **Do not clear it as a cache or deploy a clean copy over it.** No SQL migration is needed. CSRF-protected story mutations always use the session character.

Sources reviewed October 6, 2026:
https://www.eveonline.com/news/view/patch-notes-version-24-01
https://www.eveonline.com/news/view/cradle-of-war-major-update-is-live
https://support.eveonline.com/hc/en-us/articles/360009908300-Epic-Arc
Current official ESI OpenAPI specification (API Explorer): killmail references, attacker hull data, 90-day history; no epic/COSMOS mission-availability endpoint.

Validation: eight PHP suites plus JavaScript UI checks, including private grant isolation, consent rejection, pagination, duplicate/loss/NPC exclusions, story persistence/replay denial, stale campaigns, forecast/no-announcement handling and escaped section rendering. Production login and real-character killmail import still require testing after applying the update.

## Revision 6: spacing, reference placement and personal hull goals
Agent cards now stretch within each grid row and place their details controls at the bottom. Milestone rows have horizontal padding and full-width progress content. Epic-arc material is a permanent Special missions field guide in Standings & Agents, alongside an explicitly community-sourced Guristas COSMOS guide. Overview retains a small discovery link instead of a recurring epic next-action card.

Combat action suggests the hull with the most verified recorded kills; ties use latest activity and then hull ID. It shows the remaining kills to the next already configured overall combat achievement, counting all Guristas hulls. There is no fabricated achievement beyond 500. Missing history remains unknown; an empty or unavailable record uses a clearly labelled Worm starting suggestion rather than claiming training, ownership, affordability or fitting suitability. No larger-hull ladder or new permissions were added.

Validation: PHP and JavaScript suites passed. Hull tests cover mixed-hull progress, tie breaking, unavailable history and completed achievement tiers. Placement tests cover the persistent reference and Overview link. Visual browser verification of the updated CSS remains for the local application.

## Revision 7: automatic practical recommendations

GURI-002 was merged to main at 7658ab5 in the supplied Git output. This package completes the subsequent dossier recommendation refinement.

Main login now requires seven read-only scopes. Add `esi-location.read_location.v1`, `esi-location.read_ship_type.v1` and `esi-assets.read_assets.v1` to the registered EVE developer application before deploying. Each purpose appears in the login dialog. Existing sessions must sign in again; declined required permissions prevent account login. No wallet, saved-fitting or additional mission scopes are requested.

All active skill levels are retained. The checked-in readiness reference contains the 14 Guristas hulls and recursive skill prerequisites from official ESI universe/types (compatibility date 2026-09-14), retrieved October 7. Hull prerequisites are factual; the drone/shield support baseline and hull-class mission guidance are site heuristics. Basic guidance uses Drones, Drone Avionics, Power Grid Management, Shield Operation and CPU Management at level 2 for level 1 activity and level 3 for level 2/3 guidance, or level 4 for level 4 guidance. Level 3/4 additionally requires Drones V and Drone Interfacing III/IV. This does not check a live fitting, guarantee survival or require users to select a fit. Capital and level 5 group missions are excluded from automatic solo advice.

Location and current ship are authenticated and cached according to ESI expiry. Assets are scanned completely, up to 20 pages, with a consistent page count and no failed page permitted. Only Guristas hull evidence is retained in the normalized page model. Asset safety, nested containers/ship holds and copies are excluded from accessible-hull evidence. Public station/structure docking access and fitting quality remain unknown. Fresh skill and ownership evidence alone influence readiness; stale or revoked context does not qualify a hull. A missing inventory is unknown, not zero.

Security-agent selection checks complete standing access, then hull/support evidence. Current/local hull availability comes first, then shortest known stargate travel, mission level, existing agent relationship and name. Unknown readiness uses ordinary level 1 guidance. Three alternative agents are shown in Overview if the offered mission, route or access is unsuitable. Other divisions remain discoverable in mission options. Public shortest-route lookups use no bearer token and are cached and bounded to 24 distinct destinations with six-request batches. Routes omit safety, docking permission, jump bridges and live opposition. A failed batch stays unknown.

Combat goals filter active hull prerequisites and support skills, favor current/local/owned hulls, then unfinished first-blood recognition and smaller hull classes. Within 10% (minimum two kills) of an existing overall milestone, available smaller hulls are retained. No highest-kill-count lock-in, automatic bigger-ship progression, fabricated achievement above 500, or false skill unlock. Rare prize hulls require ownership evidence. A smaller class is a cost-conscious preference, not a market-price comparison.

FW advice prefers known shortest travel, lower suppression, then campaign progress. It never estimates LP/hour, win probability, exact earnings, live plex availability or opponents. Unavailable distances show a campaign shortlist without claiming a best destination. The win/loss incentive and unavailable personal contribution are explained. Lower-demand eligible sites and fleet coordination are fallbacks. Career VP is not personal campaign contribution. No blanket win-first priority remains.

Validation: nine PHP suites and JavaScript checks pass, plus PHP lint. Regression tests cover active vs trained skills, insufficient support skills, standing-only high-level rejection, alternative hull milestones, unknown/stale context, complete asset pagination, authorization-before-cache and public route credential isolation.

Deployment: `tools/deploy-dossier.ps1` requires a clean main matching origin/main, archives only the committed paths in `docs/dossier-package-files.txt`, uploads through the existing EC2 SSH identity, and invokes the server helper. The server lints PHP before writes, backs up the live site including pilot records, applies only manifest files, and restores prior application files on a detected deployment failure. Storage, uploads and local secret configuration are not in the manifest. The homepage smoke check is not a signed-in dashboard test. Sign in again and verify account recommendations after deploy. Multi-file deployment is not transactionally atomic, although individual file replacement is atomic.

Production review attempted October 7 UTC: the account URL redirected to the public home page and its login dialog requested the original three scopes. The latest cumulative dossier package is therefore not verified as deployed. The cloud session needed authentication; its secure login request and recovery timed out. The signed-in fold, navigation, interactive feedback and accomplishment discoverability review remains pending after deployment and a working authenticated browser session. No new production UX findings are claimed from the blocked account view.
