#!/usr/bin/env python3
"""v57r4 guarded managed-cron cutover. Never creates backups or alters runtime state."""
import argparse, os, subprocess, sys, time, re
ROOT='/var/www/sites/guristas.net'
BEGIN='# BEGIN GURISTAS.NET RECOVERED V56'
END='# END GURISTAS.NET RECOVERED V56'
MBEGIN='# BEGIN GURISTAS.NET OPERATIONS V54'
MEND='# END GURISTAS.NET OPERATIONS V54'
DISPATCH='* * * * * cd '+ROOT+' && /usr/bin/php bin/guristas-ops.php tick 2>&1 | logger -t guristas-ops'
LEGACY=[
'*/5 * * * * cd '+ROOT+' && /usr/bin/php bin/guristas-intelligence.php raid 2>&1 | logger -t guristas-raid',
'*/10 * * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-priority 2>&1 | logger -t guristas-trade',
'5,15,25,35,45,55 * * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-rotate 2>&1 | logger -t guristas-trade',
'2,32 * * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-region 10000002 2>&1 | logger -t guristas-trade',
'4,34 * * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-region 10000043 2>&1 | logger -t guristas-trade',
'7,37 * * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php collect-region 10000032 2>&1 | logger -t guristas-trade',
'8,18,28,38,48,58 * * * * cd '+ROOT+' && /usr/bin/php bin/guristas-build.php publish 2>&1 | logger -t guristas-build',
'49 * * * * cd '+ROOT+' && /usr/bin/php bin/guristas-build-planets.php 2>&1 | logger -t guristas-build-planets',
'10 9 * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php offers 2>&1 | logger -t guristas-trade # GURISTAS_DAILY_LP',
'25 9 * * * cd '+ROOT+' && /usr/bin/python3 bin/guristas-sde-sync.py 2>&1 | logger -t guristas-sde # GURISTAS_DAILY_SDE',
'*/30 * * * * cd '+ROOT+' && /usr/bin/php -d memory_limit=512M bin/guristas-intelligence.php publish 2>&1 | logger -t guristas-trade # GURISTAS_V46_TRADE_PUBLISH',
]
TIMER='guristas-v57r4-cutover-restore'
def run(args,input=None,check=True,timeout=15):
 p=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
 if check and p.returncode:raise RuntimeError(str(args)+': '+p.stderr.strip()+' '+p.stdout.strip())
 return p

def read():return run(['/usr/bin/crontab','-u','apache','-l']).stdout

def mode(src):
 l=src.splitlines()
 if l.count(BEGIN)==1 and l.count(END)==1:
  i=l.index(BEGIN);j=l.index(END)
  if l[i+1:j]!=LEGACY:raise RuntimeError('Legacy block differs from verified schedule')
  if any(('guristas-ops.php tick' in s or 'guristas-intelligence.php' in s or 'guristas-build.php' in s or 'guristas-build-planets.php' in s or 'guristas-sde-sync.py' in s) for k,s in enumerate(l) if (k<i or k>j) and s.strip() and not s.lstrip().startswith('#')):raise RuntimeError('Extra Guristas jobs detected')
  return 'legacy',i,j
 if l.count(MBEGIN)==1 and l.count(MEND)==1:
  i=l.index(MBEGIN);j=l.index(MEND)
  if l[i+1:j]!=[DISPATCH]:raise RuntimeError('Managed dispatcher does not match verified entry')
  if any(('guristas-ops.php tick' in s or 'guristas-intelligence.php' in s or 'guristas-build.php' in s or 'guristas-build-planets.php' in s or 'guristas-sde-sync.py' in s) for k,s in enumerate(l) if (k<i or k>j) and s.strip() and not s.lstrip().startswith('#')):raise RuntimeError('Extra Guristas jobs detected')
  return 'managed',i,j
 raise RuntimeError('Unknown or partial Apache cron mode; no changes allowed')

def replace(src,old,new):
 m,i,j=mode(src)
 if m!=old:raise RuntimeError('Expected '+old+' schedule but found '+m)
 l=src.splitlines()
 replacement=[MBEGIN,DISPATCH,MEND] if new=='managed' else [BEGIN,*LEGACY,END]
 return '\n'.join(l[:i]+replacement+l[j+1:])+'\n'

def put(expected,new):
 if read()!=expected:raise RuntimeError('Concurrent crontab edit: stop')
 run(['/usr/bin/crontab','-u','apache','-'],input=new)
 if read()!=new:raise RuntimeError('Cron update round-trip mismatch')

def active():
 p=run(['/usr/bin/ps','-eo','pid=,user=,args='])
 found=[]
 for line in p.stdout.splitlines():
  parts=line.strip().split(None,2)
  if len(parts)!=3 or parts[1]!='apache':continue
  if re.search(r'(?:^|[ /])guristas-(?:intelligence\.php|build\.php|build-planets\.php|sde-sync\.py|ops\.php)(?:\s|$)',parts[2]):found.append(line.strip())
 return found

def php_inspect():
 p=run(['/usr/bin/sudo','-u','apache','/usr/bin/php',ROOT+'/bin/guristas-ops.php','inspect'],timeout=20)
 import json
 obj=json.loads(p.stdout)
 return obj

def verify_managed():
 view=php_inspect()
 if view.get('mode')!='managed' or view.get('scheduler',{}).get('managed_entries')!=1 or view.get('scheduler',{}).get('legacy_entries')!=0:raise RuntimeError('App does not recognize managed mode')
 return view

def restore():
 src=read();m,_,_=mode(src)
 if m=='legacy':print('Legacy cron already active');return
 put(src,replace(src,'managed','legacy'))
 print('RESTORED: 11 original legacy cron entries')

def activate():
 src=read();m,_,_=mode(src)
 if m!='legacy':raise RuntimeError('Activation requires exact legacy schedule')
 if active():raise RuntimeError('Running legacy workloads detected; retry later')
 for p in ['bin/guristas-ops.php','app/services/IntelligenceOperations.php','bin/guristas-intelligence.php']:
  if not os.path.isfile(ROOT+'/'+p):raise RuntimeError('Missing source: '+p)
 if php_inspect().get('mode')!='legacy':raise RuntimeError('App scheduler mode disagrees with crontab')
 watchdog=['/usr/bin/systemd-run','--quiet','--unit='+TIMER,'--on-active=4min','/usr/bin/python3',ROOT+'/bin/guristas-managed-cutover.py','restore']
 run(watchdog)
 changed=False
 try:
  put(src,replace(src,'legacy','managed'));changed=True
  print('Dispatcher installed; recovery watchdog armed. Checking production tick.')
  # Do not leave the dispatcher unverified. The first due tick may be ~60 seconds away.
  view=verify_managed()
  initial=int(view.get('last_tick_at') or 0)
  deadline=time.monotonic()+95
  while time.monotonic()<deadline:
   time.sleep(2)
   view=verify_managed()
   tick=int(view.get('last_tick_at') or 0)
   if tick>initial and time.time()-tick<90:
    print('PASS: managed dispatcher has produced a fresh tick at',tick)
    break
  else:raise RuntimeError('No new dispatcher tick arrived in 95 seconds')
  if mode(read())[0]!='managed':raise RuntimeError('Managed schedule changed during validation')
  # Cancel automatic rollback only after successful observed tick.
  stop=run(['/usr/bin/systemctl','stop',TIMER+'.timer'],check=False)
  if stop.returncode:raise RuntimeError('Could not disarm automatic restore watchdog: '+stop.stderr)
  print('ACTIVATED: managed scheduler is authoritative; watchdog disarmed')
  print('Verify job outcomes and Admin controls separately. Roll back with: sudo python3 bin/guristas-managed-cutover.py restore')
 except BaseException:
  if changed:
   try:restore()
   except Exception as exc:print('CRITICAL: automatic restore failed; watchdog remains armed: '+str(exc),file=sys.stderr)
  raise

def main():
 a=argparse.ArgumentParser();a.add_argument('action',choices=['inspect','activate','restore']);x=a.parse_args()
 if os.geteuid()!=0:sys.exit('Run with sudo')
 try:
  src=read();m,_,_=mode(src)
  if x.action=='inspect':
   print('Scheduler:',m,'; active workloads:',len(active()))
   for w in active()[:12]:print(w)
   print('No changes made')
  elif x.action=='restore':restore()
  else:activate()
 except Exception as exc:sys.exit('STOP: '+str(exc))
if __name__=='__main__':main()
