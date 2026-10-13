import json, pathlib, subprocess, sys, tempfile, zipfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as temp:
    temp=pathlib.Path(temp)
    z=temp/'mock-sde.zip'
    records={
        '_sde.jsonl':[{'buildNumber':3586130,'releaseDate':'2026-10-07'}],
        'types.jsonl':[], 'npcCorporations.jsonl':[{'_key':1000437,'name':{'en':'Commando Guri'}}],
        'mapSolarSystems.jsonl':[], 'npcStations.jsonl':[], 'blueprints.jsonl':[],
        'planetSchematics.jsonl':[{'_key':92,'name':{'en':'Synthetic Synapses'},'cycleTime':3600,'pins':[2472],
            'types':[{'_key':2312,'isInput':True,'quantity':10},{'_key':2319,'isInput':True,'quantity':10},{'_key':2346,'isInput':False,'quantity':3}]}],
        'typeMaterials.jsonl':[{'_key':1228,'materials':[{'materialTypeID':34,'quantity':200}]}],
    }
    for tid,name in [(2312,'Supertensile Plastics'),(2319,'Test Cultures'),(2346,'Synthetic Synapses'),(1228,'Scordite'),(34,'Tritanium')]:
        records['types.jsonl'].append({'_key':tid,'name':{'en':name},'published':True,'portionSize':100})
    stations={'jita':(60003760,30000142,10000002),'amarr':(60008494,30002187,10000043),
              'dodixie':(60011866,30002659,10000032),'hek':(60005686,30002053,10000042),
              'rens':(60004588,30002510,10000030),'fulcrum':(60015187,30045339,10001000)}
    for name,(station,system,region) in stations.items():
        records['npcStations.jsonl'].append({'_key':station,'solarSystemID':system})
        records['mapSolarSystems.jsonl'].append({'_key':system,'regionID':region,'name':{'en':name}})
    with zipfile.ZipFile(z,'w') as archive:
        for name,items in records.items():
            archive.writestr(name,'\n'.join(json.dumps(row) for row in items)+'\n')
    offers=temp/'offers.json';offers.write_text(json.dumps([{'type_id':2346,'lp_cost':5,'required_items':[]}]))
    out=temp/'reference.json'
    subprocess.run([sys.executable,str(root/'tools/build-industry-reference.py'),str(z),str(offers),str(out),
       '--include-pi','--include-reprocessing','--include-upstream'],check=True)
    ref=json.loads(out.read_text())
    assert len(ref['pi_schematics'])==1, ref['pi_schematics']
    assert ref['pi_schematics']['92']['output']['typeID']==2346
    assert ref['reprocessing']['1228']['portion_size']==100
    assert ref['types']['34']['name']=='Tritanium'
    assert ref['sde_build']==3586130
print('PASS v34: SDE import of planetary schematics, reprocessing tables and type names')
