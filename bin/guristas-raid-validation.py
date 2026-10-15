#!/usr/bin/env python3
"""Guarded one-shot RAID validation with recovery; no backup files."""
import argparse
import os
import re
import subprocess
import sys
import time

BEGIN = '# BEGIN GURISTAS.NET RECOVERED V56'
END = '# END GURISTAS.NET RECOVERED V56'
HOLD_BEGIN = '# BEGIN GURISTAS.NET RAID VALIDATION V57R3'
HOLD_END = '# END GURISTAS.NET RAID VALIDATION V57R3'
PREFIX = '# V57R3 HELD '
ROOT = '/var/www/sites/guristas.net'


def call(args, *, check=True, stdin=None):
    p = subprocess.run(args, input=stdin, text=True, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, timeout=240)
    if check and p.returncode:
        raise RuntimeError(f'{args[0]} returned {p.returncode}: {p.stderr.strip()}')
    return p


def cron():
    return call(['/usr/bin/crontab', '-u', 'apache', '-l']).stdout


def put(expected, updated):
    if cron() != expected:
        raise RuntimeError('Apache crontab changed concurrently; refusing overwrite')
    call(['/usr/bin/crontab', '-u', 'apache', '-'], stdin=updated)
    if cron() != updated:
        raise RuntimeError('Crontab write did not round-trip exactly')


def boundaries(lines, first, last):
    if lines.count(first) != 1 or lines.count(last) != 1:
        raise RuntimeError('Expected exactly one recognized cron block')
    i, j = lines.index(first), lines.index(last)
    if j - i != 12:
        raise RuntimeError('Expected precisely eleven entries inside cron block')
    return i, j


def check_jobs(entries):
    fragments = [
        'guristas-intelligence.php raid',
        'guristas-intelligence.php collect-priority',
        'guristas-intelligence.php collect-rotate',
        'guristas-intelligence.php collect-region 10000002',
        'guristas-intelligence.php collect-region 10000043',
        'guristas-intelligence.php collect-region 10000032',
        'guristas-build.php publish',
        'guristas-build-planets.php',
        'guristas-intelligence.php offers',
        'guristas-sde-sync.py',
        'guristas-intelligence.php publish',
    ]
    for entry, expected in zip(entries, fragments):
        if not entry.startswith(('*/5 ', '*/10 ', '5,15', '2,32', '4,34', '7,37', '8,18', '49 ', '10 9', '25 9', '*/30')):
            raise RuntimeError('Unexpected cron schedule: ' + entry)
        if ('cd ' + ROOT + ' && ') not in entry or expected not in entry:
            raise RuntimeError('Unexpected cron command: ' + entry)
    if len(set(entries)) != 11:
        raise RuntimeError('Duplicate legacy entries')


def restore():
    source = cron()
    lines = source.splitlines()
    if HOLD_BEGIN not in lines and HOLD_END not in lines:
        i, j = boundaries(lines, BEGIN, END)
        check_jobs(lines[i + 1:j])
        print('Legacy cron already restored: 11 entries')
        return
    i, j = boundaries(lines, HOLD_BEGIN, HOLD_END)
    held = lines[i + 1:j]
    if any(not x.startswith(PREFIX) for x in held):
        raise RuntimeError('Unexpected validation block contents; refusing restore')
    entries = [x[len(PREFIX):] for x in held]
    check_jobs(entries)
    updated = '\n'.join(lines[:i] + [BEGIN] + entries + [END] + lines[j + 1:]) + '\n'
    put(source, updated)
    print('RESTORED: all eleven original legacy cron entries')


def workloads():
    p = call(['/usr/bin/ps', '-eo', 'pid=,user=,args='])
    matches = []
    for line in p.stdout.splitlines():
        parts = line.strip().split(None, 2)
        if len(parts) < 3 or parts[1] != 'apache':
            continue
        pid, _, args = parts
        if re.search(r'\b(?:bin/)?guristas-(?:intelligence\.php|build\.php|build-planets\.php|sde-sync\.py|ops\.php)\b', args):
            matches.append(f'{pid} {args}')
    return matches


def validate():
    source = cron()
    lines = source.splitlines()
    i, j = boundaries(lines, BEGIN, END)
    entries = lines[i + 1:j]
    check_jobs(entries)
    if any('guristas-ops.php' in x for x in lines if x and not x.lstrip().startswith('#')):
        raise RuntimeError('An active managed dispatcher already exists')
    if workloads():
        raise RuntimeError('Legacy workloads are already executing; retry later')
    if not os.path.isfile(ROOT + '/bin/guristas-ops.php'):
        raise RuntimeError('Missing deployed worker')
    if not os.path.isfile(ROOT + '/app/services/IntelligenceOperations.php'):
        raise RuntimeError('Missing deployed service')
    if not os.path.isfile(ROOT + '/bin/guristas-intelligence.php'):
        raise RuntimeError('Missing RAID workload')
    if not call(['/usr/bin/systemd-run', '--help'], check=False).returncode == 0:
        raise RuntimeError('systemd-run watchdog is unavailable')
    # Fail-safe root service: restores the guarded in-crontab entries after five minutes,
    # even if SSH disconnects or this script is terminated. No backup file is created.
    watchdog = [
        '/usr/bin/systemd-run', '--quiet', '--unit=guristas-v57r3-raid-restore',
        '--on-active=5min', '/usr/bin/python3', ROOT + '/bin/guristas-raid-validation.py',
        'restore'
    ]
    hold = [HOLD_BEGIN] + [PREFIX + x for x in entries] + [HOLD_END]
    updated = '\n'.join(lines[:i] + hold + lines[j + 1:]) + '\n'
    call(watchdog)
    try:
        put(source, updated)
        print('Guarded RAID-only validation mode entered; restore watchdog armed.')
        # Cron might have launched immediately before our replace; fail rather than overlap.
        time.sleep(2)
        active = workloads()
        if active:
            raise RuntimeError('Legacy worker still in flight: ' + ' | '.join(active[:5]))
        report = call(['/usr/bin/sudo', '-u', 'apache', '/usr/bin/php',
                       ROOT + '/bin/guristas-ops.php', 'validate-raid'], check=False)
        print(report.stdout)
        if report.stderr:
            print(report.stderr, file=sys.stderr)
        if report.returncode:
            raise RuntimeError('Managed RAID validation failed with exit ' + str(report.returncode))
        print('PASS: real managed RAID execution completed successfully')
    finally:
        restore()
        if cron().count(BEGIN) != 1:
            raise RuntimeError('CRITICAL: cron restoration not verified')


if __name__ == '__main__':
    if os.geteuid() != 0:
        sys.exit('Run with sudo; no changes made')
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['inspect', 'validate', 'restore'])
    args = parser.parse_args()
    try:
        if args.action == 'inspect':
            source = cron()
            lines = source.splitlines()
            i, j = boundaries(lines, BEGIN, END)
            check_jobs(lines[i + 1:j])
            print('READY: eleven legacy jobs recognized; running workloads:', len(workloads()))
            for entry in workloads():
                print(entry)
            print('No changes made')
        elif args.action == 'restore':
            restore()
        else:
            validate()
    except Exception as exc:
        sys.exit('STOP: ' + str(exc))
