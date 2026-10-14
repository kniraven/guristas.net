#!/usr/bin/env python3
"""Restore the 11 verified legacy cron entries; reversible, guarded, no backups."""
import argparse,subprocess,sys
BEGIN='# BEGIN GURISTAS.NET OPERATIONS V54'
END='# END GURISTAS.NET OPERATIONS V54'
DISPATCH='* * * * * cd /var/www/sites/guristas.net && /usr/bin/php bin/guristas-ops.php tick 2>&1 | logger -t guristas-ops'
ROOT='/var/www/sites/guristas.net'
def job(spec):return f"cd {ROOT} && {spec}"
LEGACY=[
'*/5 * * * * '+job('/usr/bin/php bin/guristas-intelligence.php raid 2>&1 | logger -t guristas-raid'),
'*/10 * * * * '+job('/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-priority 2>&1 | logger -t guristas-trade'),
'5,15,25,35,45,55 * * * * '+job('/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-rotate 2>&1 | logger -t guristas-trade'),
'2,32 * * * * '+job('/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-region 10000002 2>&1 | logger -t guristas-trade'),
'4,34 * * * * '+job('/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-region 10000043 2>&1 | logger -t guristas-trade'),
'7,37 * * * * '+job('/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-region 10000032 2>&1 | logger -t guristas-trade'),
'8,18,28,38,48,58 * * * * '+job('/usr/bin/php bin/guristas-build.php publish 2>&1 | logger -t guristas-build'),
'49 * * * * '+job('/usr/bin/php bin/guristas-build-planets.php 2>&1 | logger -t guristas-build-planets'),
'10 4 * * * '+job('/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php offers 2>&1 | logger -t guristas-trade # GURISTAS_DAILY_LP'),
'25 4 * * * '+job('/usr/bin/python3 bin/guristas-sde-sync.py 2>&1 | logger -t guristas-sde # GURISTAS_DAILY_SDE'),
'*/30 * * * * '+job('/usr/bin/php -d memory_limit=512M bin/guristas-intelligence.php publish 2>&1 | logger -t guristas-trade # GURISTAS_V46_TRADE_PUBLISH'),
]
# These times are America/Chicago. LP/SDE original crontab used 09:10 and 09:25, but
# on this Chicago-time server they should remain 09:10 and 09:25 to preserve actual cadence.
LEGACY[8]=LEGACY[8].replace('10 4 * * *','10 9 * * *',1)
LEGACY[9]=LEGACY[9].replace('25 4 * * *','25 9 * * *',1)
ap=argparse.ArgumentParser();ap.add_argument('mode',choices=['restore','activate']);ap.add_argument('--apply',action='store_true');a=ap.parse_args()
if subprocess.run(['id','-u'],capture_output=True,text=True).stdout.strip()!='0':sys.exit('Run with sudo; no changes made')
def read():
 p=subprocess.run(['crontab','-u','apache','-l'],capture_output=True,text=True)
 if p.returncode:sys.exit('Cannot read apache crontab: '+p.stderr)
 return p.stdout
src=read();lines=src.splitlines()
if a.mode=='restore':
 if lines.count(BEGIN)!=1 or lines.count(END)!=1 or lines.count(DISPATCH)!=1:sys.exit('Expected exactly one v54 scheduler block; no changes made')
 start=lines.index(BEGIN);end=lines.index(END)
 if end!=start+2 or lines[start+1]!=DISPATCH:sys.exit('Unexpected scheduler block; no changes made')
 remaining=lines[:start]+lines[end+1:]
 if any('guristas-intelligence.php' in x or 'guristas-build.php' in x or 'guristas-sde-sync.py' in x for x in remaining if not x.strip().startswith('#')):sys.exit('Existing Guristas cron jobs detected; refusing duplicates')
 new='\n'.join(remaining+['','# BEGIN GURISTAS.NET RECOVERED V56',*LEGACY,'# END GURISTAS.NET RECOVERED V56',''])+'\n'
else:
 begin='# BEGIN GURISTAS.NET RECOVERED V56';endmark='# END GURISTAS.NET RECOVERED V56'
 if lines.count(begin)!=1 or lines.count(endmark)!=1:sys.exit('Expected recovered v56 cron block; no changes made')
 start=lines.index(begin);end=lines.index(endmark)
 if lines[start+1:end]!=LEGACY:sys.exit('Recovered cron schedule was modified; refusing activation')
 if any(DISPATCH in x for x in (lines[:start]+lines[end+1:])):sys.exit('Dispatcher already exists; no changes made')
 remaining=lines[:start]+lines[end+1:]
 new='\n'.join(remaining+['',BEGIN,DISPATCH,END,''])+'\n'
print('MODE:',a.mode,'| 11 jobs | preserves unrelated entries')
print('Changes are limited to the known Apache Guristas cron block. No runtime data modified.')
if not a.apply:print('DRY RUN; nothing changed. Use --apply after review.');sys.exit(0)
if read()!=src:sys.exit('Concurrent cron change detected; no changes made')
p=subprocess.run(['crontab','-u','apache','-'],input=new,text=True,capture_output=True)
if p.returncode:sys.exit('Cron update FAILED: '+p.stderr)
print('APPLIED:',a.mode)
