#!/usr/bin/python3
"""Refresh the supported BSC catalogue without model calls or transactions."""
import datetime
import fcntl
import json
import re
import subprocess
import sys
import time

LOCK = '/run/lock/bnb-rwa-catalog-sync-run.lock'
SCRIPT = '/home/bhub.tm.biz.tr/public_html/sync-tokens.php'


def log(**fields):
    print(json.dumps({'utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                      'scope': 'bsc_catalogue', **fields}, separators=(',', ':')), flush=True)


def summary(output):
    if output.count('FINAL SUMMARY') != 1:
        raise ValueError('missing_summary')
    tail = output.split('FINAL SUMMARY', 1)[1]
    labels = {'FETCHED': 'fetched', 'INSERTED': 'inserted', 'UPDATED': 'updated',
              'DEACTIVATED': 'deactivated', 'ERRORS': 'errors',
              'REQUEST ATTEMPTS': 'attempts', 'OBSERVED WEIGHT SUM': 'weight',
              'RATE LIMIT ERRORS': 'rate_limit_errors'}
    data = {}
    for label, key in labels.items():
        matches = re.findall(r'^' + re.escape(label) + r': ([0-9]+)$', tail, re.M)
        if len(matches) != 1:
            raise ValueError('invalid_summary')
        data[key] = int(matches[0])
    remaining = re.search(r'^LOWEST REMAINING: ([0-9]+|-)\s*$', tail, re.M)
    data['lowest_remaining'] = int(remaining[1]) if remaining and remaining[1] != '-' else None
    if (data['fetched'] < 1 or data['errors'] != 0
            or data['inserted'] + data['updated'] != data['fetched']
            or not re.search(r'^END UTC: \d{4}-\d\d-\d\d \d\d:\d\d:\d\d$', tail, re.M)):
        raise ValueError('incomplete_catalogue')
    return data


def main():
    started = time.monotonic()
    try:
        with open(LOCK, 'a', encoding='utf-8') as lock:
            deadline = time.monotonic() + 90
            while True:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= deadline:
                        raise ValueError('sync_busy')
                    time.sleep(1)
            log(status='started')
            result = subprocess.run(['/usr/bin/php', SCRIPT], stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    text=True, encoding='utf-8', errors='replace',
                                    timeout=180, check=False)
            if result.returncode != 0:
                raise ValueError('script_failed')
            data = summary(result.stdout)
            log(status='complete', time_ms=round((time.monotonic()-started)*1000, 2), **data)
            return 0
    except subprocess.TimeoutExpired:
        reason = 'timeout'
    except ValueError as error:
        reason = str(error)
    except OSError:
        reason = 'process_or_lock_unavailable'
    # Only fixed reason codes and numeric totals are journalled, never raw PHP output.
    log(status='failed', reason=reason, time_ms=round((time.monotonic()-started)*1000, 2))
    return 1


if __name__ == '__main__':
    sys.exit(main())
