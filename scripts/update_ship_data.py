#!/usr/bin/env python3
"""Build a compact ship table from CCP's current JSON Lines SDE. Python 3 stdlib only."""
import argparse, json, math, pathlib, urllib.request, zipfile

URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip'
ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = ROOT / 'public/assets/data/ships.json'
SMALL = {4,8,9,10,11,12,13,14,15,35,41,48,50,93,94,2101,2108,2110,2111,2112}
MEDIUM = {16,17,18,19,20,21,22,23,24,25,37,40,42,43,44,45,96,2109}
LARGE = {26,27,28,36,38,47}
CAPITAL = {32,33,34,46,2102,2104,2113}
RACES = {1:'Caldari',2:'Minmatar',4:'Amarr',8:'Gallente',16:'Jove',32:'Guristas',64:'Angel Cartel',128:'Blood Raiders'}

def rows(z, name):
    with z.open(name+'.jsonl') as f:
        for line in f:
            yield json.loads(line)

def en(v):
    return v.get('en','') if isinstance(v,dict) else str(v or '')

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--zip', help='Use an existing CCP JSONL SDE zip instead of downloading')
    p.add_argument('--output',type=pathlib.Path,default=OUT)
    args=p.parse_args()
    if args.zip:
        z=zipfile.ZipFile(args.zip)
    else:
        req=urllib.request.Request(URL,headers={'User-Agent':'Kniraven ship explorer (SDE importer)'})
        import tempfile
        with tempfile.TemporaryFile() as f:
            with urllib.request.urlopen(req,timeout=120) as response:
                while chunk:=response.read(1024*1024): f.write(chunk)
            f.seek(0)
            z=zipfile.ZipFile(f)
            build(z,args.output)
        return
    with z: build(z,args.output)

def build(z,out):
    names={x['_key']:en(x['name']) for x in rows(z,'shipTreeGroups')}
    groups={x['_key']:x for x in rows(z,'groups')}
    factions={x['_key']:en(x['name']) for x in rows(z,'factions')}
    meta_groups={x['_key']:en(x['name']) for x in rows(z,'metaGroups')}
    dogma={x['_key']:{a['attributeID']:a['value'] for a in x.get('dogmaAttributes',[])} for x in rows(z,'typeDogma')}
    def attr(d,n):return d.get(n)
    def resist(d,n):
        value=d.get(n)
        return round((1-value)*100,2) if value is not None else None
    ships=[]
    for t in rows(z,'types'):
        group=groups.get(t.get('groupID'),{})
        if group.get('categoryID')!=6 or not t.get('published'):continue
        d=dogma.get(t['_key'],{})
        tree=t.get('shipTreeGroupID')
        ship_type=names.get(tree) or en(group.get('name'))
        families={
            'Destroyer':{14,15,50,93,2101,2108,2110,2112},
            'Frigate':{8,9,10,11,12,13,41,48,94,2111},
            'Cruiser':{16,17,18,19,20,21,22,96},
            'Battlecruiser':{23,24,25},
            'Battleship':{26,27,28,47},
            'Dreadnought':{32,2102,2104},
            'Carrier':{33,2113},
        }
        family=next((label for label, ids in families.items() if tree in ids),ship_type)
        size='Small' if tree in SMALL else 'Medium' if tree in MEDIUM else 'Large' if tree in LARGE else 'Capital' if tree in CAPITAL else 'Other'
        mass=t.get('mass')
        agility=attr(d,70)
        cap=attr(d,482)
        cap_ms=attr(d,55)
        shield_ms=attr(d,479)
        data={
            'id':t['_key'],'name':en(t.get('name')),'size':size,
            'type':ship_type,'family':family,
            'faction':factions.get(t.get('factionID')) or RACES.get(t.get('raceID'),'Other'),
            'tech':f"Tech {['','I','II','III'][int(t.get('techLevel') or 0)]}" if int(t.get('techLevel') or 0) in (1,2,3) else 'Other',
            'variant':meta_groups.get(t.get('metaGroupID'),'Other'),
            'shieldHp':attr(d,263),'shieldRecharge':shield_ms/1000 if shield_ms else None,
            'armorHp':attr(d,265),'structureHp':attr(d,9),
            'shieldEm':resist(d,271),'shieldThermal':resist(d,274),'shieldKinetic':resist(d,273),'shieldExplosive':resist(d,272),
            'armorEm':resist(d,267),'armorThermal':resist(d,270),'armorKinetic':resist(d,269),'armorExplosive':resist(d,268),
            'structureEm':resist(d,113),'structureThermal':resist(d,110),'structureKinetic':resist(d,109),'structureExplosive':resist(d,111),
            'targetRange':attr(d,76)/1000 if attr(d,76) is not None else None,
            'gravimetric':attr(d,211),'scanResolution':attr(d,564),'signature':attr(d,552),'maxTargets':attr(d,192),
            'velocity':attr(d,37),'mass':mass,'inertia':agility,'warpSpeed':(attr(d,600) or 0)*(attr(d,1281) or 1) or None,
            'alignTime':round(-math.log(.25)*mass*agility/1_000_000,2) if mass and agility else None,
            'droneBandwidth':attr(d,1271),
            'droneRangeBonus':attr(d,458),
            'capacitor':cap,'capRecharge':cap_ms/1000 if cap_ms else None,
            'capPeak':round(cap*2.5/(cap_ms/1000),3) if cap and cap_ms else None,
        }
        ships.append(data)
    ships.sort(key=lambda x:x['name'].casefold())
    metadata=None
    try:
        with urllib.request.urlopen('https://developers.eveonline.com/static-data/tranquility/latest.jsonl',timeout=15) as response:
            metadata=next((x for x in map(json.loads,response) if x.get('_key')=='sde'),None)
    except Exception:
        pass
    output={'source':'CCP EVE Online Static Data Export','build':metadata.get('buildNumber') if metadata else None,'count':len(ships),'ships':ships}
    out.parent.mkdir(parents=True,exist_ok=True)
    tmp=out.with_suffix('.tmp')
    tmp.write_text(json.dumps(output,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
    tmp.replace(out)
    print(f'Wrote {len(ships)} published ship types to {out}')

if __name__=='__main__':main()
