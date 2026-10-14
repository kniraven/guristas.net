import importlib.util
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'bin/install-intelligence-v46-cron.py'
spec=importlib.util.spec_from_file_location('v46cron',str(p)); mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
old='''# BEGIN GURISTAS.NET INTELLIGENCE V13
0 * * * * cd /var/www/sites/guristas.net && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php priority
15 * * * * cd /var/www/sites/guristas.net && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php rotate
20 * * * * cd /var/www/sites/guristas.net && /usr/bin/php bin/guristas-intelligence.php region 10000002
# END GURISTAS.NET INTELLIGENCE V13
# OTHER JOB
'''
new,count=mod.change(old)
assert count==3 and 'collect-priority' in new and 'collect-rotate' in new and 'collect-region 10000002' in new
assert 'memory_limit=512M bin/guristas-intelligence.php publish' in new
assert '# OTHER JOB' in new
assert mod.change(new)[0]==new
print('PASS v46 cron: separate collection/publish, guarded idempotent migration')
