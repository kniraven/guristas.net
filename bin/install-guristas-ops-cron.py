#!/usr/bin/env python3
"""Guarded cron migration. Must be run as root explicitly after deployment.
Will NOT overwrite or back up files; aborts on unexpected crontab contents.
"""
import argparse,subprocess,sys
MARKER='# BEGIN GURISTAS.NET OPERATIONS V54'
ENTRY="* * * * * cd /var/www/sites/guristas.net && /usr/bin/php bin/guristas-ops.php tick 2>&1 | logger -t guristas-ops"
IDS=['guristas-intelligence.php raid','guristas-intelligence.php collect-priority','guristas-intelligence.php collect-rotate','guristas-intelligence.php collect-region 10000002','guristas-intelligence.php collect-region 10000043','guristas-intelligence.php collect-region 10000032','guristas-build.php publish','guristas-build-planets.php','guristas-intelligence.php offers','guristas-sde-sync.py','guristas-intelligence.php publish']
parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');a=parser.parse_args()
p=subprocess.run(['crontab','-u','apache','-l'],capture_output=True,text=True)
if p.returncode: sys.exit('Cannot read Apache crontab; no changes made: '+p.stderr)
src=p.stdout
if MARKER in src:
    if ENTRY in src:
        print('Already migrated. No changes made.');sys.exit(0)
    sys.exit('Operations marker exists with unexpected entry; refusing to change cron.')
lines=src.splitlines();matches=[]
for ln in lines:
    if ln.lstrip().startswith('#') or not ln.strip(): continue
    found=[i for i,id in enumerate(IDS) if id in ln]
    if len(found)==1: matches.append((ln,found[0]))
if len(matches)!=11 or sorted(i for _,i in matches)!=list(range(11)):
    sys.exit('Expected exactly 11 legacy job entries, each once; found '+str(len(matches))+'. Nothing changed.')
remaining=[ln for ln in lines if ln not in [m[0] for m in matches] and ln not in ['# BEGIN GURISTAS.NET INTELLIGENCE V13','# END GURISTAS.NET INTELLIGENCE V13']]
new='\n'.join(remaining+['',MARKER,ENTRY,'# END GURISTAS.NET OPERATIONS V54',''])+'\n'
print('Recognized 11 legacy jobs; preserving',len(remaining),'other lines.')
print('New cron job:',ENTRY)
if not a.apply:
    print('DRY RUN ONLY. Re-run with --apply to migrate.');sys.exit(0)
# Recheck before commit to prevent trampling unrelated concurrent edits
again=subprocess.run(['crontab','-u','apache','-l'],capture_output=True,text=True)
if again.returncode or again.stdout!=src:sys.exit('Crontab changed during inspection; refusing migration.')
result=subprocess.run(['crontab','-u','apache','-'],input=new,text=True,capture_output=True)
if result.returncode:sys.exit('Crontab rejected migration: '+result.stderr)
print('Migrated: 11 legacy entries replaced with one minute dispatcher. Other cron lines preserved.')
