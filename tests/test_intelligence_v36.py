"""Offline integration: full manufacturing importer and safe cron rewrite."""
import importlib.util
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import zipfile

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('catalog',root/'tools/build-full-blueprint-db.py')
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
spec2=importlib.util.spec_from_file_location('cron',root/'bin/install-intelligence-cron.py')
cron=importlib.util.module_from_spec(spec2)
spec2.loader.exec_module(cron)
with tempfile.TemporaryDirectory() as td:
    zpath=Path(td)/'sde.zip'
    with zipfile.ZipFile(zpath,'w') as z:
        z.writestr('_sde.jsonl',json.dumps({'buildNumber':3607705})+'\n')
        z.writestr('blueprints.jsonl',''.join(json.dumps({'_key':i,'maxProductionLimit':10,'activities':{'manufacturing':{'time':120,'products':[{'typeID':i+10000,'quantity':1}],'materials':[{'typeID':34,'quantity':i%5+1}],'skills':[]}}})+'\n' for i in range(1001,2101)))
    dbpath=Path(td)/'full.sqlite'
    result=mod.build(zpath,dbpath)
    assert result=={'sde_build':3607705,'manufacturing_recipes':1100}
    with sqlite3.connect(dbpath) as db:
        assert db.execute('select count(*) from manufacturing').fetchone()[0]==1100
        assert db.execute('select count(*) from manufacturing where product_id=?',(11001,)).fetchone()[0]==1
        assert db.execute('select value from meta where key="sde_build"').fetchone()[0]=='3607705'
old='''# BEGIN GURISTAS.NET INTELLIGENCE V13
*/5 * * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php raid 2>&1 | logger -t guristas-raid
*/10 * * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php priority 2>&1 | logger -t guristas-trade
5,15,25,35,45,55 * * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php rotate 2>&1 | logger -t guristas-trade
2,32 * * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php region 10000002
4,34 * * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php region 10000043
7,37 * * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php region 10000032
15 3 * * * cd /var/www/sites/guristas.net && '/usr/bin/php' bin/guristas-intelligence.php offers
# END GURISTAS.NET INTELLIGENCE V13
8,18 * * * * cd /var/www/sites/guristas.net && /usr/bin/php bin/guristas-build.php publish
25 9 * * * cd /var/www/sites/guristas.net && /usr/bin/python3 bin/guristas-sde-sync.py >/dev/null 2>&1 # GURISTAS_DAILY_SDE
10 9 * * * cd /var/www/sites/guristas.net && /usr/bin/php bin/guristas-intelligence.php offers >/dev/null 2>&1 # GURISTAS_DAILY_LP
'''
new=cron.rewrite(old)
assert new.count('# GURISTAS_DAILY_LP')==1 and new.count('# GURISTAS_DAILY_SDE')==1
assert '15 3 * * *' not in new
assert "'/usr/bin/php' bin/guristas-intelligence.php raid" in new
assert "'/usr/bin/php' -d memory_limit=256M bin/guristas-intelligence.php priority" in new
assert new==cron.rewrite(new), 'Cron rewrites must be idempotent'
assert 'guristas-build.php publish' in new
try: cron.rewrite('0 0 * * * some other job\n')
except ValueError: pass
else: raise AssertionError('Missing cron block not rejected')
print('PASS v36: complete indexed blueprint catalog, source version, cron deduplication, memory flags and idempotence')
