# GURI-002 required login and effective standings

Add esi-skills.read_skills.v1 to this application's enabled permissions in CCP's developer portal. Keep standings and FW statistics enabled. No killmail permission is requested.

All logins require all three scopes. Incomplete consent does not establish a session. Existing identity-only sessions require reauthorization. Active skills, not trained levels, are used (important for Alpha accounts).

Guristas effective standing = raw + (10 - raw) * 0.04 * active skill level. Diplomacy (3357) applies below zero; Criminal Connections (3361) above zero. Exactly zero receives no bonus. Connections (3359) is parsed but does not apply to Guristas entities. Social affects gains rather than current standing and is not added.

Ordinary-agent requirements use maximum applicable effective standing and check the -2 boundary before rounding. Missing relationships remain unknown; neither unreported standings nor failed skills responses are invented. Calculations require fresh standings and skills. Special, storyline, epic-arc, player-corporation and other non-standing restrictions are not asserted as unlocked. Pirate enlistment remains based on the raw faction relationship.

Validated on PHP 8.3: syntax, all six PHP fixture suites and the JavaScript suite. PHP 8.0 and real CCP consent flow must be verified locally. No database migration or private configuration change.

Sources: https://support.eveonline.com/hc/en-us/articles/203217152-Standings and CCP ESI OpenAPI CharactersSkills schema.
