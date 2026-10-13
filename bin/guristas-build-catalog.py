#!/usr/bin/env python3
"""Read-only lookup of LP-redeemable recipes in the active full SDE catalog.

Input: JSON object {"blueprint_ids": [int, ...], "sde_build": int} on stdin.
Output: JSON object. Never alters the SQLite database.
"""
import json
from contextlib import closing
import sqlite3
import sys
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / 'storage/cache/economic-intelligence/manufacturing-full.sqlite'


def lookup(database, request):
    ids = request.get('blueprint_ids', [])
    build = int(request.get('sde_build', 0))
    if not isinstance(ids, list) or len(ids) > 2000 or build < 1000000:
        raise ValueError('Invalid catalog lookup')
    ids = sorted({int(i) for i in ids if isinstance(i, int) and not isinstance(i, bool) and i > 0})
    if not database.is_file():
        return {'status': 'missing', 'recipes': {}, 'matched': 0}
    with closing(sqlite3.connect(database.resolve().as_uri() + '?mode=ro', uri=True, timeout=3)) as db:
        db.execute('PRAGMA query_only=ON')
        actual = db.execute("SELECT value FROM meta WHERE key='sde_build'").fetchone()
        total = db.execute("SELECT value FROM meta WHERE key='manufacturing_recipes'").fetchone()
        if not actual or int(actual[0]) != build:
            return {'status': 'version_mismatch', 'recipes': {}, 'matched': 0,
                    'catalog_build': actual[0] if actual else None}
        recipes = {}
        for start in range(0, len(ids), 500):
            block = ids[start:start + 500]
            if not block:
                continue
            marks = ','.join('?' for _ in block)
            for row in db.execute(f'''SELECT blueprint_id,product_id,product_quantity,
                manufacture_seconds,max_runs,materials_json,skills_json
                FROM manufacturing WHERE blueprint_id IN ({marks})''', block):
                bid, pid, qty, seconds, runs, materials, skills = row
                if qty < 1 or seconds < 1 or runs < 1:
                    continue
                parsed = json.loads(materials)
                if not isinstance(parsed, list) or not parsed or any(
                    int(m.get('typeID', 0)) < 1 or int(m.get('quantity', 0)) < 1 for m in parsed
                ):
                    continue
                recipes[str(bid)] = {'product': {'typeID': pid, 'quantity': qty},
                                     'materials': parsed, 'manufacture_seconds': seconds,
                                     'max_runs': runs, 'skills': json.loads(skills)}
        return {'status': 'ready', 'recipes': recipes, 'matched': len(recipes),
                'catalog_recipes': int(total[0]) if total else None, 'sde_build': build}


def main():
    try:
        data = json.load(sys.stdin)
        result = lookup(DB, data)
        print(json.dumps(result, separators=(',', ':')))
    except (ValueError, sqlite3.Error, OSError, KeyError, TypeError) as exc:
        print(json.dumps({'status': 'error', 'recipes': {}, 'error': type(exc).__name__}))
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
