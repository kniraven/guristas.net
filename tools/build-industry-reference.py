"""Build a small public reference from the official JSONL SDE and an ESI LP snapshot."""
import argparse
import datetime
import json
import pathlib
import zipfile

p = argparse.ArgumentParser()
p.add_argument('sde')
p.add_argument('offers')
p.add_argument('output')
p.add_argument('--retrieved-at', default=None)
p.add_argument('--include-upstream', action='store_true', help='Include manufacturing blueprints for direct inputs to LP/Guristas production (opt-in)')
args = p.parse_args()
with zipfile.ZipFile(args.sde) as archive:
    def rows(name):
        return [json.loads(line) for line in archive.open(name)]
    metadata = rows('_sde.jsonl')[0]
    types = {row['_key']: row for row in rows('types.jsonl')}
    corporations = {row['_key']: row for row in rows('npcCorporations.jsonl')}
    assert corporations[1000437]['name']['en'] == 'Commando Guri'
    systems = {row['_key']: row for row in rows('mapSolarSystems.jsonl')}
    stations = {row['_key']: row for row in rows('npcStations.jsonl')}
    blueprints = rows('blueprints.jsonl')
offers = json.loads(pathlib.Path(args.offers).read_text())
assert isinstance(offers, list) and offers
offer_types = {row['type_id'] for row in offers}
hulls = {17715,17918,17930,78366,78367,45645,45647,45649,85229,85236,85062}
# Blueprint recipes producing one-hop industrial inputs are useful to BUILD,
# but are not automatically claimed profitable or manufacturable in Venal.
core_inputs = set()
for row in blueprints:
    activity = row.get('activities', {}).get('manufacturing', {})
    products = activity.get('products', [])
    if len(products) != 1 or (row['_key'] not in offer_types and products[0]['typeID'] not in hulls):
        continue
    core_inputs.update(x['typeID'] for x in activity.get('materials', []))
selected = {}
for row in blueprints:
    activity = row.get('activities', {}).get('manufacturing', {})
    products = activity.get('products', [])
    if len(products) != 1:
        continue
    if row['_key'] not in offer_types and products[0]['typeID'] not in hulls and not (args.include_upstream and products[0]['typeID'] in core_inputs):
        continue
    if not types.get(row['_key'], {}).get('published'):
        continue
    selected[row['_key']] = {'name': types[row['_key']]['name']['en'], 'max_runs': row['maxProductionLimit'], 'time': activity['time'], 'materials': activity.get('materials', []), 'product': products[0]}
needed = offer_types | hulls
for offer in offers:
    needed.update(row['type_id'] for row in offer.get('required_items', []))
for bp in selected.values():
    needed.add(bp['product']['typeID'])
    needed.update(row['typeID'] for row in bp['materials'])
hubs = {}
for key, label, station in [('jita','Jita',60003760),('amarr','Amarr',60008494),('dodixie','Dodixie',60011866),('hek','Hek',60005686),('rens','Rens',60004588),('fulcrum','The Fulcrum',60015187)]:
    record = stations[station]
    system = systems[record['solarSystemID']]
    hubs[key] = {'name': label, 'station_id': station, 'system_id': record['solarSystemID'], 'region_id': system['regionID'], 'system_name': system['name']['en']}
result = {'reference_scope':'lp_blueprints_and_hulls_plus_one_hop_inputs' if args.include_upstream else 'lp_blueprints_and_hulls', 'source':'CCP SDE and public ESI Commando Guri offers', 'sde_build':metadata['buildNumber'], 'sde_release':metadata['releaseDate'], 'retrieved_at':args.retrieved_at or datetime.datetime.now(datetime.timezone.utc).isoformat(), 'blueprints':selected, 'types':{i:{'name':types[i]['name']['en'],'volume':types[i].get('volume')} for i in needed if i in types}, 'hubs':hubs, 'offers_snapshot':offers}
pathlib.Path(args.output).write_text(json.dumps(result, separators=(',', ':'))+'\n')
print(f'{len(selected)} blueprints, {len(result["types"])} item names, {len(offers)} offers')
