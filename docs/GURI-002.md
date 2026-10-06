# Character standings and faction warfare

## Data contract

`GET /api/account/pilot.php` is self-only: the character comes from the signed-in session, never from a query parameter. Signed-out requests return 401. Responses and the account page use private/no-store cache headers.

Each section has `state`, `data`, and `meta`. States are ready, stale, authorization_required, rate_limited, or unavailable. Missing authorization and errors return null data, not fabricated zeros. Empty standings are a valid result. An absent faction_id in a valid FW response means ESI did not report current enlistment. Rank fields remain null when unreported.

## Source verification

Reviewed CCP's current OpenAPI schema on 2026-10-06 UTC:
https://esi.evetech.net/meta/openapi.json
https://developers.eveonline.com/api-explorer
https://developers.eveonline.com/docs/services/sso/

- /characters/{character_id}/standings requires esi-characters.read_standings.v1. Returns NPC faction, corporation and agent standings, not contact standings. Schema cache TTL: 3600 seconds. Do not label these as calculated skill-adjusted standings.
- /characters/{character_id}/fw/stats requires esi-characters.read_fw_stats.v1. Kills and victory points contain yesterday, last_week and total. Enlistment date, faction and ranks are optional. The schema describes daily expiry at 11:05. These are not live killmail totals or LP balances.
- Use response cache headers, conditional ETags and the existing pinned compatibility date. The fallback is one hour; stale-on-transient-error is bounded by existing configuration.

## Consent and privacy

Basic login requests no private scopes and preserves existing scoped tokens. A signed-in user chooses Connect standings or Connect faction warfare through a CSRF-protected POST. Consent includes the selected feature plus previously granted enabled feature scopes. The callback validates the exact pending scope list and requires the same character for feature connections. Killmail consent is excluded until that feature ships.

The existing encrypted-token tables and key are reused, including the separate local token table. No database migration or private config edits are needed. CCP application registration must permit the two scopes before feature consent can succeed.

Private cache keys separate character, environment and grant identity; token values are not written to caches. Authorization is checked before every cache read. Private files use the existing 0640 writer. HTTP 401/403 purges the rejected cache and never serves stale data. Transient errors can serve labeled stale data, while throttling persists a bounded retry window.

External CCP revocation cannot be detected instantly on a fresh cache hit with an unexpired locally stored token. It becomes visible when refresh or an ESI request rejects the grant. Do not promise immediate remote-revocation detection. A missing locally stored scope denies access immediately.

## Verification

Run `php tests/test_pilot_data.php`. Fixtures cover consent selection, same-character consent, identity login preserving grants, character/environment/grant cache isolation, authorization before fresh cache, public/private separation, token-free private files, stale and rejected authorization, conditional revalidation, rate-limit backoff, malformed/empty results and real zero values. Tests never contact ESI or the database.

Before deployment, verify locally:
1. Existing account settings and shared navigation still work.
2. Signed-out GET /api/account/pilot.php returns 401 without private data.
3. Basic sign-in requests no standings, FW or killmail scopes.
4. Connect each missing feature, select the same character, and confirm only enabled permissions are requested. Selecting a different character must fail without switching the signed-in account.
5. Both account sections and the API show the character's ESI results and freshness. Empty or unavailable data is explained rather than inferred.
6. Log out and sign in again; connected feature permissions are retained. Check narrow/mobile layouts and all site themes.

The account panels are a minimal integration for GURI-002. Full Pilot Record layout/navigation remains GURI-001. Non-mapped entity names currently display their EVE IDs. Live browser/SSO verification is required; fixture success alone does not complete the ticket.

Standings display resolves public entity names through POST /universe/names, caches individual public names for 24 hours, and retains IDs when resolution fails. Factions are expanded by default; corporations and agents are collapsible. Each group defaults to highest standing first; visible controls allow lowest first or alphabetical sorting and entity-type filtering. Name calls carry no bearer token.

Entity detail update: official image service supplies faction/corporation logos and agent portraits (decorative images, lazy loading, no referrer). ID ascending/descending sorts added; highest standing remains the default in each group. app/data/pilot-entities.json is a compact CCP SDE reference retrieved 2026-10-06, containing NPC names, corporation/faction affiliations, agent level/division and recorded system/station IDs. Station names use public names lookup; system name remains available if lookup fails. Bases are static reference locations, not live character locations. Regenerate this reference after relevant SDE changes; no private location scope is requested.

Usability update: overview counts actual positive/negative/exactly-zero reported standings, highlights the Guristas faction relationship when present and the highest faction/corporation values, and displays optional visual +2/+5/+10 milestones with honest remaining distance. These are UI goals, not mission/unlock eligibility. No historical growth, earned rewards, LP, or unreported data is inferred. Search matches names/IDs/affiliations/divisions/recorded bases; relationship/type filters, visible match counts, reset and expand/collapse controls keep long lists manageable. Highest standing first remains the default in each group. FW last-week metrics are highlighted without describing them as current-week/live activity. Timestamp detail remains accessible in a disclosure. Additional checks: PHP tests/test_pilot_ui.php and node tests/test_pilot_ui.js.

Guristas focus update supersedes the generic visual milestones above: only Guristas faction/corporation tracks receive +1/+3/+5 ordinary-agent mission thresholds, with their faction/corporation scope explained. Guristas-only filtering is the interactive default; enemy relationships retain information and explicitly marked propaganda, but no goals or progress rewards. Candidate agents are drawn from BasicAgent type 2 in the CCP SDE; special/storyline/epic agents are excluded. Recorded ordinary Guristas levels stop at 4, so no level-5 reward is advertised. Unknown standings remain unknown; <= -2 raw relevant standings prompt an effective-standing check. This is not a confirmed eligibility calculator; no skills scope was added. Enlistment threshold 0.0 follows CCP Version 23.01 patch notes, superseding old -2 support copy. Website recognition reflects current displayed data, is not persisted, and grants no access rights or in-game reward. Tests: PHP tests/test_guristas_progress.php. Owner-only advanced tools remain a separate implementation; no hidden entrance or authorization change is included here.
