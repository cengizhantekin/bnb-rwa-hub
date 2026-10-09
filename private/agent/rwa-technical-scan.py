#!/usr/bin/python3
"""Persist primary-provider daily candles and build a deterministic technical watchlist."""
import argparse
import datetime as dt
from decimal import Decimal, localcontext
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent
PATTERN = re.compile(r'[A-Z0-9][A-Z0-9._-]{0,15}')


def utc():
    return dt.datetime.now(dt.timezone.utc).isoformat()


def save(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, temporary = tempfile.mkstemp(prefix='.snapshot-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(value, f, indent=2, allow_nan=False)
            f.write('\n'); f.flush(); os.fsync(f.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)


def load_engine():
    spec = importlib.util.spec_from_file_location('technical_engine', ROOT/'test-rwa-technicals.py')
    engine = importlib.util.module_from_spec(spec); spec.loader.exec_module(engine)
    return engine


def collect(args):
    p = subprocess.run(['/usr/bin/php', str(ROOT/'test-rwa-candles.php'), *args],
                       stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, text=True,
                       encoding='utf-8', timeout=260, check=False)
    if len(p.stdout) > 2_000_000: raise ValueError('collector_output_too_large')
    d = json.loads(p.stdout)
    if p.returncode or d.get('ok') is not True:
        raise ValueError(d.get('error', 'collector_unavailable'))
    return d


def closed_rows(item, cutoff, engine):
    by_time = {}
    for row in item['rows']:
        if isinstance(row, list) and len(row) == 7:
            try:
                stamp = engine.number(row[5], True)
                if stamp == stamp.to_integral_value() and stamp+engine.DAY <= cutoff:
                    by_time[int(stamp)] = row
            except ValueError:
                pass
    return [by_time[t] for t in sorted(by_time)]


def captured_rows(rows, cutoff, engine):
    # Retain malformed rows and conflicting duplicate timestamps for field validation.
    # Remove only rows whose valid timestamp establishes they were still open at capture.
    retained = []
    for row in rows:
        try:
            stamp = engine.number(row[5], True) if isinstance(row,list) and len(row)==7 else None
            if stamp is not None and stamp == stamp.to_integral_value() and stamp+engine.DAY > cutoff:
                continue
        except (ValueError, TypeError): pass
        retained.append(row)
    return retained


def assess(item, cutoff, engine):
    f = engine.feature_assessment(item['rows'], '1d', cutoff, allow_historical=True)
    features = f['features']
    required = ['SMA20', 'SMA50', 'RSI14']
    base = {k: item.get(k) for k in ('ticker', 'provider', 'symbol', 'contract_address')}
    base.update(source='daily_token_candles', timeframe='1d',
                indicators={k: v['value'] for k, v in features.items() if k != 'support_resistance'},
                levels=features['support_resistance']['value'],
                signals=[], attention_points=0, status='not_selected',
                availability={k: {'status': v['status'], 'reasons': v['reasons'] if v['status']=='unavailable' else []}
                              for k, v in features.items()},
                checked_features=['trend', 'ma_pullback', 'support_recovery', 'range_breakout', 'breakout_retest'],
                execution_checked=False, stock_price_normalization_verified=False)
    rows = closed_rows(item, cutoff, engine)
    base['source_candle_open_utc'] = dt.datetime.fromtimestamp(rows[-1][5]/1000, dt.timezone.utc).isoformat() if rows else None
    base['diagnostics'] = f  # Internal, do not forward methodology metadata to the UI.
    if rows and f.get('close_history_last_utc') == base['source_candle_open_utc']:
        base['close'] = engine.shown(engine.number(rows[-1][3], True))
    if any(features[k]['value'] is None for k in required):
        base['status'] = 'technical_data_unavailable'; return base
    close = engine.number(rows[-1][3], True)
    ma20, ma50, rsi = (Decimal(features[k]['value']) for k in required)
    base['close'] = engine.shown(close)
    # Previous assessment uses the same collection cutoff and excludes only the last closed row.
    previous_rows = [r for r in item['rows'] if not (isinstance(r, list) and len(r)==7 and r[5]==rows[-1][5])]
    previous = engine.feature_assessment(previous_rows, '1d', min(cutoff, rows[-1][5]+1000), allow_historical=True)['features']['RSI14']['value']
    previous_rsi = Decimal(previous) if previous is not None else None
    previous_close = engine.number(rows[-2][3], True) if len(rows) >= 2 else None
    def add(code, points, evidence):
        base['signals'].append({'code': code, 'evidence': evidence}); base['attention_points'] += points
    evidence = {'close': engine.shown(close), 'sma20': str(ma20), 'sma50': str(ma50), 'rsi14': str(rsi)}
    if close > ma20 > ma50 and 45 <= rsi <= 70:
        add('trend_alignment', 2, evidence)
    if ma20 > ma50 and close >= ma50 and abs(close/ma20-1) <= Decimal('0.015') and 40 <= rsi <= 65 and previous_rsi is not None and rsi > previous_rsi:
        add('ma_pullback_recovery', 3, {**evidence, 'previous_rsi14': str(previous_rsi)})
    for zone in base['levels'] or []:
        if zone['kind']=='support' and Decimal(zone['distance_pct']) <= 2 and previous_close is not None and close > previous_close and 40 <= rsi <= 65:
            add('support_recovery', 3, {**evidence, 'support_center': zone['center'], 'distance_pct': zone['distance_pct']})
            break
    if f['usable_range_bars'] >= 22 and len(rows) >= 22:
        # These latest rows are valid because the field-specific range segment covers >=22 bars.
        recent = rows[-22:]
        resistance = max(engine.number(r[1], True) for r in recent[:-2])
        previous_close = engine.number(recent[-2][3], True)
        low = engine.number(recent[-1][2], True)
        base['prior_range_resistance'] = engine.shown(resistance)
        if previous_close > resistance*Decimal('1.003') and resistance*Decimal('0.995') <= low <= resistance*Decimal('1.005') and close > resistance and 45 <= rsi <= 75:
            add('possible_breakout_retest', 4, {**evidence, 'prior_range_resistance': engine.shown(resistance), 'latest_low': engine.shown(low)})
        elif close > resistance*Decimal('1.003') and previous_close <= resistance*Decimal('1.003') and 50 <= rsi <= 75:
            add('possible_range_breakout', 3, {**evidence, 'prior_range_resistance': engine.shown(resistance)})
    if base['signals']: base['status'] = 'technical_candidate'
    return base


def primary_catalog(rows):
    groups = {'bstock': set(), 'ondo': set()}
    for r in rows:
        provider, ticker = r.get('provider'), r.get('ticker')
        if not isinstance(ticker, str) or not PATTERN.fullmatch(ticker):
            raise ValueError('invalid_catalogue_ticker')
        if provider in groups: groups[provider].add(ticker)
    if not groups['bstock'] or len(groups['bstock']) > 60 or len(groups['ondo']) > 1000:
        raise ValueError('unexpected_primary_catalogue_size')
    return {**{t: 'ondo' for t in sorted(groups['ondo']-groups['bstock'])},
            **{t: 'bstock' for t in sorted(groups['bstock'])}}


def saved_payloads(store, allowed):
    payloads = []
    for ticker, provider in sorted(allowed.items()):
        path = store/'candles'/(ticker+'.json')
        if not path.exists(): continue
        payload = json.loads(path.read_text())
        if payload.get('ticker') != ticker or len(payload.get('results', [])) != 1:
            raise ValueError('invalid_saved_series')
        if payload['results'][0].get('provider') != provider: continue
        payloads.append(payload)
    return payloads


def batch_tickers(store, allowed, limit):
    ages = {}
    for payload in saved_payloads(store, allowed):
        ticker = payload['ticker']
        if allowed[ticker] == 'ondo': ages[ticker] = payload['cutoff_ms']
    # Unchecked first, then oldest collection. No cursor can strand a failed ticker.
    return sorted((t for t, p in allowed.items() if p == 'ondo'),
                  key=lambda t: (ages.get(t, 0), t))[:limit]


def persist_payload(store, payload, allowed=None):
    ticker = payload.get('ticker')
    if not isinstance(ticker, str) or not PATTERN.fullmatch(ticker): raise ValueError('invalid_ticker')
    items = payload.get('results', [])
    if len(items) != 1: raise ValueError('ambiguous_primary_series')
    item = items[0]
    expected_provider = allowed.get(ticker) if allowed is not None else 'bstock'
    if item.get('provider') != expected_provider or item.get('bar') != '1d' or item.get('ticker') != ticker or item.get('error'):
        raise ValueError('invalid_primary_series')
    if not isinstance(item.get('symbol'), str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,39}', item['symbol']):
        raise ValueError('invalid_primary_series')
    if not isinstance(item.get('contract_address'), str) or not re.fullmatch(r'0x[0-9a-fA-F]{40}', item['contract_address']):
        raise ValueError('invalid_primary_series')
    path = store/'candles'/(ticker+'.json')
    if path.exists():
        old = json.loads(path.read_text())
        prior = old['results'][0]
        if prior.get('provider') == item.get('provider') and prior.get('contract_address') == item.get('contract_address') and prior.get('symbol') == item.get('symbol'):
            # Keep old history, but retain duplicates in each fresh response for the validator.
            fresh_stamps = {r[5] for r in item['rows'] if isinstance(r,list) and len(r)==7 and isinstance(r[5],(int,float))}
            prior_rows = captured_rows(prior['rows'], old['cutoff_ms'], load_engine())
            retained = [r for r in prior_rows if isinstance(r,list) and len(r)==7 and isinstance(r[5],(int,float)) and r[5] not in fresh_stamps]
            item = {**item, 'rows': sorted(retained, key=lambda r:r[5])[-2000:] + item['rows']}
            payload = {**payload, 'results': [item]}
    engine = load_engine()
    payload = {**payload, 'results': [{**item, 'rows': captured_rows(item['rows'], payload['cutoff_ms'], engine)}]}
    save(path, payload)
    return payload['results'][0]


def publish(store, payloads, metrics, engine, allowed=None):
    records = []
    with localcontext() as ctx:
        ctx.prec = 50
        for p in payloads:
            item = persist_payload(store, p, allowed)
            # Exclude candles that were still open when fetched, even if rebuilding much later.
            evaluated={**item, 'rows':captured_rows(item['rows'],p['cutoff_ms'],engine)}
            record = assess(evaluated, int(time.time()*1000), engine)
            # Collection time belongs to this payload, never to the later report rebuild.
            record['api_fetch_at_utc'] = dt.datetime.fromtimestamp(p['cutoff_ms']/1000, dt.timezone.utc).isoformat()
            records.append(record)
    records.sort(key=lambda r: (-r['attention_points'], r['ticker']))
    result = {'ok': True, 'schema_version': 1, 'generated_at_utc': utc(),
              'scope': 'primary_provider_technical_watchlist' if allowed else 'bstock_technical_watchlist',
              'scope_complete': len(records) == len(allowed) if allowed else metrics['scope_complete'],
              'eligible_count': len(allowed) if allowed else len(records),
              'pending_tickers': sorted(set(allowed or {}) - {r['ticker'] for r in records}),
              'provider_selection_policy': 'bStocks first; Ondo only where bStocks is absent; xStocks-only excluded.',
              'provider_counts': {p: sum(r['provider']==p for r in records) for p in ('bstock','ondo')},
              'scanned_count': len(records), 'candidate_count': sum(r['status']=='technical_candidate' for r in records),
              'data_unavailable_count': sum(r['status']=='technical_data_unavailable' for r in records),
              'model_called': False, 'database_writes': 0, 'binance_http_requests': metrics['requests'],
              'request_metrics': metrics['request_metrics'], 'records': records,
              'methodology': {'points_are_probability': False, 'source': 'token_price_history',
                             'rank_basis': 'Rule-based technical attention points; no performance claim.',
                             'other_evidence': 'Execution quotes, fundamentals and participation evidence have not yet been integrated.'}}
    save(store/'technical-watchlist.json', result)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--import-report', type=Path)
    mode.add_argument('--sync-all-bstocks', action='store_true')
    mode.add_argument('--sync-ondo-batch', action='store_true')
    parser.add_argument('--batch-size', type=int, default=30)
    mode.add_argument('--rebuild', action='store_true')
    mode.add_argument('--self-test', action='store_true')
    parser.add_argument('--store', type=Path, default=ROOT/'technical-data')
    args = parser.parse_args(); os.umask(0o077)
    if not 1 <= args.batch_size <= 30: parser.error('--batch-size must be between 1 and 30')
    engine = load_engine()
    if args.self_test:
        return tests(engine)
    store = args.store.resolve(); store.mkdir(parents=True, exist_ok=True, mode=0o700)
    with open(store/'sync.lock', 'a') as lock:
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({'ok': False, 'error': 'technical_sync_already_running'})); return 1
        payloads = []; allowed = None; metrics = {'scope_complete': False, 'requests': 0, 'request_metrics': []}
        if args.import_report:
            report = json.loads(args.import_report.read_text())
            if not report.get('ok') or report.get('stop_reason'):
                raise ValueError('incomplete_import_report')
            if report.get('provider') == 'ondo':
                # Import requires the diagnostic's complete source-precedence catalogue.
                coverage = report['coverage']
                allowed = {**{t: 'ondo' for t in coverage['ondo_additional_tickers']},
                           **{t: 'bstock' for t in coverage['bstock_primary_tickers']}}
                if any(not PATTERN.fullmatch(t) for t in allowed) or len(allowed)>1000:
                    raise ValueError('invalid_import_catalogue')
                if set(coverage['ondo_additional_tickers']) & set(coverage['bstock_primary_tickers']):
                    raise ValueError('invalid_import_catalogue')
                if report.get('tested_count') != len(report['tested_tickers']):
                    raise ValueError('incomplete_import_report')
                # Source precedence comes from the current DB, never an outdated import catalogue.
                allowed = primary_catalog(collect(['--catalog'])['rows'])
            for entry in report['tested_tickers']:
                p = entry['raw_payload']
                if not p.get('ok'): raise ValueError('invalid_import_payload')
                if allowed and allowed.get(p['ticker']) != 'ondo': raise ValueError('invalid_import_catalogue')
                persist_payload(store, p, allowed)
                payloads.append(p)
            if allowed: payloads = saved_payloads(store, allowed)
            metrics['scope_complete'] = report.get('selection_method') == 'all'
        elif args.rebuild:
            prior = json.loads((store/'technical-watchlist.json').read_text())
            metrics['scope_complete'] = prior['scope_complete']
            if prior['scope'] == 'primary_provider_technical_watchlist':
                # Rebuild revalidates the current DB catalogue, without Binance requests.
                allowed = primary_catalog(collect(['--catalog'])['rows'])
                payloads = saved_payloads(store, allowed)
            else:
                for rec in prior['records']:
                    if not PATTERN.fullmatch(rec['ticker']): raise ValueError('invalid_saved_ticker')
                    payloads.append(json.loads((store/'candles'/(rec['ticker']+'.json')).read_text()))
        else:
            catalog = collect(['--catalog'])
            allowed = primary_catalog(catalog['rows'])
            tickers = batch_tickers(store, allowed, args.batch_size) if args.sync_ondo_batch else sorted(t for t,p in allowed.items() if p=='bstock')
            started = time.monotonic()
            for index, ticker in enumerate(tickers):
                if time.monotonic()-started > 1200: raise ValueError('sync_time_budget_exceeded')
                if index: time.sleep(2)
                p = collect(['--provider='+allowed[ticker], '--ticker='+ticker])
                persist_payload(store, p, allowed)
                metrics['requests'] += p['binance_http_requests']; metrics['request_metrics'].extend(p['requests'])
                print(json.dumps({'utc': utc(), 'ticker': ticker, 'provider': allowed[ticker], 'status': 'candles_saved', 'requests': p['binance_http_requests']}), flush=True)
            payloads = saved_payloads(store, allowed)
            metrics['scope_complete'] = True
        if not payloads: raise ValueError('no_saved_candles')
        result = publish(store, payloads, metrics, engine, allowed)
        print(json.dumps({k: result[k] for k in ('ok','scope','scope_complete','scanned_count','candidate_count','data_unavailable_count','eligible_count','provider_counts','binance_http_requests','model_called')}))
        print('OUTPUT: '+str(store/'technical-watchlist.json'))
    return 0


def tests(engine):
    base = 1704067200000
    rows = [[100+i,102+i,99+i,101+i,1,base+i*engine.DAY,1] for i in range(70)]
    item = {'ticker':'TEST','provider':'bstock','symbol':'TESTB','bar':'1d','contract_address':'0x'+'1'*40,'rows':rows}
    cutoff=base+70*engine.DAY+1000
    record = assess(item, cutoff, engine)
    assert record['status']=='not_selected'  # Rising prices alone are insufficient: RSI=100.
    stale = assess(item, cutoff+3*engine.DAY, engine)
    assert stale['indicators']['SMA20']==record['indicators']['SMA20']
    assert stale['status']=='not_selected'  # Historical evidence is retained, not a current-opportunity claim.
    gap = {**item,'rows': rows[:30]+rows[31:]}
    assert assess(gap,cutoff,engine)['indicators']['SMA50'] is not None
    with tempfile.TemporaryDirectory() as d:
        store=Path(d); p={'ok':True,'ticker':'TEST','cutoff_ms':cutoff,'results':[item]}
        r=publish(store,[p],{'scope_complete':False,'requests':0,'request_metrics':[]},engine)
        assert r['scanned_count']==1 and (store/'candles/TEST.json').exists()
        assert r['records'][0]['api_fetch_at_utc']==dt.datetime.fromtimestamp(cutoff/1000,dt.timezone.utc).isoformat()
        fresh={**p,'results':[{**item,'rows':[rows[-1][:3]+[rows[-1][3]+1]+rows[-1][4:]]}]}
        merged=persist_payload(store,fresh)
        assert len(merged['rows'])==70 and merged['rows'][-1][3]==rows[-1][3]+1
        # A captured open candle remains excluded during a later rebuild.
        captured={**p,'results':[{**item,'rows':rows+[[999,1000,998,999,1,base+70*engine.DAY,1]]}]}
        historical=publish(store,[captured],{'scope_complete':False,'requests':0,'request_metrics':[]},engine)
        assert historical['records'][0]['diagnostics']['usable_close_bars']==70
        before=(store/'technical-watchlist.json').read_bytes()
        bad={**p,'results':[dict(item,provider='ondo')]}
        try: publish(store,[bad],{'scope_complete':False,'requests':0,'request_metrics':[]},engine)
        except ValueError: pass
        else: raise AssertionError('source gate')
        assert (store/'technical-watchlist.json').read_bytes()==before
    allowed = primary_catalog([{'ticker':'TEST','provider':'bstock'}, {'ticker':'TEST','provider':'ondo'},
                               {'ticker':'ONLY','provider':'ondo'}, {'ticker':'XONLY','provider':'xstocks'}])
    assert allowed == {'ONLY':'ondo', 'TEST':'bstock'}
    with tempfile.TemporaryDirectory() as d:
        store=Path(d)
        assert batch_tickers(store, allowed, 1)==['ONLY']
        ondo={**item,'ticker':'ONLY','provider':'ondo','symbol':'ONLYon'}
        op={'ok':True,'ticker':'ONLY','cutoff_ms':cutoff,'results':[ondo]}
        publish(store,[op],{'scope_complete':False,'requests':0,'request_metrics':[]},engine,allowed)
        result=json.loads((store/'technical-watchlist.json').read_text())
        assert result['scope_complete'] is False and result['pending_tickers']==['TEST']
        assert result['provider_counts']=={'bstock':0,'ondo':1}
        overlap={**op,'ticker':'TEST','results':[{**ondo,'ticker':'TEST'}]}
        try: persist_payload(store,overlap,allowed)
        except ValueError: pass
        else: raise AssertionError('Ondo cannot replace an existing bStocks ticker')
        # Captured open prices are not retained to contaminate a later incremental merge.
        opened={**op,'results':[{**ondo,'rows':rows+[[999,1000,998,999,1,base+70*engine.DAY,1]]}]}
        persist_payload(store,opened,allowed)
        assert len(json.loads((store/'candles/ONLY.json').read_text())['results'][0]['rows'])==70
        conflict={**op,'results':[{**ondo,'rows':rows+[rows[-1][:3]+[999]+rows[-1][4:]]}]}
        merged=persist_payload(store,conflict,allowed)
        assert len(merged['rows'])==71
        assert engine.feature_assessment(merged['rows'],'1d',cutoff)['features']['SMA20']['status']=='unavailable'
        larger={**allowed,'NEW':'ondo'}
        assert batch_tickers(store,larger,1)==['NEW']
    print('PASS: eligibility, historical indicator retention, original API collection timestamp, observed bars, source precedence, persistent candles and atomic watchlist. API/model calls: 0.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError,ValueError,KeyError,TypeError,subprocess.TimeoutExpired) as error:
        safe={'unexpected_bstock_catalogue_size','sync_time_budget_exceeded','no_saved_candles','ambiguous_primary_series','invalid_primary_series','incomplete_import_report','sync_busy_try_again','saved_raw_candles_unavailable'}
        message=str(error) if str(error) in safe else 'technical_scan_failed'
        print(json.dumps({'ok':False,'error':message,'previous_watchlist_preserved':True,'model_called':False})); sys.exit(1)
