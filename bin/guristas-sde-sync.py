#!/usr/bin/env python3
"""Daily SDE build check and atomic reference activation; not a homepage request.

Designed for the existing apache cron user. Generates only a compact BUILD reference,
not the entire SDE; a separate full index remains future work.
"""
import argparse
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
URL = 'https://developers.eveonline.com/static-data/tranquility/latest.jsonl'
ARCHIVE = 'https://developers.eveonline.com/static-data/tranquility/eve-online-static-data-{}-jsonl.zip'


def latest_build(data):
    for line in data.splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        if row.get('_key') != 'sde':
            continue
        candidate = row.get('_value', row.get('buildNumber', row.get('build')))
        if isinstance(candidate, dict):
            candidate = candidate.get('buildNumber', candidate.get('build', candidate.get('version')))
        if isinstance(candidate, bool) or not str(candidate).isdigit():
            raise ValueError('Unexpected SDE build metadata')
        build = int(candidate)
        if not 1000000 <= build <= 99999999:
            raise ValueError('Invalid SDE build number')
        return build
    raise ValueError('SDE build record missing')


def reference_valid(ref, expected_build):
    if str(ref.get('sde_build')) != str(expected_build):
        raise ValueError('Imported reference SDE build mismatch')
    minimums = {'blueprints': 50, 'pi_schematics': 60, 'reprocessing': 1000, 'types': 500}
    counts = {}
    for key, minimum in minimums.items():
        count = len(ref.get(key, {}))
        if count < minimum:
            raise ValueError(f'Imported {key} only {count}, expected >= {minimum}')
        counts[key] = count
    return counts


def fetch_metadata():
    req = urllib.request.Request(URL, headers={'User-Agent': 'guristas.net-SDE-sync/1.0'})
    with urllib.request.urlopen(req, timeout=30) as response:
        if response.status != 200:
            raise RuntimeError('SDE metadata HTTP status not 200')
        return response.read(65536).decode('utf-8')


def emit(record):
    # Fixed-size status snapshot for read-only health checks; no accumulating log.
    status = ROOT / 'storage/cache/economic-intelligence/sde-sync-status.json'
    status.parent.mkdir(parents=True, exist_ok=True)
    content = dict(record, checked_at=dt.datetime.now(dt.timezone.utc).isoformat())
    handle, name = tempfile.mkstemp(prefix='sde-status-', dir=status.parent)
    try:
        with os.fdopen(handle, 'w', encoding='utf-8') as out:
            json.dump(content, out)
        os.chmod(name, 0o640)
        os.replace(name, status)
    finally:
        if os.path.exists(name):
            os.unlink(name)
    print(json.dumps(content))


def sync(force=False):
    runtime = ROOT / 'storage/cache/economic-intelligence'
    runtime.mkdir(parents=True, exist_ok=True)
    lock_path = runtime / 'sde-sync.lock'
    with lock_path.open('a+b') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            emit({'ok': True, 'status': 'already_running'})
            return 0
        active = runtime / 'industry-reference-live.json'
        source = active if active.exists() else ROOT / 'app/data/industry-reference.json'
        current = json.loads(source.read_text(encoding='utf-8'))
        previous = str(current.get('sde_build', ''))
        newest = latest_build(fetch_metadata())
        if previous == str(newest) and not force:
            emit({'ok': True, 'status': 'unchanged', 'build': newest})
            return 0
        # Keep any failed download/import completely separate from active reference.
        # Temporary files are deleted before exit, including on exceptions.
        with tempfile.TemporaryDirectory(prefix='sde-sync-', dir=runtime) as tempdir:
            temp = Path(tempdir)
            archive = temp / 'sde.zip'
            url = ARCHIVE.format(newest)
            req = urllib.request.Request(url, headers={'User-Agent': 'guristas.net-SDE-sync/1.0'})
            with urllib.request.urlopen(req, timeout=90) as response, archive.open('wb') as target:
                if response.status != 200:
                    raise RuntimeError('SDE archive HTTP status not 200')
                length = 0
                while chunk := response.read(1024 * 1024):
                    length += len(chunk)
                    if length > 210 * 1024 * 1024:
                        raise RuntimeError('SDE archive unexpectedly large')
                    target.write(chunk)
            # Preserve runtime's currently refreshed LP offers, not a stale Git snapshot.
            offers_cache = runtime / 'lp-offers.json'
            offers = json.loads(offers_cache.read_text(encoding='utf-8')) if offers_cache.exists() else current.get('offers_snapshot', [])
            if isinstance(offers, dict):
                offers = offers.get('offers', offers.get('offers_snapshot', []))
            if not isinstance(offers, list) or len(offers) < 100:
                raise RuntimeError('Insufficient LP offers for reference import')
            offers_path = temp / 'offers.json'
            offers_path.write_text(json.dumps(offers), encoding='utf-8')
            stage = temp / 'candidate.json'
            command = [sys.executable, str(ROOT / 'tools/build-industry-reference.py'), str(archive), str(offers_path), str(stage), '--include-upstream', '--include-pi', '--include-reprocessing']
            result = subprocess.run(command, capture_output=True, text=True, timeout=240)
            if result.returncode != 0:
                raise RuntimeError('SDE importer failed: ' + result.stderr[-600:])
            candidate = json.loads(stage.read_text(encoding='utf-8'))
            counts = reference_valid(candidate, newest)
            # Same filesystem, atomic replacement. Any previous reference survives
            # failures until after all validation finishes.
            os.chmod(stage, 0o640)
            os.replace(stage, active)
        emit({'ok': True, 'status': 'activated', 'previous_build': previous, 'build': newest, 'counts': counts, 'activated_at': dt.datetime.now(dt.timezone.utc).isoformat()})
        return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--force', action='store_true')
    args = parser.parse_args()
    try:
        return sync(args.force)
    except Exception as ex:
        emit({'ok': False, 'status': 'retained_previous_reference', 'error': str(ex)})
        return 1


if __name__ == '__main__':
    sys.exit(main())
