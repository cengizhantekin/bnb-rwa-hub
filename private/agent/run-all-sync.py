#!/usr/bin/python3
"""Refresh all active BSC token database snapshots; never invoke the model or execute trades."""
import datetime
import fcntl
import json
import re
import subprocess
import sys
import time

ROOT = '/home/bhub.tm.biz.tr/public_html'
LOCK = '/run/lock/bnb-rwa-all-sync-run.lock'
SCRIPTS = ('sync-market.php', 'sync-rwa-price.php')


def log(**fields):
    print(json.dumps({'utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                      'scope': 'all_active_bsc', **fields}, separators=(',', ':')), flush=True)


def summary(output):
    if output.count('FINAL SUMMARY') != 1:
        raise ValueError('missing_summary')
    tail = output.split('FINAL SUMMARY', 1)[1]
    names = {'REQUESTED TOKENS': 'requested', 'RETURNED': 'returned',
             'SAVED': 'saved', 'MISSING': 'missing', 'BATCHES': 'batches',
             'REQUEST ATTEMPTS': 'attempts', 'OBSERVED WEIGHT SUM': 'weight',
             'RATE LIMIT ERRORS': 'rate_limit_errors'}
    data = {}
    for label, key in names.items():
        values = re.findall(r'^' + re.escape(label) + r': ([0-9]+)$', tail, re.M)
        if len(values) != 1:
            raise ValueError('invalid_summary')
        data[key] = int(values[0])
    remaining = re.search(r'^LOWEST REMAINING: ([0-9]+|-)\s*$', tail, re.M)
    data['lowest_remaining'] = (int(remaining[1]) if remaining and remaining[1] != '-' else None)
    if (data['requested'] < 1 or data['requested'] != data['returned']
            or data['requested'] != data['saved'] or data['missing'] != 0
            or data['batches'] < 1 or data['attempts'] < 1
            or not re.search(r'^END UTC: \d{4}-\d\d-\d\d \d\d:\d\d:\d\d$', tail, re.M)):
        raise ValueError('incomplete_sync')
    return data


def run(script):
    started = time.monotonic()
    try:
        result = subprocess.run(['/usr/bin/php', ROOT + '/' + script, '--all'],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                text=True, encoding='utf-8', errors='replace', timeout=180,
                                stdin=subprocess.DEVNULL, check=False)
        if result.returncode != 0:
            raise ValueError('script_failed')
        data = summary(result.stdout)
        log(script=script, status='ok', time_ms=round((time.monotonic()-started)*1000, 2), **data)
        return True
    except subprocess.TimeoutExpired:
        reason = 'timeout'
    except ValueError as error:
        reason = str(error)
    except OSError:
        reason = 'process_unavailable'
    log(script=script, status='failed', reason=reason,
        time_ms=round((time.monotonic()-started)*1000, 2))
    # Never copy arbitrary API responses, config errors or stderr into the journal.
    return False


def main():
    try:
        with open(LOCK, 'a', encoding='utf-8') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                log(status='skipped', reason='already_running')
                return 0
            log(status='started')
            results = [run(script) for script in SCRIPTS]
            ok = all(results)
            log(status='complete' if ok else 'failed', successful_scripts=sum(results))
            return 0 if ok else 1
    except OSError:
        log(status='failed', reason='lock_unavailable')
        return 1


if __name__ == '__main__':
    sys.exit(main())
