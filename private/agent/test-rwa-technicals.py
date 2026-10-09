#!/usr/bin/python3
"""Read-only daily/weekly technical coverage diagnostic, standard library only."""
import datetime as dt
from decimal import Decimal, InvalidOperation, localcontext
import json
from pathlib import Path
import subprocess
import sys

DAY = 86400000
TOLERANCE = Decimal('0.005')


def number(value, positive=False):
    if isinstance(value, bool):
        raise ValueError('invalid_number')
    try:
        result = Decimal(str(value))
    except InvalidOperation:
        raise ValueError('invalid_number') from None
    if not result.is_finite() or result < 0 or (positive and result == 0):
        raise ValueError('invalid_number')
    return result


def shown(value):
    return None if value is None else format(value.quantize(Decimal('0.000001')), 'f').rstrip('0').rstrip('.')


def sma(values, period):
    return sum(values[-period:], Decimal(0)) / period if len(values) >= period else None


def rsi(values, period=14):
    if len(values) <= period:
        return None
    changes = [values[i] - values[i-1] for i in range(1, len(values))]
    gain = sum((max(x, Decimal(0)) for x in changes[:period]), Decimal(0)) / period
    loss = sum((max(-x, Decimal(0)) for x in changes[:period]), Decimal(0)) / period
    for change in changes[period:]:
        gain = (gain*(period-1) + max(change, Decimal(0))) / period
        loss = (loss*(period-1) + max(-change, Decimal(0))) / period
    if gain == loss == 0:
        return Decimal(50)  # Explicit neutral convention for a flat series.
    if loss == 0:
        return Decimal(100)
    return Decimal(100) - Decimal(100)/(1+gain/loss)


def clean_rows(rows, bar, cutoff):
    step = DAY if bar == '1d' else 7*DAY
    parsed = {}
    excluded = 0
    for row in rows:
        if not isinstance(row, list) or len(row) != 7:
            raise ValueError('unexpected_candle_schema')
        o, h, l, c = [number(x, True) for x in row[:4]]
        volume = None if row[4] is None else number(row[4])
        timestamp = number(row[5], True)
        count = None if row[6] is None else number(row[6])
        if timestamp != timestamp.to_integral_value() or (count is not None and count != count.to_integral_value()):
            raise ValueError('invalid_timestamp_or_count')
        timestamp = int(timestamp)
        if timestamp < 1230768000000 or timestamp > cutoff:
            raise ValueError('invalid_timestamp')
        if h < max(o, c) or l > min(o, c) or h < l:
            raise ValueError('invalid_ohlc')
        candle = (timestamp, o, h, l, c, volume, None if count is None else int(count))
        if timestamp in parsed and parsed[timestamp] != candle:
            raise ValueError('conflicting_duplicate')
        parsed[timestamp] = candle
    output = []
    for timestamp in sorted(parsed):
        if timestamp + step > cutoff:
            excluded += 1
        else:
            output.append(parsed[timestamp])
    gaps = sum(output[i][0]-output[i-1][0] != step for i in range(1, len(output)))
    return output, {'raw_rows': len(rows), 'unique_rows': len(parsed),
                    'duplicates_removed': len(rows)-len(parsed),
                    'open_candles_excluded': excluded, 'interval_gaps': gaps,
                    'missing_volume_rows': sum(row[5] is None for row in output),
                    'missing_trade_count_rows': sum(row[6] is None for row in output),
                    'utc_boundary_aligned': all(row[0] % DAY == 0 for row in output),
                    'latest_close_age_seconds': (cutoff-output[-1][0]-step)//1000 if output else None}


def horizontal_zones(rows, close):
    # Two candles on each side confirm a pivot; future bars never enter a historical pivot.
    zones = []
    past = rows[:-2][-120:]
    for kind, column in [('support', 3), ('resistance', 2)]:
        pivots = []
        for i in range(2, len(past)-2):
            value = past[i][column]
            neighbours = [past[j][column] for j in (i-2, i-1, i+1, i+2)]
            if (kind == 'support' and all(value < x for x in neighbours)) or (
                    kind == 'resistance' and all(value > x for x in neighbours)):
                pivots.append((i, value))
        clusters = []
        for index, price in sorted(pivots, key=lambda item: item[1]):
            if clusters and (price-clusters[-1][0][1])/clusters[-1][0][1] <= TOLERANCE:
                clusters[-1].append((index, price))
            else:
                clusters.append([(index, price)])
        for cluster in clusters:
            touches = []; last = -10
            for index, price in sorted(cluster):
                if index-last >= 3:
                    touches.append(price); last = index
            if len(touches) < 2:
                continue
            center = sum(touches, Decimal(0))/len(touches)
            if (kind == 'support' and center <= close) or (kind == 'resistance' and center >= close):
                zones.append({'kind': kind, 'low': shown(min(touches)), 'high': shown(max(touches)),
                              'center': shown(center), 'confirmed_pivot_touches': len(touches),
                              'distance_pct': shown(abs(center/close-1)*100)})
    return sorted(zones, key=lambda zone: Decimal(zone['distance_pct']))[:4]


def candle_audit(raw_rows, bar, cutoff):
    """Measure all failures; no inferred prices, swaps or database changes."""
    step = DAY if bar == '1d' else 7*DAY
    by_time = {}; malformed = 0; conflicts = set()
    for row in raw_rows:
        try:
            if not isinstance(row, list) or len(row) != 7:
                raise ValueError('schema')
            timestamp = number(row[5], True)
            if timestamp != timestamp.to_integral_value() or not 1230768000000 <= timestamp <= cutoff:
                raise ValueError('timestamp')
            timestamp = int(timestamp)
        except (ValueError, TypeError):
            malformed += 1; continue
        if timestamp in by_time and by_time[timestamp] != row:
            conflicts.add(timestamp)
        by_time[timestamp] = row
    records = []
    open_rows = 0
    for timestamp, row in sorted(by_time.items()):
        if timestamp + step > cutoff:
            open_rows += 1; continue
        reasons = []
        try:
            prices = [number(value, True) for value in row[:4]]
        except ValueError:
            prices = None; reasons.append('invalid_price_number')
        try:
            close = number(row[3], True)
        except ValueError:
            close = None
        if prices:
            o, h, low, c = prices
            if h < low: reasons.append('high_below_low')
            if not low <= o <= h: reasons.append('open_outside_high_low')
            if not low <= c <= h: reasons.append('close_outside_high_low')
        if timestamp in conflicts: reasons.append('conflicting_duplicate')
        records.append(dict(timestamp=timestamp, prices=prices, close=close, reasons=reasons))
    # Retrospective LOCAL scale check, not a stock valuation or confirmed bad price.
    # Neighbouring candles are used only for this diagnostic; never a live signal.
    for i, rec in enumerate(records):
        neighbours = [r['close'] for r in records[max(0,i-3):i]+records[i+1:i+4]
                      if r['close'] is not None]
        if rec['prices'] and len(neighbours) >= 3:
            neighbours.sort(); mid=len(neighbours)//2
            median=neighbours[mid] if len(neighbours)%2 else (neighbours[mid-1]+neighbours[mid])/2
            if any(max(value/median, median/value) >= 10 for value in rec['prices']):
                rec['reasons'].append('local_scale_outlier_candidate')
    def window_summary(window):
        n=len(window); bad=sum(bool(r['reasons']) for r in window)
        return dict(closed_rows=n, flagged_rows=bad,
                    flagged_pct=shown(Decimal(bad)*100/n) if n else None,
                    ohlc_inconsistent_rows=sum(any(k in r['reasons'] for k in
                        ('high_below_low','open_outside_high_low','close_outside_high_low')) for r in window),
                    local_scale_outlier_candidates=sum('local_scale_outlier_candidate' in r['reasons'] for r in window),
                    unusable_close_rows=sum(r['close'] is None or r['timestamp'] in conflicts for r in window))
    windows={'all':window_summary(records)}
    windows.update({str(n):window_summary(records[-n:]) for n in (20,50,200)})
    samples=[dict(open_utc=dt.datetime.fromtimestamp(r['timestamp']/1000,dt.timezone.utc).isoformat(),
                  reasons=r['reasons'], ohlc=[str(x) for x in r['prices']] if r['prices'] else None)
             for r in records if r['reasons']]
    def experimental(selected, label):
        closes=[r['close'] for r in selected if r['close'] is not None and r['timestamp'] not in conflicts]
        n=len(closes)
        gaps=sum(selected[i]['timestamp']-selected[i-1]['timestamp'] != step for i in range(1,len(selected)))
        return dict(status='experimental_unvalidated', series=label, closed_closes=n,
                    sma={str(p):shown(sma(closes,p)) for p in (20,50,200)},
                    rsi14_wilder=shown(rsi(closes)), interval_gaps=gaps,
                    latest_close_utc=dt.datetime.fromtimestamp(selected[-1]['timestamp']/1000,dt.timezone.utc).isoformat() if selected else None,
                    usable_for_opportunity_signal=False,
                    reason='Session calendar, source scale and close-price accuracy remain unverified; filtering changes period coverage and RSI changes.')
    close_rows=[r for r in records if r['close'] is not None and r['timestamp'] not in conflicts]
    filtered=[r for r in close_rows if not r['reasons']]
    open_check = {'flagged_open_rows': 0, 'previous_close_available': 0,
                  'already_equal_previous_close': 0, 'nearly_equal_previous_close': 0,
                  'replacing_open_resolves_ohlc': 0, 'replacement_still_invalid': 0,
                  'missing_or_conflicting_previous_close': 0,
                  'nonconsecutive_previous_bar': 0, 'consecutive_replacement_resolves': 0,
                  'consecutive_replacement_still_invalid': 0, 'samples': [],
                  'values_modified': False,
                  'note': 'Counterfactual check only. Previous observed candle may precede a date gap; such cases are counted separately. Equality does not prove the intended source definition.'}
    for i, rec in enumerate(records):
        if 'open_outside_high_low' not in rec['reasons']:
            continue
        open_check['flagged_open_rows'] += 1
        previous = records[i-1] if i else None
        if previous is None or previous['close'] is None or previous['timestamp'] in conflicts:
            open_check['missing_or_conflicting_previous_close'] += 1
            continue
        open_check['previous_close_available'] += 1
        o, h, low, c = rec['prices']; prev = previous['close']
        consecutive = rec['timestamp'] - previous['timestamp'] == step
        equal = o == prev
        near = abs(o-prev)/prev <= Decimal('0.0000000001')
        resolves = low <= prev <= h and low <= c <= h and h >= low
        open_check['already_equal_previous_close'] += int(equal)
        open_check['nearly_equal_previous_close'] += int(near)
        open_check['nonconsecutive_previous_bar'] += int(not consecutive)
        open_check['replacing_open_resolves_ohlc'] += int(resolves)
        open_check['replacement_still_invalid'] += int(not resolves)
        if consecutive:
            open_check['consecutive_replacement_resolves'] += int(resolves)
            open_check['consecutive_replacement_still_invalid'] += int(not resolves)
        if len(open_check['samples']) < 12:
            open_check['samples'].append({
                'open_utc': dt.datetime.fromtimestamp(rec['timestamp']/1000,dt.timezone.utc).isoformat(),
                'previous_open_utc': dt.datetime.fromtimestamp(previous['timestamp']/1000,dt.timezone.utc).isoformat(),
                'consecutive': consecutive, 'original_open': str(o), 'previous_close': str(prev),
                'high': str(h), 'low': str(low), 'close': str(c),
                'open_equals_previous_close': equal, 'replacement_resolves_ohlc': resolves})
    return dict(windows=windows, malformed_or_timestamp_rows=malformed,
                conflicting_timestamp_count=len(conflicts), open_rows_excluded=open_rows,
                flagged_samples=samples[:12], flagged_samples_truncated=len(samples)>12,
                local_outlier_rule='Any OHLC value differs by at least factor 10 from median of up to 6 neighbouring closes; at least 3 required. Candidate only, not confirmed error.',
                close_only_experiment=experimental(close_rows,'raw_positive_closes_including_ohlc_inconsistencies'),
                skip_flagged_experiment=experimental(filtered,'skip_flagged_candles'),
                skipped_closed_rows=len(records)-len(filtered), automatic_swaps=False,
                original_values_modified=False, database_writes=0, open_previous_close_check=open_check)


def feature_assessment(raw_rows, bar, cutoff, *, allow_historical=False):
    """Field-specific, conservative token-series diagnostics; no deletion or repair."""
    step = DAY if bar == '1d' else 7*DAY
    parsed={}; conflicts=set(); malformed=0
    for row in raw_rows:
        try:
            if not isinstance(row,list) or len(row)!=7: raise ValueError('schema')
            stamp=number(row[5],True)
            if stamp!=stamp.to_integral_value() or not 1230768000000<=stamp<=cutoff: raise ValueError('timestamp')
            stamp=int(stamp)
        except ValueError:
            malformed+=1; continue
        if stamp in parsed and parsed[stamp]!=row: conflicts.add(stamp)
        parsed[stamp]=row
    closed=[(stamp,parsed[stamp]) for stamp in sorted(parsed) if stamp+step<=cutoff]
    close_tail=[]; range_tail=[]; close_reset=None; range_reset=None; previous=None; previous_close=None
    open_warnings=0; close_candidates=0; range_candidates=0; date_gaps=0
    for stamp,row in closed:
        if previous is not None and stamp-previous!=step:
            # Observed-bar policy: retain real candles; never invent a missing candle.
            date_gaps+=1
        close=None;high=None;low=None;close_reason=None;range_reason=None
        try: close=number(row[3],True)
        except ValueError: close_reason='Invalid or missing closing price.'
        try: high=number(row[1],True);low=number(row[2],True)
        except ValueError: range_reason='Invalid or missing high/low price.'
        if stamp in conflicts or stamp%DAY:
            close_reason=range_reason='Conflicting duplicate or unsupported candle timestamp.'
        if close is not None and high is not None and low is not None:
            if high<low:
                range_reason='Reported high is below reported low.'
            if not low<=close<=high:
                close_reason=range_reason='Closing price is outside the reported high/low range; the erroneous field is unknown.'
            if max(high/close,close/high,low/close,close/low)>=10:
                range_candidates+=1;range_reason='Extreme high/low relative to close; source check required.'
            try:
                o=number(row[0],True)
                if not low<=o<=high:open_warnings+=1
            except ValueError:open_warnings+=1
        if close is not None and previous_close is not None and max(close/previous_close,previous_close/close)>=10:
            close_candidates+=1;close_reason='Closing price changes by factor 10 or more; scale or source check required.'
        if close_reason or close is None:
            close_tail=[];close_reset=close_reason or 'Closing price unavailable.'
        else:close_tail.append((stamp,close))
        if close_reason or range_reason or high is None or low is None:
            range_tail=[];range_reset=range_reason or close_reason or 'High/low unavailable.'
        else:
            # Open is deliberately unused by these pivot calculations; no raw field is rewritten.
            range_tail.append((stamp,None,high,low,close,None,None))
        previous=stamp;previous_close=close
    age=(cutoff-closed[-1][0]-step)//1000 if closed else None
    fresh=age is not None and age<=step//1000
    closing=[value for _,value in close_tail];n=len(closing)
    def result(value, needed, count, reset):
        reasons=[]
        if malformed:reasons.append('Some row timestamps or schemas could not be validated.')
        if not fresh and not allow_historical:reasons.append('Latest closed candle is missing or stale.')
        if count<needed:reasons.append(f'{needed} bars required; {count} usable observed bars available.')
        if count<needed and reset:reasons.append(reset)
        return {'status':'unavailable' if reasons else 'available_for_token_series',
                'value':None if reasons else value,'reasons':reasons,
                'usable_for_stock_opportunity':False}
    features={}
    for period in ([20,50,200] if bar=='1d' else [20,50]):
        features['SMA'+str(period)]=result(shown(sma(closing,period)),period,n,close_reset)
    features['RSI14']=result(shown(rsi(closing)),15,n,close_reset)
    if features['RSI14']['status']=='available_for_token_series' and n<100:
        features['RSI14']['status']='available_with_warning'
        features['RSI14']['reasons']=['Limited Wilder RSI initialization history: fewer than 100 observed closes.']
    zones=horizontal_zones(range_tail,range_tail[-1][4]) if len(range_tail)>=30 else []
    features['support_resistance']=result(zones,30,len(range_tail),range_reset)
    if features['support_resistance']['status']=='available_for_token_series' and not zones:
        features['support_resistance']['status']='not_detected'
        features['support_resistance']['reasons']=['No qualifying repeated pivot levels detected in the usable segment.']
    return {'features':features,'usable_close_bars':n,'usable_range_bars':len(range_tail),
            'last_close_segment_reset':close_reset,'last_range_segment_reset':range_reset,
            'open_field_warning_rows':open_warnings,'close_jump_candidates':close_candidates,
            'range_extreme_candidates':range_candidates,'latest_closed_age_seconds':age,
            'interpretation':'Diagnostic token-price indicators only. Source scale, historical share ratios and candle timestamp semantics remain unverified.',
            'calendar_policy':'Use observed closed candles in timestamp order. Missing dates do not reset calculations; no synthetic candles or prices are inserted. Periods count observed bars, not calendar days.',
            'date_gap_count':date_gaps,
            'methodology_visibility':'Documentation only; do not display date-gap counts or calculation-policy notes in the user report.',
            'close_history_first_utc':dt.datetime.fromtimestamp(close_tail[0][0]/1000,dt.timezone.utc).isoformat() if close_tail else None,
            'close_history_last_utc':dt.datetime.fromtimestamp(close_tail[-1][0]/1000,dt.timezone.utc).isoformat() if close_tail else None,
            'suspicious_price_policy':'Factor-10 jumps are candidates, not confirmed errors; restart the segment without altering or replacing prices.',
            'open_policy':'Open-range mismatch is a warning, not a blanket rejection of close or high/low fields.',
            'raw_values_modified':False,'database_writes':0}


def analyze(item, cutoff):
    out = {key: item.get(key) for key in ('ticker', 'provider', 'symbol', 'bar', 'pages', 'error')}
    out['price_basis'] = 'onchain_token_candles_not_independent_stock_history'
    out['historical_share_ratio_adjustment'] = 'unavailable'
    out['pagination_warning'] = item.get('pagination_warning')
    out['page_info'] = item.get('page_info', [])
    if item.get('error'):
        out['status'] = 'unavailable'; return out
    out['feature_assessment'] = feature_assessment(item.get('rows', []), item['bar'], cutoff)
    out['candle_audit'] = candle_audit(item.get('rows', []), item['bar'], cutoff)
    try:
        rows, quality = clean_rows(item['rows'], item['bar'], cutoff)
    except (ValueError, KeyError) as error:
        out.update(status='invalid_data', validation_error=str(error))
        for row in item.get('rows', []):
            try:
                clean_rows([row], item['bar'], cutoff)
            except ValueError as row_error:
                fields=('open','high','low','close','volume','timestamp_ms','trade_count')
                out['invalid_row_sample']={key:value for key,value in zip(fields,row)} if isinstance(row,list) else str(row)[:128]
                out['invalid_row_reason']=str(row_error)
                break
        return out
    out['quality'] = quality
    n = len(rows); out['closed_candles'] = n
    if not n:
        out['status'] = 'no_closed_candles'; return out
    out['first_open_utc'] = dt.datetime.fromtimestamp(rows[0][0]/1000, dt.timezone.utc).isoformat()
    out['last_open_utc'] = dt.datetime.fromtimestamp(rows[-1][0]/1000, dt.timezone.utc).isoformat()
    # Missing dates do not block observed-bar calculations. Timestamp alignment still matters.
    if not quality['utc_boundary_aligned']:
        out.update(status='calendar_or_timestamp_validation_required', indicators=None)
        return out
    closes = [row[4] for row in rows]
    periods = [20, 50, 200] if item['bar'] == '1d' else [20, 50]
    out['sma'] = {str(period): shown(sma(closes, period)) for period in periods}
    out['missing_history_for'] = ['SMA'+str(p) for p in periods if n < p]
    if n < 15:
        out['missing_history_for'].append('RSI14')
    out['rsi14_wilder'] = shown(rsi(closes))
    out['rsi_warmup'] = 'limited' if n < 100 else 'at_least_100_closed_bars'
    out['flat_close_series'] = len(set(closes[-min(20, n):])) == 1
    out['last_close'] = shown(closes[-1])
    step = DAY if item['bar'] == '1d' else 7*DAY
    out['status'] = 'stale_candles' if quality['latest_close_age_seconds'] > step//1000 else (
        'insufficient_history' if out['missing_history_for'] else 'indicators_available')
    out['horizontal_zones'] = horizontal_zones(rows, closes[-1]) if n >= 30 else []
    out['pattern_test'] = 'not_detected'
    if n >= 22:
        prior = rows[-22:-2]
        resistance = max(row[2] for row in prior)
        support = min(row[3] for row in prior)
        out['prior_20_bar_range'] = {'support': shown(support), 'resistance': shown(resistance)}
        threshold = Decimal('0.003')
        if closes[-2] > resistance*(1+threshold) and (
                resistance*(1-TOLERANCE) <= rows[-1][3] <= resistance*(1+TOLERANCE)
                and closes[-1] > resistance):
            out['pattern_test'] = 'possible_breakout_retest'
        elif closes[-1] > resistance*(1+threshold) and closes[-2] <= resistance*(1+threshold):
            out['pattern_test'] = 'possible_breakout'
        elif closes[-1] < support*(1-threshold):
            out['pattern_test'] = 'below_prior_support'
    out['actionable_stock_opportunity'] = False
    out['interpretation'] = 'Coverage test only; historical normalization, volume scope and issuer/session coverage need validation.'
    return out


def self_test():
    D = Decimal
    assert sma([D(i) for i in range(1, 21)], 20) == D('10.5')
    assert sma([D(1)]*19, 20) is None
    assert rsi([D(i) for i in range(1, 30)]) == 100
    assert rsi([D(i) for i in range(30, 0, -1)]) == 0
    assert rsi([D(10)]*30) == 50
    assert rsi([D(10)]*14) is None
    # Published Wilder seed example independently hand-computed from first 14 changes.
    prices = list(map(D, ['44.34','44.09','44.15','43.61','44.33','44.83','45.10','45.42',
                          '45.84','46.08','45.89','46.03','45.61','46.28','46.28']))
    assert abs(rsi(prices)-D('70.4641350211')) < D('0.000001')
    base = 1704067200000
    rows = [[str(100+i),str(102+i),str(99+i),str(101+i),'10',base+i*DAY,2] for i in range(220)]
    item = dict(ticker='TEST',provider='ondo',symbol='TESTon',bar='1d',pages=3,error=None,rows=rows)
    cutoff=base+220*DAY+1000
    report=analyze(item,cutoff)
    assert report['status']=='indicators_available' and report['sma']['200']=='220.5'
    cleaned, q=clean_rows(list(reversed(rows))+[rows[0]],'1d',cutoff)
    assert len(cleaned)==220 and q['duplicates_removed']==1
    _, q=clean_rows(rows,'1d',base+219*DAY+1)
    assert q['open_candles_excluded']==1
    bad=dict(item,rows=rows[:100]+rows[101:])
    gap_report=analyze(bad,cutoff)
    expected_closes=[D(row[3]) for row in bad['rows']]
    assert gap_report['sma']['200']==shown(sma(expected_closes,200))
    assert gap_report['rsi14_wilder']==shown(rsi(expected_closes))
    assert analyze(dict(item,rows=rows[:10]),cutoff)['rsi14_wilder'] is None
    conflict=rows+[list(rows[0])]; conflict[-1][3]='100'
    assert analyze(dict(item,rows=conflict),cutoff)['validation_error']=='conflicting_duplicate'
    weekly=dict(item,bar='1w',rows=[[100,102,99,101,10,base+i*7*DAY,1] for i in range(60)])
    result=analyze(weekly,base+60*7*DAY+1000)
    assert result['sma']['50']=='101' and result['flat_close_series']
    pivots=[[100,107 if i%8==0 else 105,93 if i%8==4 else 95,100,10,base+i*DAY,1] for i in range(60)]
    zones=analyze(dict(item,rows=pivots),base+60*DAY+1000)['horizontal_zones']
    assert {zone['kind'] for zone in zones}=={'support','resistance'}
    assert all(zone['confirmed_pivot_touches']>=2 for zone in zones)
    invalid=[list(row) for row in rows]; invalid[0][1]='90'
    assert analyze(dict(item,rows=invalid),cutoff)['validation_error']=='invalid_ohlc'
    optional=[list(row) for row in rows]; optional[-1][4]=None; optional[-1][6]=None
    optional_report=analyze(dict(item,rows=optional),cutoff)
    assert optional_report['sma']['200']=='220.5'
    assert optional_report['quality']['missing_trade_count_rows']==1
    null_price=[list(row) for row in rows]; null_price[-1][3]=None
    null_report=analyze(dict(item,rows=null_price),cutoff)
    assert null_report['status']=='invalid_data' and null_report['invalid_row_sample']['close'] is None
    # Horizontal resistance fixed before the last two candles, followed by breakout + retest.
    retest=[[100,105,95,100,10,base+i*DAY,1] for i in range(22)]
    retest[-2]=[100,110,99,108,10,base+20*DAY,1]
    retest[-1]=[108,110,105,107,10,base+21*DAY,1]
    assert analyze(dict(item,rows=retest),base+22*DAY+1000)['pattern_test']=='possible_breakout_retest'
    retest[-1][2]=102
    assert analyze(dict(item,rows=retest),base+22*DAY+1000)['pattern_test']!='possible_breakout_retest'
    audit=candle_audit(invalid,'1d',cutoff)
    assert audit['windows']['all']['flagged_rows']==1
    assert audit['windows']['20']['flagged_rows']==0
    assert audit['close_only_experiment']['sma']['200']=='220.5'
    assert audit['automatic_swaps'] is False
    spike=[list(row) for row in rows]; spike[-5][:4]=[5000,5100,4900,5050]
    audit=candle_audit(spike,'1d',cutoff)
    assert audit['windows']['all']['local_scale_outlier_candidates']==1
    assert audit['skip_flagged_experiment']['interval_gaps']==1
    assert audit['skip_flagged_experiment']['usable_for_opportunity_signal'] is False
    # Carried previous close already equals bad open: replacement cannot repair it.
    open_rows=[[100,110,90,108,10,base,1],[108,105,99,102,10,base+DAY,1]]
    check=candle_audit(open_rows,'1d',base+2*DAY+1000)['open_previous_close_check']
    assert check['already_equal_previous_close']==1 and check['replacement_still_invalid']==1
    # Different open can be made range-consistent, but this does not establish actual first trade.
    open_rows[0][3]=103
    check=candle_audit(open_rows,'1d',base+2*DAY+1000)['open_previous_close_check']
    assert check['replacing_open_resolves_ohlc']==1 and check['already_equal_previous_close']==0
    assert open_rows[1][0]==108
    open_rows[1][5]=base+2*DAY
    check=candle_audit(open_rows,'1d',base+3*DAY+1000)['open_previous_close_check']
    assert check['nonconsecutive_previous_bar']==1 and check['consecutive_replacement_resolves']==0
    feature_rows=[[100,105,99,102,10,base+i*DAY,1] for i in range(60)]
    feature_rows[-5][0]=108
    f=feature_assessment(feature_rows,'1d',base+60*DAY+1000)
    assert f['features']['SMA50']['status']=='available_for_token_series'
    assert f['open_field_warning_rows']==1 and f['features']['SMA200']['status']=='unavailable'
    assert f['features']['RSI14']['status']=='available_with_warning'
    f=feature_assessment(feature_rows[:35]+feature_rows[36:],'1d',base+60*DAY+1000)
    assert f['usable_close_bars']==59 and f['features']['SMA50']['value']=='102'
    assert f['date_gap_count']==1 and f['features']['support_resistance']['status']!='unavailable'
    assert f['features']['SMA20']['status']=='available_for_token_series'
    feature_rows[-5][1]=2000
    f=feature_assessment(feature_rows,'1d',base+60*DAY+1000)
    assert f['features']['SMA50']['status']=='available_for_token_series' and f['usable_range_bars']==4
    feature_rows[-1][3]=None
    f=feature_assessment(feature_rows,'1d',base+60*DAY+1000)
    assert f['usable_close_bars']==0 and f['features']['SMA20']['value'] is None
    f=feature_assessment(rows,'1d',base+223*DAY)
    assert f['features']['SMA50']['value'] is None
    print('PASS: Full-series and recent-window anomaly counts, close-only and skip experiments; SMA, Wilder RSI, insufficient history, flat series, open candles, ordering, duplicates, gaps, weekly scope and breakout/retest checks. API calls: 0.')


def main():
    if sys.argv[1:] == ['--self-test']:
        self_test(); return 0
    compact='--summary' in sys.argv[1:]
    args=[arg for arg in sys.argv[1:] if arg!='--summary']
    if any(not (arg=='--weekly' or arg.startswith('--ticker=')) for arg in args):
        print('Usage: python3 test-rwa-technicals.py [--ticker=TSLA] [--weekly] [--summary]'); return 1
    try:
        result=subprocess.run(['/usr/bin/php',str(Path(__file__).with_name('test-rwa-candles.php')),*args],
                              stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
                              timeout=260,text=True,encoding='utf-8',check=False)
        payload=json.loads(result.stdout)
        if result.returncode or not payload.get('ok'):
            print(json.dumps(payload,indent=2)); return 1
        with localcontext() as ctx:
            ctx.prec=50
            reports=[analyze(item,payload['cutoff_ms']) for item in payload['results']]
        weights=[number(r['weight']) for r in payload['requests'] if r.get('weight') is not None]
        output={'ok':True,'action':'technical_coverage_test','ticker':payload['ticker'],
            'binance_http_requests':payload['binance_http_requests'],
            'observed_weight_sum':str(sum(weights,Decimal(0))) if weights else None,
            'weight_headers_complete':len(weights)==len(payload['requests']),
            'total_time_ms':payload['total_time_ms'],'lock_wait_ms':payload.get('lock_wait_ms'),'model_called':False,'database_writes':0,
            'reports':reports}
        if compact:
            print('SUMMARY: '+json.dumps({key:value for key,value in output.items() if key!='reports'}))
            for report in reports:
                keys=('symbol','bar','status','closed_candles','sma','rsi14_wilder',
                      'rsi_warmup','pattern_test','validation_error','error','quality',
                      'pagination_warning','page_info','invalid_row_sample','invalid_row_reason','feature_assessment','candle_audit')
                print('TOKEN: '+json.dumps({key:report[key] for key in keys if key in report}))
        else:
            print(json.dumps(output,indent=2))
        return 0
    except (OSError,subprocess.TimeoutExpired,ValueError,InvalidOperation):
        print(json.dumps({'ok':False,'error':'technical_test_unavailable'})); return 1


if __name__=='__main__':
    sys.exit(main())
