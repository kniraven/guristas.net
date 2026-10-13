#!/usr/bin/env python3
"""No production writes: test catalog lookup against disposable SQLite fixtures."""
import importlib.util
from contextlib import closing
import json
import sqlite3
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('build_catalog', ROOT / 'bin/guristas-build-catalog.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory() as tmp:
    dbpath = Path(tmp) / 'manufacturing.sqlite'
    with closing(sqlite3.connect(dbpath)) as db:
        db.execute('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)')
        db.execute('CREATE TABLE manufacturing (blueprint_id INTEGER PRIMARY KEY, product_id INTEGER, product_quantity INTEGER, manufacture_seconds INTEGER, max_runs INTEGER, materials_json TEXT, skills_json TEXT)')
        db.executemany('INSERT INTO meta VALUES (?,?)', [('sde_build', '3607705'), ('manufacturing_recipes', '4867')])
        db.execute('INSERT INTO manufacturing VALUES (?,?,?,?,?,?,?)', (91001, 92001, 3, 600, 10, json.dumps([{'typeID': 34, 'quantity': 12}]), '[]'))
        db.execute('INSERT INTO manufacturing VALUES (?,?,?,?,?,?,?)', (91002, 92002, 2, 600, 0, json.dumps([{'typeID': 34, 'quantity': 12}]), '[]'))
        db.commit()
    result = module.lookup(dbpath, {'blueprint_ids': [91001, 91002, 11111], 'sde_build': 3607705})
    assert result['status'] == 'ready' and result['matched'] == 1 and result['catalog_recipes'] == 4867
    assert result['recipes']['91001']['product']['quantity'] == 3
    assert '91002' not in result['recipes']
    assert module.lookup(dbpath, {'blueprint_ids': [91001], 'sde_build': 3607706})['status'] == 'version_mismatch'
    assert module.lookup(dbpath.parent / 'none.sqlite', {'blueprint_ids': [91001], 'sde_build': 3607705})['status'] == 'missing'
    try:
        module.lookup(dbpath, {'blueprint_ids': list(range(2001)), 'sde_build': 3607705})
        raise AssertionError('Oversize input accepted')
    except ValueError:
        pass
print('PASS BUILD v37: read-only full catalog, source build guard, LP filtering, invalid recipes and missing database')
