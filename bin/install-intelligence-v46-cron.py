#!/usr/bin/env python3
"""Review and optionally migrate only the existing marked Guristas intelligence cron block.

Does not change unrelated cron entries. Dry-run by default. Asks operator for explicit --apply.
"""
import argparse
import re
import subprocess
import sys

START = '# BEGIN GURISTAS.NET INTELLIGENCE V13'
END = '# END GURISTAS.NET INTELLIGENCE V13'
ROOT = '/var/www/sites/guristas.net'
PATTERN = re.compile(r'(?P<prefix>bin/guristas-intelligence\.php\s+)(?P<command>priority|rotate|region)(?=\s|$)')

def change(old):
    if old.count(START)!=1 or old.count(END)!=1:
        raise ValueError('Expected exactly one marked V13 intelligence block. Crontab not changed.')
    inside=False
    output=[]
    changes=0
    for line in old.splitlines():
        if START in line: inside=True
        if inside and not line.lstrip().startswith('#'):
            replacement,n=PATTERN.subn(lambda m:m.group('prefix')+'collect-'+m.group('command'),line)
            if n:
                changes+=n
                line=replacement
                if '/usr/bin/php -d memory_limit=256M ' not in line:
                    line=line.replace('/usr/bin/php ', '/usr/bin/php -d memory_limit=256M ',1)
        output.append(line)
        if END in line:inside=False
    if changes<3 and not (changes==0 and '# GURISTAS_V46_TRADE_PUBLISH' in old and 'collect-priority' in old and 'collect-rotate' in old and 'collect-region' in old):
        raise ValueError(f'Expected at least 3 collector schedules, found {changes}; no changes made')
    if '# GURISTAS_V46_TRADE_PUBLISH' not in old:
        output.append(f'*/30 * * * * cd {ROOT} && /usr/bin/php -d memory_limit=512M bin/guristas-intelligence.php publish 2>&1 | logger -t guristas-trade # GURISTAS_V46_TRADE_PUBLISH')
    return '\n'.join(output).rstrip()+'\n',changes

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--apply',action='store_true',help='Apply after reviewing the dry-run result')
    args=parser.parse_args()
    current=subprocess.run(['crontab','-u','apache','-l'],capture_output=True,text=True,check=True).stdout
    updated,count=change(current)
    print(f'Collection-only lines changed: {count}')
    for line in updated.splitlines():
        if 'guristas-intelligence.php' in line:print(line)
    if args.apply:
        if subprocess.run(['id','-u'],capture_output=True,text=True,check=True).stdout.strip()!='0':
            sys.exit('Apply requires sudo/root')
        subprocess.run(['crontab','-u','apache','-'],input=updated,text=True,check=True)
        print('Applied to apache cron. Unrelated entries preserved.')
    else:
        print('DRY RUN: nothing changed. Review above, then run with sudo and --apply.')
