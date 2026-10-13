#!/usr/bin/env python3
"""Import all published manufacturing recipes to an indexed SQLite reference.
This is a reference catalog, not evidence of blueprint ownership or job feasibility.
"""
import argparse
import json
import sqlite3
import zipfile
from pathlib import Path


def build(archive_path, output):
    path = Path(output)
    if path.exists():
        raise ValueError('Destination must be a new staging file')
    db = sqlite3.connect(str(path))
    try:
        db.executescript('''
          PRAGMA journal_mode=DELETE;
          CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
          CREATE TABLE manufacturing (
            blueprint_id INTEGER PRIMARY KEY,
            product_id INTEGER NOT NULL,
            product_quantity INTEGER NOT NULL,
            manufacture_seconds INTEGER NOT NULL,
            max_runs INTEGER NOT NULL,
            materials_json TEXT NOT NULL,
            skills_json TEXT NOT NULL
          );
          CREATE INDEX by_product ON manufacturing(product_id);
        ''')
        with zipfile.ZipFile(archive_path) as z:
            with z.open('_sde.jsonl') as source:
                record=json.loads(next(source))
                build_number=int(record.get('buildNumber',0))
            if build_number<1000000:
                raise ValueError('Missing official SDE build')
            count=0
            with z.open('blueprints.jsonl') as source:
                rows=[]
                for line in source:
                    if not line.strip():
                        continue
                    bp=json.loads(line)
                    m=bp.get('activities',{}).get('manufacturing') or {}
                    products=m.get('products',[])
                    if len(products)!=1 or int(products[0].get('typeID',0))<1:
                        continue
                    materials=m.get('materials',[])
                    if any(int(x.get('typeID',0))<1 or int(x.get('quantity',0))<1 for x in materials):
                        continue
                    rows.append((int(bp['_key']),int(products[0]['typeID']),int(products[0]['quantity']),int(m.get('time',0)),int(bp.get('maxProductionLimit',0)),json.dumps(materials,separators=(',',':')),json.dumps(m.get('skills',[]),separators=(',',':'))))
                    if len(rows)>=1000:
                        db.executemany('INSERT INTO manufacturing VALUES (?,?,?,?,?,?,?)',rows)
                        count+=len(rows)
                        rows.clear()
                if rows:
                    db.executemany('INSERT INTO manufacturing VALUES (?,?,?,?,?,?,?)',rows)
                    count+=len(rows)
            if count<1000:
                raise ValueError(f'Unexpectedly small manufacturing catalog: {count}')
            db.executemany('INSERT INTO meta VALUES (?,?)', [('sde_build',str(build_number)),('manufacturing_recipes',str(count))])
            db.commit()
            integrity=db.execute('PRAGMA integrity_check').fetchone()[0]
            if integrity!='ok':
                raise ValueError('SQLite integrity check failed: '+integrity)
        return {'sde_build':build_number,'manufacturing_recipes':count}
    except BaseException:
        db.close()
        path.unlink(missing_ok=True)
        raise
    finally:
        try: db.close()
        except Exception: pass


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('sde_zip')
    parser.add_argument('staging_sqlite')
    args=parser.parse_args()
    print(json.dumps(build(args.sde_zip,args.staging_sqlite)))

if __name__=='__main__': main()
