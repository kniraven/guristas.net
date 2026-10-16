#!/usr/bin/python3
"""Allowlisted Guristas systemd bridge. Invoked by sudo as root, never by HTTP directly."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import fcntl

UNITS = {'guristas-venal-collector': 'hourly', 'guristas-cache-cleanup': 'daily'}
PREFIX = '/etc/systemd/system'

def call(*args, timeout=10):
    return subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=True)

def fail(message):
    print(message, file=sys.stderr)
    sys.exit(2)

def main(argv):
    if len(argv)<3 or argv[1] not in {'journal','config','run','settings'} or argv[2] not in UNITS:
        fail('Rejected action or unit')
    action, name = argv[1:3]
    mode = UNITS[name]
    if action in ('journal','config','run') and len(argv)!=3: fail('Extra arguments rejected')
    if action=='journal':
        # No shell, user-supplied units, or arbitrary journal ranges.
        p=call('/usr/bin/journalctl','-u',name+'.service','-n','240','--no-pager','-o','cat')
        sys.stdout.write(p.stdout[-60000:]);return
    if action=='config':
        timer=name+'.timer'
        p=call('/usr/bin/systemctl','show',timer,'--no-pager','-p','UnitFileState','-p','ActiveState')
        active='ActiveState=active' in p.stdout
        directory=Path(PREFIX)/(timer+'.d')
        override=directory/'90-guristas-admin.conf'
        minute=0;hour=0
        if override.exists():
            content=override.read_text()
            match=re.search(r'^# Guristas schedule: (hourly|daily) (\d{1,2}) (\d{1,2})$',content,re.M)
            if not match or match.group(1)!=mode: fail('Existing admin timer override is invalid')
            minute=int(match.group(2));hour=int(match.group(3))
        print(json.dumps({'ok':True,'mode':mode,'minute':minute,'hour':hour,'enabled':active}));return
    # Explicit locking prevents simultaneous website requests from racing timer changes.
    lockfd=os.open('/run/lock/guristas-systemd-admin.lock',os.O_CREAT|os.O_RDWR,0o600)
    with os.fdopen(lockfd,'w') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        if action=='run':
            state=call('/usr/bin/systemctl','show',name+'.service','--no-pager','-p','ActiveState').stdout
            if 'ActiveState=active' in state or 'ActiveState=activating' in state: fail('Service is already running')
            call('/usr/bin/systemctl','start','--no-block',name+'.service')
            print(json.dumps({'ok':True,'requested':True,'service':name+'.service'}));return
        if len(argv)!=8 or argv[3]!=mode: fail('Invalid schedule request')
        def intval(v,maxval):
            if not re.fullmatch(r'[0-9]{1,2}',v):fail('Invalid number')
            n=int(v)
            if n>maxval: fail('Out-of-range number')
            return n
        minute=intval(argv[4],59);hour=intval(argv[5],23)
        if argv[6] not in ('0','1'): fail('Enabled must be 0 or 1')
        # argv[7] is reserved explicit confirmation, protects accidental CLI calls.
        if argv[7]!='confirmed':fail('Missing confirmation')
        enabled=argv[6]=='1'
        timer=name+'.timer'
        directory=Path(PREFIX)/(timer+'.d')
        override=directory/'90-guristas-admin.conf'
        # Do not alter unrelated existing drop-ins: fail closed if present.
        if directory.exists():
            other=[entry for entry in directory.iterdir() if entry.name!='90-guristas-admin.conf']
            if other:fail('Other timer drop-ins exist: manual review needed')
        expression=f'*-*-* *:{minute:02d}:00 America/Chicago' if mode=='hourly' else f'*-*-* {hour:02d}:{minute:02d}:00 America/Chicago'
        call('/usr/bin/systemd-analyze','calendar',expression)
        data='[Timer]\nOnCalendar=\nOnCalendar='+expression+f'\n# Guristas schedule: {mode} {minute} {hour}\n'
        previous=override.read_bytes() if override.exists() else None
        directory.mkdir(mode=0o755,parents=True,exist_ok=True)
        temp=None
        try:
            fd,temp=tempfile.mkstemp(prefix='.guristas-',dir=str(directory))
            os.fchmod(fd,0o644)
            with os.fdopen(fd,'wb') as f:f.write(data.encode());f.flush();os.fsync(f.fileno())
            os.replace(temp,override);temp=None
            call('/usr/bin/systemctl','daemon-reload',timeout=20)
            if enabled:call('/usr/bin/systemctl','enable','--now',timer)
            else:call('/usr/bin/systemctl','disable','--now',timer)
            # Restart a live timer only to re-arm its schedule, not its service.
            if enabled:call('/usr/bin/systemctl','restart',timer)
        except Exception:
            if previous is None:override.unlink(missing_ok=True)
            else:override.write_bytes(previous)
            try:call('/usr/bin/systemctl','daemon-reload',timeout=20)
            except Exception:pass
            raise
        finally:
            if temp and os.path.exists(temp):os.unlink(temp)
        print(json.dumps({'ok':True,'service':timer,'mode':mode,'minute':minute,'hour':hour,'enabled':enabled}))

if __name__=='__main__':
    try:main(sys.argv)
    except (OSError,subprocess.SubprocessError) as ex:fail('System administration operation failed: '+str(ex)[:300])
