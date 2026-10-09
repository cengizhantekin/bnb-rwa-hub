"""Fixed localhost Studio inference probe using only Python's standard library."""
import json
import fcntl
import hashlib
from itertools import permutations
import os
from pathlib import Path
import re
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone

URL = 'http://127.0.0.1:9000/rwa/comment'
CACHE_DIR = Path(__file__).resolve().parent / 'rwa-cache'
CACHE_TTL = 300
FAILURE_COOLDOWN = 120
MODEL_TIMEOUT = 20
CACHE_SCHEMA = 'rwa-evidence-codes-en-v3'
COMMENT_TEXT = {
    'stale_prices': 'Some source prices are stale; those records do not establish current executable prices.',
    'stale_fetches': 'Some fetch timestamps are stale; a new data fetch is needed.',
    'source_fetch_gap': 'Some source prices are much older than their fetch timestamps; a new fetch does not refresh the source price.',
    'unknown_liquidity': 'Missing liquidity fields must be treated as unknown.',
    'zero_amm': 'A reported zero liquidity value does not establish that RFQ is unavailable.',
    'execution_checks': 'RFQ quotes, fees and slippage checks are needed to assess execution.',
}


def eligible_codes(facts):
    codes = []
    if any(f['market_source_age']['status'] == 'stale' or f['rwa_source_age']['status'] == 'stale' for f in facts):
        codes.append('stale_prices')
    if any(f['market_fetch_age']['status'] == 'stale' or f['rwa_fetch_age']['status'] == 'stale' for f in facts):
        codes.append('stale_fetches')
    if any(f['market_source_age']['seconds'] is not None and f['market_fetch_age']['seconds'] is not None
           and f['market_source_age']['seconds'] - f['market_fetch_age']['seconds'] > 3600 for f in facts):
        codes.append('source_fetch_gap')
    if any(f['formatted']['liquidity'] == 'not available' for f in facts):
        codes.append('unknown_liquidity')
    if any(f['formatted']['liquidity'] == '0' for f in facts):
        codes.append('zero_amm')
    return codes + ['execution_checks']


def decode_comment(payload, eligible):
    if not isinstance(payload, dict) or set(payload) != {'codes'}:
        return None
    codes = payload['codes']
    if (not isinstance(codes, list) or not 1 <= len(codes) <= 3
            or any(not isinstance(code, str) or code not in eligible for code in codes)
            or len(set(codes)) != len(codes)):
        return None
    return ' '.join(COMMENT_TEXT[code] for code in codes)


def number(value):
    if value is None:
        return None
    try:
        result = Decimal(str(value))
    except InvalidOperation:
        raise ValueError('Invalid numeric snapshot field') from None
    if not result.is_finite():
        raise ValueError('Non-finite snapshot value')
    return result


def display(value):
    d = number(value)
    if d is None:
        return 'not available'
    text = format(d, ',f')
    if '.' in text:
        text = text.rstrip('0').rstrip('.')
    return text


def age(value):
    if value is None:
        return {'seconds': None, 'display': 'not available', 'status': 'missing'}
    d = number(value)
    if d != d.to_integral_value():
        raise ValueError('Age must be an integer')
    seconds = int(d)
    status = 'clock_inconsistency' if seconds < 0 else ('fresh' if seconds <= 300 else 'stale')
    hours = (d / Decimal(3600)).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP)
    return {'seconds': seconds, 'display': f'{seconds} seconds ({display(hours)} hours)', 'status': status}


def verify_snapshot(snapshot):
    facts = []
    warnings = ['Volume units and token versus underlying-asset scope are unverified.',
                'The reference price has no separate source timestamp; the RWA token timestamp does not establish reference-price freshness.',
                'Holder counts do not establish community size or activity.',
                'A zero liquidity field does not establish that RFQ is unavailable.',
                'Executable arbitrage cannot be established without RFQ quotes, slippage and fees.']
    volumes = {}
    report = ['DATABASE SNAPSHOT, not a live Binance request.',
              'Captured UTC: ' + str(snapshot.get('captured_at_utc', 'not available')),
              'Price and liquidity units are not established in this report.', '',
              '| Token | Market price | Reference price | RWA token price | Holder count | Reported liquidity |',
              '| --- | --- | --- | --- | --- | --- |']
    for token in snapshot['tokens']:
        if not isinstance(token, dict):
            raise ValueError('Invalid token')
        symbol = token['symbol']
        market_age = age(token.get('market_price_age_seconds'))
        fetch_age = age(token.get('market_fetch_age_seconds'))
        rwa_age = age(token.get('rwa_price_age_seconds'))
        rwa_fetch_age = age(token.get('rwa_fetch_age_seconds'))
        raw_volume = number(token.get('raw_volume24H'))
        db_volume = number(token.get('volume_24h'))
        mapping = 'not_available'
        if raw_volume is not None and db_volume is not None:
            mapping = 'matches_at_db_precision' if raw_volume.quantize(Decimal('0.01'), rounding=ROUND_HALF_UP) == db_volume else 'mismatch'
            if mapping == 'mismatch': warnings.append(symbol + ': raw volume24H differs from the database volume.')
        if db_volume is not None:
            volumes.setdefault(db_volume, []).append(symbol)
        fact = {'symbol': symbol, 'provider': token.get('provider'), 'contract_address': token['contract_address'],
                'formatted': {key: display(token.get(key)) for key in
                    ['price', 'reference_price', 'rwa_token_price', 'liquidity', 'volume_24h', 'holders']},
                'market_source_age': market_age, 'market_fetch_age': fetch_age,
                'rwa_source_age': rwa_age, 'rwa_fetch_age': rwa_fetch_age,
                'raw_volume24H': token.get('raw_volume24H'), 'volume_mapping': mapping}
        facts.append(fact)
        f = fact['formatted']
        liquidity_label = f['liquidity']
        if liquidity_label == 'not available':
            liquidity_label = 'unknown'
        elif liquidity_label == '0':
            liquidity_label = '0 (reported value; RFQ availability unknown)'
        report.append('| ' + ' | '.join([symbol, f['price'], f['reference_price'],
                      f['rwa_token_price'], f['holders'], liquidity_label]) + ' |')
        if market_age['seconds'] is not None and fetch_age['seconds'] is not None and market_age['seconds'] - fetch_age['seconds'] > 3600:
            warnings.append(symbol + ': source price is much older than the API fetch; a new fetch does not make that price current.')
    if len({f['contract_address'].lower() for f in facts}) != len(facts):
        raise ValueError('Duplicate token identity')
    for symbols in volumes.values():
        if len(symbols) > 1:
            warnings.append(', '.join(symbols) + ': identical volume_24h values; the source field scope needs verification.')
    report.extend(['', '| Token | Market source age | Market fetch age | RWA source age | RWA fetch age |',
                   '| --- | --- | --- | --- | --- |'])
    for fact in facts:
        cells = [fact['symbol']]
        for field in ['market_source_age', 'market_fetch_age', 'rwa_source_age', 'rwa_fetch_age']:
            item = fact[field]
            cells.append(item['display'] + ' [' + item['status'] + ']')
        report.append('| ' + ' | '.join(cells) + ' |')
    report.extend(['', 'DATA QUALITY WARNINGS:', *warnings])
    return facts, warnings, '\n'.join(report)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


ALLOWED_COMMENTS = {
    ' '.join(COMMENT_TEXT[code] for code in codes)
    for length in (1, 2, 3) for codes in permutations(COMMENT_TEXT, length)
}


def valid_comment(text):
    # Only exact application-owned sentences may reach the user.
    return isinstance(text, str) and text in ALLOWED_COMMENTS


def load_state(path):
    try:
        if path.stat().st_size > 16384:
            return {}
        with path.open(encoding='utf-8') as stream:
            value = json.load(stream)
        return value if isinstance(value, dict) else {}
    except (OSError, ValueError):
        return {}


def save_state(path, value):
    temporary = path.with_name(path.name + '.' + str(os.getpid()) + '.tmp')
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as stream:
            if os.geteuid() == 0:
                owner = CACHE_DIR.stat()
                os.fchown(stream.fileno(), owner.st_uid, owner.st_gid)
            json.dump(value, stream, ensure_ascii=False)
        os.replace(temporary, path)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def qualitative_facts(facts):
    # Small packet: numeric report remains deterministic and is not rewritten by the model.
    result = []
    for fact in facts:
        source = fact['market_source_age']['seconds']
        fetched = fact['market_fetch_age']['seconds']
        liquidity = fact['formatted']['liquidity']
        result.append({
            'symbol': fact['symbol'],
            'liquidity_state': 'missing' if liquidity == 'not available' else ('zero' if liquidity == '0' else 'reported'),
            'market_source_status': fact['market_source_age']['status'],
            'market_fetch_status': fact['market_fetch_age']['status'],
            'rwa_source_status': fact['rwa_source_age']['status'],
            'rwa_fetch_status': fact['rwa_fetch_age']['status'],
            'source_much_older_than_fetch': source is not None and fetched is not None and source - fetched > 3600,
            'volume_mapping': fact['volume_mapping'],
        })
    return result


def model_comment(snapshot, facts, warnings):
    qualitative = qualitative_facts(facts)
    eligible = eligible_codes(facts)
    # Source values/timestamps invalidate the cache; ticking age/capture times do not.
    # Freshness categories are included, so crossing the freshness threshold also invalidates it.
    stable_tokens = [{k: v for k, v in token.items() if not k.endswith('_age_seconds')}
                     for token in snapshot['tokens']]
    stable_tokens.sort(key=lambda token: token['symbol'])
    encoded = json.dumps({'schema': CACHE_SCHEMA, 'tokens': stable_tokens,
                          'qualitative': sorted(qualitative, key=lambda fact: fact['symbol']),
                          'warnings': warnings}, ensure_ascii=False, sort_keys=True).encode('utf-8')
    cache_key = hashlib.sha256(encoded).hexdigest()
    info = {'model_status': 'unavailable', 'model_called': False,
            'studio_http_requests': 0, 'studio_http_status': None,
            'studio_time_ms': 0, 'cache_hit': False, 'cache_age_seconds': None}
    cache_path = CACHE_DIR / ('comment-' + hashlib.sha256(snapshot['ticker'].encode('ascii')).hexdigest()[:16] + '.json')

    def cached():
        saved = load_state(cache_path)
        created = saved.get('created_at')
        if not isinstance(created, (int, float)) or isinstance(created, bool):
            return None
        elapsed = time.time() - created
        if (saved.get('key') == cache_key and 0 <= elapsed <= CACHE_TTL
                and decode_comment(saved.get('selection'), eligible) == saved.get('comment')
                and valid_comment(saved.get('comment'))):
            info.update(model_status='cached', cache_hit=True, cache_age_seconds=round(elapsed, 2))
            return saved['comment']
        return None

    # Fail closed for model calls if shared coordination storage is unavailable.
    try:
        if not CACHE_DIR.is_dir():
            info['model_status'] = 'cache_unavailable'
            return None, info
        comment = cached()
        if comment is not None:
            return comment, info
        lock_fd = os.open(CACHE_DIR / 'inference.lock', os.O_CREAT | os.O_RDWR, 0o600)
    except OSError:
        info['model_status'] = 'cache_unavailable'
        return None, info

    with os.fdopen(lock_fd, 'a') as lock:
        try:
            if os.geteuid() == 0:
                owner = CACHE_DIR.stat()
                os.fchown(lock.fileno(), owner.st_uid, owner.st_gid)
        except OSError:
            info['model_status'] = 'cache_unavailable'
            return None, info
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            info['model_status'] = 'busy'
            return None, info
        # Check again after taking the shared cross-process lock.
        comment = cached()
        if comment is not None:
            return comment, info
        failure = load_state(CACHE_DIR / 'failure.json')
        failed_at = failure.get('created_at')
        if isinstance(failed_at, (int, float)) and not isinstance(failed_at, bool) and 0 <= time.time() - failed_at < FAILURE_COOLDOWN:
            info['model_status'] = 'cooldown'
            return None, info
        selection_request = {'eligible_codes': eligible}
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        req = urllib.request.Request(URL, data=json.dumps(selection_request, ensure_ascii=False).encode('utf-8'),
                                     method='POST', headers={'Content-Type': 'application/json', 'Accept': 'application/json'})
        start = time.perf_counter()
        info.update(model_called=True, studio_http_requests=1)
        try:
            with opener.open(req, timeout=MODEL_TIMEOUT) as response:
                info['studio_http_status'] = response.status
                raw = response.read(65537)
            if info['studio_http_status'] != 200 or len(raw) > 65536:
                raise ValueError('Invalid response')
            payload = json.loads(raw.decode('utf-8'))
            comment = decode_comment(payload, eligible)
            if not valid_comment(comment):
                info['model_status'] = 'quality_rejected'
                raise ValueError('Comment rejected')
            comment = comment.strip()
            save_state(cache_path, {'key': cache_key, 'created_at': time.time(), 'comment': comment, 'selection': payload})
            info['model_status'] = 'live'
            return comment, info
        except urllib.error.HTTPError as exc:
            info['studio_http_status'] = exc.code
            exc.close()
        except (urllib.error.URLError, TimeoutError, OSError, ValueError, TypeError, UnicodeError):
            pass
        finally:
            info['studio_time_ms'] = round((time.perf_counter() - start) * 1000, 2)
        # Studio may translate provider 429 to 500. Cool down on either error.
        try:
            save_state(CACHE_DIR / 'failure.json', {'created_at': time.time()})
        except OSError:
            pass
        return None, info


def probe(request):
    if not isinstance(request, dict) or request.get('action') != 'rwa_compare':
        raise ValueError('Unsupported request')
    request_id = request.get('request_id')
    if not isinstance(request_id, str) or not 1 <= len(request_id) <= 64:
        raise ValueError('Invalid request_id')
    ticker = request.get('ticker')
    if not isinstance(ticker, str) or not re.fullmatch(r'[A-Z0-9][A-Z0-9._-]{0,15}', ticker):
        raise ValueError('Invalid ticker')
    snapshot = request.get('snapshot')
    if (not isinstance(snapshot, dict) or snapshot.get('ticker') != ticker
            or snapshot.get('source') != 'database_snapshot' or str(snapshot.get('chain_id')) != '56'
            or not isinstance(snapshot.get('tokens'), list) or not 1 <= len(snapshot['tokens']) <= 10):
        raise ValueError('Invalid snapshot')
    for token in snapshot['tokens']:
        if (not isinstance(token, dict) or token.get('underlying_ticker') != ticker
                or str(token.get('chain_id')) != '56'
                or token.get('provider') not in {'bstock','ondo','xstocks'}
                or not isinstance(token.get('symbol'), str)
                or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,39}', token['symbol'])
                or not isinstance(token.get('contract_address'), str)
                or not re.fullmatch(r'0x[0-9a-fA-F]{40}', token['contract_address'])):
            raise ValueError('Unexpected token identity')
    facts, warnings, deterministic_report = verify_snapshot(snapshot)
    comment, info = model_comment(snapshot, facts, warnings)
    accepted = comment is not None
    if accepted:
        label = 'MODEL-SELECTED OBSERVATIONS (cached):' if info['cache_hit'] else 'MODEL-SELECTED OBSERVATIONS:'
        combined = deterministic_report + '\n\n' + label + '\n' + comment
    else:
        explanation = {
            'busy': 'Another model request is in progress; the deterministic data report is available.',
            'cooldown': 'Model cooldown is active after a failure; the deterministic data report is available.',
            'cache_unavailable': 'Model cache is unavailable; the deterministic data report is available.',
            'quality_rejected': 'Model selection failed validation; the deterministic data report is available.',
        }.get(info['model_status'], 'Model observations are temporarily unavailable; the deterministic data report is available.')
        warnings.append(explanation)
        combined = deterministic_report + '\n\nMODEL STATUS: ' + explanation
    return {
        'ok': True, 'action': 'rwa_compare', 'request_id': request_id,
        'ticker': ticker, 'snapshot': snapshot, 'verified_facts': facts, 'quality_warnings': warnings,
        'model_comment_accepted': accepted, 'result': combined, **info,
        'cache_ttl_seconds': CACHE_TTL, 'failure_cooldown_seconds': FAILURE_COOLDOWN,
        'python_version': sys.version.split()[0],
        'completed_at_utc': datetime.now(timezone.utc).isoformat(),
        'token_usage': None, 'request_weight': None,
    }


def main():
    try:
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            raise ValueError('Request too large')
        response = probe(json.loads(raw))
        exit_code = 0
    except urllib.error.HTTPError as exc:
        response = {'ok': False, 'error': f'Studio HTTP {exc.code}; no payment attempted'}
        exit_code = 1
    except (urllib.error.URLError, TimeoutError, OSError):
        response = {'ok': False, 'error': 'Cannot reach local Studio or request timed out'}
        exit_code = 1
    except (ValueError, TypeError, UnicodeError, KeyError, OverflowError):
        response = {'ok': False, 'error': 'Invalid comparison request or snapshot'}
        exit_code = 1
    print(json.dumps(response, ensure_ascii=False))
    return exit_code


if __name__ == '__main__':
    sys.exit(main())
