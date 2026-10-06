<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/PilotEntityNames.php';
$catalog = eve_pilot_entity_catalog();
$rows = eve_enrich_pilot_entities([
    ['from_id' => 3019356, 'from_type' => 'agent', 'standing' => 7.9],
    ['from_id' => 1000127, 'from_type' => 'npc_corp', 'standing' => 6.35],
    ['from_id' => 99999999, 'from_type' => 'agent', 'standing' => 0.0],
], $catalog, [60012607 => 'Arnon IX - Moon 3 - Sisters of EVE Bureau']);
if ($rows[0]['corporation_name'] !== 'Sisters of EVE' || $rows[0]['faction_name'] !== 'Servant Sisters of EVE'
    || $rows[0]['system_name'] !== 'Arnon' || $rows[0]['agent_level'] !== 1
    || $rows[0]['location_name'] !== 'Arnon IX - Moon 3 - Sisters of EVE Bureau'
    || $rows[0]['standing'] !== 7.9 || $rows[1]['faction_name'] !== 'Guristas Pirates'
    || $rows[2]['name'] !== 'EVE ID 99999999' || $rows[2]['standing'] !== 0.0) throw new RuntimeException('Entity enrichment failed.');
$fallback = eve_enrich_pilot_entities([$rows[0]], $catalog, []);
if ($fallback[0]['location_name'] !== 'Arnon system') throw new RuntimeException('System fallback failed.');
$wrongType = eve_enrich_pilot_entities([['from_id' => 3019356, 'from_type' => 'faction', 'standing' => 1.0]], $catalog, []);
if (isset($wrongType[0]['corporation_name'])) throw new RuntimeException('Type mismatch was enriched.');
echo "PASS: agent/corporation affiliations, recorded bases, station-name fallback, unknown IDs and unchanged standings.\n";
