"""Offline v35 updater checks; does not access CCP or modify runtime data."""
import importlib.util
from pathlib import Path
p = Path(__file__).resolve().parents[1] / 'bin/guristas-sde-sync.py'
spec = importlib.util.spec_from_file_location('sync', p)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
assert m.latest_build('{"_key":"other","_value":2}\n{"_key":"sde","_value":3586130}\n') == 3586130
assert m.latest_build('{"_key":"sde","_value":{"buildNumber":3586130}}') == 3586130
for bad in ['', '{"_key":"sde","_value":"oops"}', '{"_key":"sde","_value":10}']:
    try: m.latest_build(bad)
    except ValueError: pass
    else: raise AssertionError('Invalid metadata accepted')
ref = {'sde_build': 3586130, 'blueprints': dict.fromkeys(range(55)), 'pi_schematics': dict.fromkeys(range(68)), 'reprocessing': dict.fromkeys(range(8092)), 'types': dict.fromkeys(range(556))}
assert m.reference_valid(ref,3586130)['blueprints']==55
for alteration in ({'sde_build':1},{'pi_schematics':{}}):
    broken=dict(ref,**alteration)
    try: m.reference_valid(broken,3586130)
    except ValueError: pass
    else: raise AssertionError('Incomplete SDE accepted')
print('PASS v35: SDE metadata parsing, import coverage and build-mismatch safeguards')
