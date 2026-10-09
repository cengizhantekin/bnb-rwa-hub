#!/usr/bin/python3
"""Bounded BSC primary-provider daily coverage test. No model/trades."""
import argparse
from collections import Counter
from decimal import localcontext
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


def universe(rows):
    groups = {p: set() for p in ('bstock', 'ondo', 'xstocks')}
    counts = Counter()
    for row in rows:
        p, ticker, count = row['provider'], row['ticker'], row['token_records']
        if p not in groups or not re.fullmatch(r'[A-Z0-9][A-Z0-9._-]{0,15}', ticker) or type(count) is not int or count < 1:
            raise ValueError('invalid_catalogue')
        groups[p].add(ticker); counts[p] += count
    bs, ondo, xs = (groups[p] for p in groups)
    return {
        'provider_token_records': dict(counts),
        'provider_unique_tickers': {p: len(t) for p, t in groups.items()},
        'bstock_primary_tickers': sorted(bs),
        'ondo_additional_tickers': sorted(ondo-bs),
        'xstocks_only_excluded_tickers': sorted(xs-bs-ondo),
        'bstock_primary_count': len(bs),
        'ondo_additional_count': len(ondo-bs),
        'xstocks_only_excluded_count': len(xs-bs-ondo),
        'proposed_discovery_unique_tickers': len(bs|ondo),
        'all_supported_unique_tickers': len(bs|ondo|xs),
        'coverage_is_catalogue_only': True,
        'note': 'Catalogue presence does not establish adequate candle history or usable indicators.'}


def selection(tickers, limit):
    if len(tickers) <= limit:
        return list(tickers)
    # Reproducible alphabet-spread sample, including previously tested TSLA when present.
    indexes = [i*(len(tickers)-1)//(limit-1) for i in range(limit)] if limit > 1 else [0]
    chosen = [tickers[i] for i in indexes]
    if 'TSLA' in tickers and 'TSLA' not in chosen:
        chosen[-1] = 'TSLA'
    return sorted(set(chosen))


def collect(args):
    p = subprocess.run(['/usr/bin/php', str(ROOT/'test-rwa-candles.php'), *args],
                       stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                       text=True, encoding='utf-8', timeout=260, check=False)
    d = json.loads(p.stdout)
    if len(p.stdout) > 2_000_000 or not isinstance(d, dict) or not isinstance(d.get('ok'), bool):
        raise ValueError('invalid_collector_output')
    return d


def eligible_tickers(coverage, provider):
    # Ondo is selected only when bStocks is absent, never when bStocks quality fails.
    return coverage['bstock_primary_tickers'] if provider == 'bstock' else coverage['ondo_additional_tickers']


def report_path(provider):
    if provider not in ('bstock', 'ondo'): raise ValueError('invalid_provider')
    return ROOT/(provider+'-coverage-report.json')


def checkpoint(report):
    target = report_path(report.get('provider', 'bstock'))
    fd, temporary = tempfile.mkstemp(prefix='.coverage-', dir=ROOT)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(report, f, indent=2); f.write('\n')
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)
    return str(target)


def brief(report):
    audit = report.get('candle_audit', {})
    q = report.get('quality', {})
    return {'symbol': report.get('symbol'), 'status': report.get('status'),
            'feature_assessment': report.get('feature_assessment'),
            'closed_candles': report.get('closed_candles', audit.get('windows', {}).get('all', {}).get('closed_rows')),
            'anomaly_windows': audit.get('windows'), 'interval_gaps': q.get('interval_gaps'),
            'sma': report.get('sma'), 'rsi14_wilder': report.get('rsi14_wilder'),
            'error': report.get('error'), 'validation_error': report.get('validation_error'),
            'latest_close_age_seconds': q.get('latest_close_age_seconds'),
            'indicators_are_not_independent_stock_prices': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--provider', choices=['bstock','ondo'], default='bstock')
    parser.add_argument('--limit', type=int, default=8)
    parser.add_argument('--all', action='store_true')
    parser.add_argument('--catalog-only', action='store_true')
    parser.add_argument('--self-test', action='store_true')
    parser.add_argument('--offline', action='store_true', help='Reanalyze saved raw candles without PHP, database or API requests.')
    args = parser.parse_args()
    if args.self_test:
        rows = [{'provider':p,'ticker':t,'token_records':n} for p,t,n in
                [('bstock','TSLA',1),('bstock','AMD',2),('ondo','TSLA',3),('ondo','AAPL',1),('xstocks','ONLY',1),('xstocks','AAPL',1)]]
        u = universe(rows)
        assert u['bstock_primary_count']==2 and u['ondo_additional_count']==1
        assert u['xstocks_only_excluded_count']==1 and u['proposed_discovery_unique_tickers']==3
        assert u['provider_token_records']['bstock']==3
        assert eligible_tickers(u,'ondo')==['AAPL']
        assert 'TSLA' not in eligible_tickers(u,'ondo')
        assert report_path('ondo').name=='ondo-coverage-report.json'
        assert report_path('bstock').name=='bstock-coverage-report.json'
        assert len(selection([f'T{i:02d}' for i in range(30)],8))==8
        assert 'TSLA' in selection(sorted([f'T{i:02d}' for i in range(30)]+['TSLA']),8)
        assert brief({'status':'invalid_data'})['status']=='invalid_data'
        print('PASS: provider precedence, unique ticker coverage, token-record distinction, deterministic sample and missing-data summaries. API calls: 0.'); return 0
    if not 1 <= args.limit <= 60:
        parser.error('--limit must be between 1 and 60')
    os.umask(0o077)
    spec = importlib.util.spec_from_file_location('rwa_technicals', ROOT/'test-rwa-technicals.py')
    technical = importlib.util.module_from_spec(spec); spec.loader.exec_module(technical)
    if args.offline:
        saved=json.loads(report_path(args.provider).read_text())
        if saved.get('provider','bstock')!=args.provider: raise ValueError('saved_provider_mismatch')
        entries=saved.get('tested_tickers', [])
        if not entries or any('raw_payload' not in entry for entry in entries):
            print(json.dumps({'ok':False,'error':'saved_raw_candles_unavailable','binance_http_requests':0,'model_called':False,'database_writes':0})); return 1
        for entry in entries:
            d=entry['raw_payload']
            with localcontext() as ctx:
                ctx.prec=50
                entry['reports']=[technical.analyze(item,d['cutoff_ms']) for item in d['results']]
            print(args.provider.upper()+': '+json.dumps({'ticker':entry['ticker'],'reports':[brief(a) for a in entry['reports']]}),flush=True)
        saved['historical_collection_http_requests']=saved.get('historical_collection_http_requests',saved.get('binance_http_requests',0))
        saved.update(provider=args.provider,action=args.provider+'_saved_candle_reanalysis',binance_http_requests=0,requests=[],model_called=False,database_writes=0,
                     scope='Offline historical reanalysis at original collection cutoff; not a current opportunity ranking.')
        print('REPORT: '+checkpoint(saved)); return 0
    catalogue = collect(['--catalog'])
    if not catalogue['ok']:
        print(json.dumps(catalogue)); return 1
    coverage = universe(catalogue['rows'])
    coverage['excluded_catalogue_groups'] = catalogue.get('excluded_groups', [])
    eligible=eligible_tickers(coverage,args.provider)
    selected = selection(eligible, 60 if args.all else args.limit)
    if args.all and len(eligible) > 60:
        raise ValueError('provider_universe_exceeds_bounded_test_limit')
    report = {'ok': True, 'provider':args.provider, 'action':args.provider+'_daily_coverage_test',
              'provider_selection_policy':'bStocks first; Ondo only where bStocks is absent; xStocks-only excluded.',
              'eligible_provider_tickers':len(eligible),
              'coverage': coverage, 'selected_tickers': selected,
              'selection_method': 'all' if args.all else 'alphabet_spread',
              'tested_tickers': [], 'binance_http_requests': 0, 'requests': [],
              'model_called': False, 'database_writes': 0,
              'scope': 'Read-only coverage diagnostic, not an opportunity ranking.'}
    print('UNIVERSE: '+json.dumps({k:v for k,v in coverage.items() if not isinstance(v,list)}), flush=True)
    print('SELECTED: '+json.dumps({'provider':args.provider,'eligible_tickers':len(eligible),'tickers':selected}),flush=True)
    if args.catalog_only:
        report['selected_tickers'] = []; print('REPORT: '+checkpoint(report)); return 0
    started = time.monotonic()
    stopped = None
    for ticker in selected:
        if time.monotonic()-started > 1200:
            stopped = 'total_test_budget_exceeded'; break
        if report['tested_tickers']:
            time.sleep(2)  # Between collector processes; each owns the shared lock only during its own requests.
        d = collect(['--provider='+args.provider, '--ticker='+ticker])
        report['binance_http_requests'] += d.get('binance_http_requests', 0)
        report['requests'].extend(d.get('requests', []))
        if not d['ok']:
            stopped = d.get('error', 'collector_unavailable'); report['failed_ticker']=ticker; break
        if not d.get('results') or any(item.get('provider')!=args.provider or item.get('ticker')!=ticker or item.get('bar')!='1d' for item in d['results']):
            stopped='unexpected_primary_series'; report['failed_ticker']=ticker; break
        with localcontext() as ctx:
            ctx.prec = 50
            analyses = [technical.analyze(item, d['cutoff_ms']) for item in d['results']]
        report['tested_tickers'].append({'ticker': ticker, 'reports': analyses, 'raw_payload': d})
        print(args.provider.upper()+': '+json.dumps({'ticker':ticker,'reports':[brief(a) for a in analyses]}), flush=True)
        checkpoint(report)
    report['ok'] = stopped is None
    report['stop_reason'] = stopped
    report['tested_count'] = len(report['tested_tickers'])
    report['statuses'] = dict(Counter(a['status'] for item in report['tested_tickers'] for a in item['reports']))
    report['feature_status_counts']={feature:dict(Counter(a.get('feature_assessment',{}).get('features',{}).get(feature,{}).get('status','unavailable')
        for entry in report['tested_tickers'] for a in entry['reports'])) for feature in ('SMA20','SMA50','SMA200','RSI14','support_resistance')}
    report['total_time_seconds'] = round(time.monotonic()-started,2)
    path = checkpoint(report)
    print('SUMMARY: '+json.dumps({k:v for k,v in report.items() if k not in ('coverage','tested_tickers','requests')}), flush=True)
    print('REPORT: '+path)
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, ValueError, KeyError, TypeError, subprocess.TimeoutExpired):
        print(json.dumps({'ok':False,'error':'universe_test_unavailable','model_called':False,'database_writes':0})); sys.exit(1)
