<?php
declare(strict_types=1);
// Planned features and specific reasons for requested permissions.
return [
    'Operation planner' => [
        'esi-calendar.respond_calendar_events.v1' => 'RSVP to scheduled fleet and community events.',
        'esi-calendar.read_calendar_events.v1' => 'Show your EVE calendar beside community operations.',
    ],
    'Live fleet dashboard' => [
        'esi-location.read_location.v1' => 'Show your current system in an opt-in fleet view.',
        'esi-location.read_ship_type.v1' => 'Show the ship you are flying in that view.',
        'esi-fleets.read_fleet.v1' => 'Display your current fleet and members.',
        'esi-fleets.write_fleet.v1' => 'Make fleet changes you explicitly choose.',
        'esi-location.read_online.v1' => 'Show whether your character is online.',
    ],
    'Message center' => [
        'esi-mail.organize_mail.v1' => 'Mark and sort operation mail from the site.',
        'esi-mail.read_mail.v1' => 'Show your EVE mail and operation invitations.',
        'esi-mail.send_mail.v1' => 'Send EVE mail you choose to compose.',
        'esi-characters.read_chat_channels.v1' => 'Show chat channel memberships for community coordination.',
        'esi-characters.read_notifications.v1' => 'Show your in-game notifications.',
    ],
    'Skill planner' => [
        'esi-skills.read_skills.v1' => 'Compare trained skills with ship requirements.',
        'esi-skills.read_skillqueue.v1' => 'Estimate when queued skills finish.',
    ],
    'Wallet and LP dashboard' => [
        'esi-wallet.read_character_wallet.v1' => 'Track personal ISK balance and transactions.',
        'esi-characters.read_loyalty.v1' => 'Track loyalty points and reward goals.',
    ],
    'Corporation finance dashboard' => [
        'esi-wallet.read_corporation_wallet.v1' => 'Show authorized corporation wallet activity.',
        'esi-wallet.read_corporation_wallets.v1' => 'Show authorized corporation wallet divisions.',
    ],
    'Structure finder' => [
        'esi-search.search_structures.v1' => 'Find structures you can access.',
        'esi-universe.read_structures.v1' => 'Show details of accessible player structures.',
    ],
    'Clone planner' => [
        'esi-clones.read_clones.v1' => 'Show available jump clones and locations.',
        'esi-clones.read_implants.v1' => 'Compare implants across your active and jump clones.',
    ],
    'Contacts dashboard' => [
        'esi-characters.read_contacts.v1' => 'Identify your character contacts and standings.',
        'esi-characters.write_contacts.v1' => 'Save contacts or standings you explicitly choose.',
    ],
    'Combat achievements' => [
        'esi-killmails.read_killmails.v1' => 'Build private kill and loss achievements.',
        'esi-characters.read_medals.v1' => 'Display character medals in your profile.',
    ],
    'Corporation roster' => [
        'esi-corporations.read_corporation_membership.v1' => 'Show membership history where available.',
        'esi-characters.read_corporation_roles.v1' => 'Check which corporation tools your roles allow.',
        'esi-corporations.track_members.v1' => 'Track authorized corporation members.',
        'esi-corporations.read_divisions.v1' => 'Label corporation divisions in authorized tools.',
        'esi-corporations.read_contacts.v1' => 'Show corporation contacts and standings.',
        'esi-corporations.read_titles.v1' => 'Display corporation titles and access levels.',
        'esi-corporations.read_medals.v1' => 'Display corporation medals and awards.',
        'esi-characters.read_titles.v1' => 'Show your character\'s corporation titles.',
    ],
    'Asset inventory' => [
        'esi-assets.read_assets.v1' => 'Find your ships, modules and other assets.',
    ],
    'Planetary production' => [
        'esi-planets.manage_planets.v1' => 'Show your colonies and production routes.',
        'esi-planets.read_customs_offices.v1' => 'Show authorized corporation customs offices.',
    ],
    'In-game shortcuts' => [
        'esi-ui.open_window.v1' => 'Open selected in-game info and market windows.',
        'esi-ui.write_waypoint.v1' => 'Set a destination you choose in the EVE client.',
    ],
    'Fitting library' => [
        'esi-fittings.read_fittings.v1' => 'Import your saved ship fits for comparison.',
        'esi-fittings.write_fittings.v1' => 'Save a fit you choose back to EVE.',
    ],
    'Market planner' => [
        'esi-markets.structure_markets.v1' => 'Compare prices in structures you can access.',
        'esi-markets.read_character_orders.v1' => 'Show your open and historical personal orders.',
        'esi-markets.read_corporation_orders.v1' => 'Show authorized corporation market orders.',
    ],
    'Corporation infrastructure' => [
        'esi-corporations.read_structures.v1' => 'Display authorized corporation structures.',
        'esi-corporations.read_starbases.v1' => 'Show authorized starbase locations and status.',
        'esi-corporations.read_facilities.v1' => 'List authorized corporation industry facilities.',
    ],
    'Guristas alignment' => [
        'esi-characters.read_standings.v1' => 'Show your current NPC faction and corporation standings.',
        'esi-corporations.read_standings.v1' => 'Show corporation standings where your roles permit.',
    ],
    'Agent research planner' => [
        'esi-characters.read_agents_research.v1' => 'Track research agents and progress.',
    ],
    'Industry planner' => [
        'esi-industry.read_character_jobs.v1' => 'Track your manufacturing and research jobs.',
        'esi-characters.read_blueprints.v1' => 'Show your personal blueprint collection.',
    ],
    'Contract tracker' => [
        'esi-contracts.read_character_contracts.v1' => 'Track your personal contracts.',
        'esi-contracts.read_corporation_contracts.v1' => 'Track authorized corporation contracts.',
    ],
    'Travel planner' => [
        'esi-characters.read_fatigue.v1' => 'Show jump fatigue before travel plans.',
    ],
    'Corporation combat board' => [
        'esi-killmails.read_corporation_killmails.v1' => 'Build corporation kill and loss summaries.',
    ],
    'Corporation asset inventory' => [
        'esi-assets.read_corporation_assets.v1' => 'Find authorized corporation ships and supplies.',
    ],
    'Corporation industry planner' => [
        'esi-corporations.read_blueprints.v1' => 'Track authorized corporation blueprints.',
        'esi-industry.read_corporation_jobs.v1' => 'Track authorized corporation industry jobs.',
    ],
    'Corporation audit' => [
        'esi-corporations.read_container_logs.v1' => 'Review authorized container access logs.',
    ],
    'Mining ledger' => [
        'esi-industry.read_character_mining.v1' => 'Track your recorded mining activity.',
        'esi-industry.read_corporation_mining.v1' => 'Track authorized corporation mining activity.',
    ],
    'Diplomacy board' => [
        'esi-alliances.read_contacts.v1' => 'Show alliance contacts and standings where permitted.',
    ],
    'Faction warfare record' => [
        'esi-characters.read_fw_stats.v1' => 'Show your character\'s faction warfare record.',
        'esi-corporations.read_fw_stats.v1' => 'Show your corporation\'s faction warfare record.',
    ],
    'Corporation projects' => [
        'esi-corporations.read_projects.v1' => 'Track authorized corporation projects.',
        'esi-corporations.read_freelance_jobs.v1' => 'Show authorized corporation freelance jobs.',
        'esi-characters.read_freelance_jobs.v1' => 'Track your freelance job participation.',
    ],
    'Structure management' => [
        'esi-structures.read_corporation.v1' => 'Show authorized corporation structure information.',
        'esi-structures.read_character.v1' => 'Show structures your character can manage or access.',
    ],
    'Access audit' => [
        'esi-access.read_lists.v1' => 'Show access lists for structures and shared resources.',
    ],
    'Campaign achievements' => [
        'esi.activity.char:read' => 'Track your military campaign objectives and contributions.',
        'esi-activities.read_character.v1' => 'Show your character activity history and milestones.',
    ],
    'Character cosmetics' => [
        'esi.cosmetic.char:read' => 'Show character cosmetic and SKINR related data where available.',
    ],
];
