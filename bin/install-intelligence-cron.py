#!/usr/bin/env python3
"""Idempotently install safe Guristas collector schedules, retaining unrelated jobs."""
import os
import subprocess
import sys

ROOT = '/var/www/sites/guristas.net'

def rewrite(old):
    if '# BEGIN GURISTAS.NET INTELLIGENCE V13' not in old or '# END GURISTAS.NET INTELLIGENCE V13' not in old:
        raise ValueError('Expected V13 collector block missing; crontab unchanged')
    out = []
    inside = False
    trade_modified = 0
    for line in old.splitlines():
        if '# BEGIN GURISTAS.NET INTELLIGENCE V13' in line:
            inside = True
        elif '# END GURISTAS.NET INTELLIGENCE V13' in line:
            inside = False
        if line.endswith('# GURISTAS_DAILY_LP') or line.endswith('# GURISTAS_DAILY_SDE'):
            continue
        if line.startswith('15 3 * * * ') and 'bin/guristas-intelligence.php offers' in line:
            continue
        if inside and any('bin/guristas-intelligence.php '+kind in line for kind in ('priority','rotate','region','offers')):
            if 'memory_limit=256M' not in line:
                if "'/usr/bin/php' bin/guristas-intelligence.php" in line:
                    line=line.replace("'/usr/bin/php' bin/guristas-intelligence.php", "'/usr/bin/php' -d memory_limit=256M bin/guristas-intelligence.php")
                elif '/usr/bin/php bin/guristas-intelligence.php' in line:
                    line=line.replace('/usr/bin/php bin/guristas-intelligence.php','/usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php')
                else:
                    raise ValueError('Unexpected TRADE cron syntax, no changes made: '+line)
            trade_modified+=1
        out.append(line)
    if trade_modified<5:
        raise ValueError(f'Expected at least 5 trade cron lines, got {trade_modified}; no changes made')
    out.append(f'10 9 * * * cd {ROOT} && /usr/bin/php -d memory_limit=256M bin/guristas-intelligence.php offers 2>&1 | logger -t guristas-trade # GURISTAS_DAILY_LP')
    out.append(f'25 9 * * * cd {ROOT} && /usr/bin/python3 bin/guristas-sde-sync.py 2>&1 | logger -t guristas-sde # GURISTAS_DAILY_SDE')
    return '\n'.join(out).rstrip()+'\n'

if __name__=='__main__':
    if os.geteuid()!=0:
        raise SystemExit('Run using sudo')
    previous=subprocess.run(['crontab','-u','apache','-l'],capture_output=True,text=True,check=True).stdout
    updated=rewrite(previous)
    if updated!=previous:
        subprocess.run(['crontab','-u','apache','-'],input=updated,text=True,check=True)
    print('Configured 256 MiB for TRADE CLI, one daily LP job, one daily SDE job; all unrelated jobs preserved.')
