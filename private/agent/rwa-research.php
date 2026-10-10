<?php
/** Bounded, read-only DB research projection for Hubble. CLI only; no free-form SQL. */
declare(strict_types=1);
ini_set('display_errors', '0');

function emit(array $result): never {
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit;
}
function numberOrNull(mixed $value): ?float {
    return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
}
function intOrNull(mixed $value): ?int {
    return is_numeric($value) ? (int)$value : null;
}
function providerOrder(string $p): int {
    return ['bstock'=>0, 'ondo'=>1, 'xstocks'=>2][$p] ?? 9;
}
function iso(mixed $raw): ?string {
    if (!is_string($raw) || trim($raw) === '') return null;
    try { return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
    catch (Throwable) { return null; }
}
function validatePlan(mixed $v): ?array {
    if (!is_array($v) || array_is_list($v)) return null;
    if (array_diff(array_keys($v), ['mode','sort','provider','asset_type','tickers','search','limit','technical','technical_status']) !== []) return null;
    $mode = $v['mode'] ?? null;
    $sort = $v['sort'] ?? 'market_cap_desc';
    $provider = $v['provider'] ?? 'all';
    $assetType = $v['asset_type'] ?? 'all';
    $tickers = $v['tickers'] ?? [];
    $search = $v['search'] ?? '';
    $limit = $v['limit'] ?? 8;
    $technical = $v['technical'] ?? false;
    $technicalStatus = $v['technical_status'] ?? 'all';
    if (!in_array($mode, ['assets','health','holders'], true)
        || !in_array($sort, ['market_cap_desc','market_cap_asc','volume_desc','volume_asc','pnl_desc','pnl_asc','ticker_asc','rsi_asc','holders_desc','top10_concentration_desc','kyc_holders_desc'], true)
        || !in_array($provider, ['all','bstock','ondo','xstocks'], true)
        || !in_array($assetType, ['all','stock','etf'], true)
        || !is_array($tickers) || !array_is_list($tickers) || count($tickers)>8
        || !is_string($search) || strlen($search)>60
        || !is_int($limit) || $limit<1 || $limit>15 || !is_bool($technical)
        || !in_array($technicalStatus, ['all','candidate'], true) || ($technicalStatus === 'candidate' && !$technical)
        || ($mode === 'holders' && (!in_array($sort, ['holders_desc','top10_concentration_desc','kyc_holders_desc'], true) || $technical || $technicalStatus !== 'all'))) return null;
    $tickers = array_map(static fn($x)=>is_string($x)?strtoupper($x):'', $tickers);
    if (count(array_unique($tickers))!==count($tickers))return null;
    foreach ($tickers as $t)if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/', $t))return null;
    return compact('mode','sort','provider','assetType','tickers','search','limit','technical','technicalStatus');
}
/** Non-exhaustive examples of token-level fetch lag, not a statement about stock source age. */
function delayedFetchExamples(PDO $db, string $field, int $limit): array {
    if (!in_array($field, ['general_api_updated_at','rwa_api_updated_at'], true)) throw new InvalidArgumentException('invalid_fetch_field');
    $limit = max(1, min(15, $limit));
    $sql = "SELECT t.underlying_ticker, t.provider, t.symbol, m.{$field} AS last_fetch_utc
            FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id=t.id
            WHERE t.chain_id='56' AND t.is_active=1 AND t.provider IN ('bstock','ondo','xstocks')
              AND (m.{$field} IS NULL OR m.{$field} < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
            ORDER BY (m.{$field} IS NOT NULL), m.{$field} ASC, t.underlying_ticker, t.provider, t.symbol
            LIMIT {$limit}";
    $out=[];
    foreach ($db->query($sql) as $r) {
        $out[]=['ticker'=>(string)$r['underlying_ticker'],'provider'=>(string)$r['provider'],
                'token_symbol'=>(string)$r['symbol'],'last_fetch_utc'=>iso($r['last_fetch_utc']??null)];
    }
    return $out;
}
/** Source timestamp age is separate from collection success. 24h is a screening band,
 * not a claim that a stock should trade continuously or that a price is wrong. */
function sourcePriceExamples(PDO $db, string $field, int $limit): array {
    if (!in_array($field, ['price_time','rwa_price_updated_at'], true)) throw new InvalidArgumentException('invalid_price_source_field');
    $limit = max(1, min(15, $limit));
    $sql = "SELECT t.underlying_ticker, t.provider, t.symbol, m.{$field} AS source_utc,
            CASE WHEN m.{$field} IS NULL THEN 'unknown_timestamp'
                 WHEN m.{$field} < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) THEN 'older_than_24h'
                 ELSE 'future_timestamp' END AS age_status
            FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id=t.id
            WHERE t.chain_id='56' AND t.is_active=1 AND t.provider IN ('bstock','ondo','xstocks')
              AND (m.{$field} IS NULL OR m.{$field} < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
                   OR m.{$field} > DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))
            ORDER BY CASE WHEN m.{$field} < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) THEN 0
                          WHEN m.{$field} IS NULL THEN 1 ELSE 2 END,
                     m.{$field}, t.underlying_ticker, t.provider, t.symbol
            LIMIT {$limit}";
    $out=[];
    foreach($db->query($sql) as $r) {
        $out[]=['ticker'=>(string)$r['underlying_ticker'],'provider'=>(string)$r['provider'],
            'token_symbol'=>(string)$r['symbol'],'source_utc'=>iso($r['source_utc']??null),
            'age_status'=>(string)$r['age_status']];
    }
    return $out;
}
/** Underlying market cap / volume freshness: separate from per-token price records. */
function underlyingSourceExamples(PDO $db, int $limit): array {
    $limit=max(1,min(15,$limit));
    $out=[];
    $sql="SELECT underlying_ticker,selected_provider,source_updated_at,
          CASE WHEN source_updated_at IS NULL THEN 'unknown_timestamp'
               WHEN source_updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) THEN 'older_than_24h'
               ELSE 'future_timestamp' END AS age_status
          FROM rwa_underlying_rank_latest
          WHERE (market_cap IS NOT NULL OR volume_24h IS NOT NULL)
            AND (source_updated_at IS NULL OR source_updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
                 OR source_updated_at > DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))
          ORDER BY CASE WHEN source_updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) THEN 0
                        WHEN source_updated_at IS NULL THEN 1 ELSE 2 END,
                   source_updated_at,underlying_ticker LIMIT {$limit}";
    foreach($db->query($sql) as $r) {
        $out[]=['ticker'=>(string)$r['underlying_ticker'],'selected_provider'=>(string)$r['selected_provider'],
            'source_utc'=>iso($r['source_updated_at']??null),'age_status'=>(string)$r['age_status']];
    }
    return $out;
}
function getHealth(PDO $db, int $sampleLimit): array {
    $tokens = $db->query("SELECT COUNT(*) AS token_records, COUNT(DISTINCT underlying_ticker) AS underlying_assets, MAX(last_seen_at) AS catalogue_seen FROM rwa_tokens WHERE chain_id='56' AND is_active=1 AND provider IN ('bstock','ondo','xstocks')")->fetch(PDO::FETCH_ASSOC);
    $sources = $db->query("SELECT COUNT(*) AS total,
      COALESCE(SUM(m.general_api_updated_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)),0) AS general_fetch_10m,
      COALESCE(SUM(m.rwa_api_updated_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)),0) AS rwa_fetch_10m,
      MAX(m.general_api_updated_at) AS general_latest, MAX(m.rwa_api_updated_at) AS rwa_latest,
      MAX(m.price_time) AS general_source_latest, MAX(m.rwa_price_updated_at) AS rwa_source_latest,
      COALESCE(SUM(m.price_time < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)),0) AS general_source_older_24h,
      COALESCE(SUM(m.price_time IS NULL),0) AS general_source_unknown,
      COALESCE(SUM(m.price_time > DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)),0) AS general_source_future,
      COALESCE(SUM(m.rwa_price_updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)),0) AS rwa_source_older_24h,
      COALESCE(SUM(m.rwa_price_updated_at IS NULL),0) AS rwa_source_unknown,
      COALESCE(SUM(m.rwa_price_updated_at > DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)),0) AS rwa_source_future
      FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id=t.id
      WHERE t.is_active=1 AND t.chain_id='56' AND t.provider IN ('bstock','ondo','xstocks')")->fetch(PDO::FETCH_ASSOC);
    $underlying = $db->query("SELECT COUNT(*) AS total, MAX(source_updated_at) AS source_latest,
      MAX(generated_at) AS snapshot_generated_latest,
      COALESCE(SUM(source_updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)),0) AS source_older_24h,
      COALESCE(SUM(source_updated_at IS NULL),0) AS source_unknown,
      COALESCE(SUM(source_updated_at > DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)),0) AS source_future
      FROM rwa_underlying_rank_latest WHERE market_cap IS NOT NULL OR volume_24h IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
    $tech = $db->query('SELECT generated_at_utc,scope_complete,scanned_count,eligible_count,candidate_count,data_unavailable_count,imported_at_utc FROM rwa_technical_snapshot_meta WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $checked=intOrNull($sources['total']??null);
    $generalFresh=intOrNull($sources['general_fetch_10m']??null);
    $rwaFresh=intOrNull($sources['rwa_fetch_10m']??null);
    $withinScreen = static function(?int $total, ?int $old, ?int $unknown, ?int $future): ?int {
        return $total===null || $old===null || $unknown===null || $future===null
            ? null : max(0, $total-$old-$unknown-$future);
    };
    return ['ok'=>true,'source'=>'saved_database','mode'=>'health','as_of_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
      'data'=>['active_token_records'=>intOrNull($tokens['token_records']??null),'active_underlyings'=>intOrNull($tokens['underlying_assets']??null),'catalogue_seen_utc'=>iso($tokens['catalogue_seen']??null),
      'general_fetch_fresh_10m'=>$generalFresh,'rwa_fetch_fresh_10m'=>$rwaFresh,'checked_token_records'=>$checked,
      'general_fetch_delayed_10m'=>($checked!==null&&$generalFresh!==null)?max(0,$checked-$generalFresh):null,
      'rwa_fetch_delayed_10m'=>($checked!==null&&$rwaFresh!==null)?max(0,$checked-$rwaFresh):null,
      'general_fetch_latest_utc'=>iso($sources['general_latest']??null),'rwa_fetch_latest_utc'=>iso($sources['rwa_latest']??null),
      'general_price_source_latest_utc'=>iso($sources['general_source_latest']??null),'rwa_price_source_latest_utc'=>iso($sources['rwa_source_latest']??null),
      'price_source_age_screen_hours'=>24,'source_timestamp_future_tolerance_minutes'=>5,
      'general_price_source_within_24h'=>$withinScreen($checked,intOrNull($sources['general_source_older_24h']??null),intOrNull($sources['general_source_unknown']??null),intOrNull($sources['general_source_future']??null)),
      'rwa_price_source_within_24h'=>$withinScreen($checked,intOrNull($sources['rwa_source_older_24h']??null),intOrNull($sources['rwa_source_unknown']??null),intOrNull($sources['rwa_source_future']??null)),
      'underlying_metrics_source_within_24h'=>$withinScreen(intOrNull($underlying['total']??null),intOrNull($underlying['source_older_24h']??null),intOrNull($underlying['source_unknown']??null),intOrNull($underlying['source_future']??null)),
      'general_price_source_older_24h'=>intOrNull($sources['general_source_older_24h']??null),
      'general_price_source_unknown'=>intOrNull($sources['general_source_unknown']??null),
      'general_price_source_future'=>intOrNull($sources['general_source_future']??null),
      'rwa_price_source_older_24h'=>intOrNull($sources['rwa_source_older_24h']??null),
      'rwa_price_source_unknown'=>intOrNull($sources['rwa_source_unknown']??null),
      'rwa_price_source_future'=>intOrNull($sources['rwa_source_future']??null),
      'underlying_metrics_with_values'=>intOrNull($underlying['total']??null),
      'underlying_metrics_source_latest_utc'=>iso($underlying['source_latest']??null),
      'underlying_metrics_snapshot_generated_latest_utc'=>iso($underlying['snapshot_generated_latest']??null),
      'underlying_metrics_source_older_24h'=>intOrNull($underlying['source_older_24h']??null),
      'underlying_metrics_source_unknown'=>intOrNull($underlying['source_unknown']??null),
      'underlying_metrics_source_future'=>intOrNull($underlying['source_future']??null),
      'delayed_general_fetch_examples'=>delayedFetchExamples($db,'general_api_updated_at',$sampleLimit),
      'delayed_rwa_fetch_examples'=>delayedFetchExamples($db,'rwa_api_updated_at',$sampleLimit),
      'dated_general_price_examples'=>sourcePriceExamples($db,'price_time',$sampleLimit),
      'dated_rwa_price_examples'=>sourcePriceExamples($db,'rwa_price_updated_at',$sampleLimit),
      'dated_underlying_metric_examples'=>underlyingSourceExamples($db,$sampleLimit),
      'technical_report_generated_utc'=>iso($tech['generated_at_utc']??null),'technical_report_imported_utc'=>iso($tech['imported_at_utc']??null),
      'technical_scope_complete'=>isset($tech['scope_complete'])?(bool)$tech['scope_complete']:null,'technical_scanned'=>intOrNull($tech['scanned_count']??null),'technical_eligible'=>intOrNull($tech['eligible_count']??null),
      'technical_candidates'=>intOrNull($tech['candidate_count']??null),'technical_unavailable'=>intOrNull($tech['data_unavailable_count']??null)],
      'notes'=>['Ten-minute figures are successful fetch coverage per active provider-token record, not the source timestamp of stock prices, RWA token prices or underlying metrics.',
         'Source-age counts use a 24-hour screening threshold, with missing and future timestamps counted separately; an old timestamp does not by itself indicate a provider outage or a market-data error (e.g. markets can close).',
         'RWA price source age comes from tokenPriceUpdatedAt; this is not proof that the underlying reference price was updated at that instant.',
         'Underlying metric source timestamps belong to saved underlying ranking rows with a market cap or 24h volume; a fresh snapshot-generation time does not mean its source values changed.',
         'Example arrays are bounded, non-exhaustive. Token-level source samples can contain multiple providers for the same ticker. Unknown is not fresh.',
         'Technical report generation time does not prove source candle recency. No live Binance query is made by this tool.'],
      'binance_http_requests'=>0,'binance_request_weight'=>0];
}
function getAssets(PDO $db, array $p): array {
    $ranked=[];
    foreach($db->query('SELECT underlying_ticker,underlying_name,selected_provider,market_cap,market_cap_rank,market_cap_rank_total,volume_24h,volume_24h_rank,volume_24h_rank_total,source_updated_at,generated_at FROM rwa_underlying_rank_latest') as $r){
        $t=strtoupper(trim((string)($r['underlying_ticker']??'')));if($t!=='')$ranked[$t]=$r;
    }
    $groups=[];
    $q="SELECT t.underlying_ticker,t.underlying_name,t.token_name,t.provider,t.symbol,t.asset_type,
       m.price,m.reference_price,m.rwa_token_price,m.price_change_24h,m.price_time,m.general_api_updated_at,m.rwa_price_updated_at,m.rwa_api_updated_at
       FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id=t.id
       WHERE t.is_active=1 AND t.chain_id='56' AND t.provider IN ('bstock','ondo','xstocks') AND t.underlying_ticker IS NOT NULL AND t.underlying_ticker <> ''";
    foreach($db->query($q) as $r){
        $t=strtoupper(trim((string)$r['underlying_ticker']));$pr=(string)$r['provider'];
        if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$t))continue;
        $g=&$groups[$t];
        if(!isset($g))$g=['ticker'=>$t,'name'=>trim((string)($r['underlying_name']?:$r['token_name']?:'')),'providers'=>[],'price_rows'=>[],'type_codes'=>[]];
        $g['providers'][$pr]=true;$g['type_codes'][]=intOrNull($r['asset_type']);
        $ref=numberOrNull($r['reference_price']);$raw=numberOrNull($r['price']);$rwa=numberOrNull($r['rwa_token_price']);
        $value=$ref??$raw??$rwa;
        $kind=$ref!==null?'reference_price':($raw!==null?'general_market_price':($rwa!==null?'rwa_token_price':null));
        $row=['provider'=>$pr,'symbol'=>(string)$r['symbol'],'price'=>$value,'price_kind'=>$kind,
          'price_source_utc'=>iso($kind==='general_market_price'?$r['price_time']:$r['rwa_price_updated_at']),
          'price_fetch_utc'=>iso($kind==='general_market_price'?$r['general_api_updated_at']:$r['rwa_api_updated_at']),
          'pnl_24h'=>numberOrNull($r['price_change_24h']),'pnl_source_utc'=>iso($r['price_time'])];
        $old=$g['price_rows'][$pr]??null;
        $score=($row['price']!==null?2:0)+($row['pnl_24h']!==null?1:0);
        $oldScore=$old===null?-1:(($old['price']!==null?2:0)+($old['pnl_24h']!==null?1:0));
        if($score>$oldScore)$g['price_rows'][$pr]=$row;
        unset($g);
    }
    $all=[];
    foreach($groups as $t=>$g){
        $rank=$ranked[$t]??[];
        $name=trim((string)($rank['underlying_name']??'')) ?: $g['name'];
        if($p['tickers'] && !in_array($t,$p['tickers'],true))continue;
        if($p['search']!=='' && stripos($t,$p['search'])===false && stripos($name,$p['search'])===false)continue;
        if($p['provider']!=='all' && !isset($g['providers'][$p['provider']]))continue;
        $typeCodes=$g['type_codes'];
        $type = $typeCodes && count(array_filter($typeCodes,static fn($x)=>$x===1))===count($typeCodes)?'Stock':($typeCodes && count(array_filter($typeCodes,static fn($x)=>$x===3))===count($typeCodes)?'ETF':null);
        if($p['assetType']!=='all' && strtolower((string)$type)!==$p['assetType'])continue;
        $providers=array_keys($g['providers']);usort($providers,static fn($a,$b)=>providerOrder($a)<=>providerOrder($b));
        $preferred=(string)($rank['selected_provider']??'');
        $selected=$g['price_rows'][$preferred]??null;
        if(!$selected || ($selected['price']===null && $selected['pnl_24h']===null)){
          foreach($providers as $pr){$candidate=$g['price_rows'][$pr]??null;if($candidate && ($candidate['price']!==null || $candidate['pnl_24h']!==null)){$selected=$candidate;break;}}
        }
        $selected??=$g['price_rows'][$providers[0]]??[];
        $all[]=['ticker'=>$t,'name'=>$name,'asset_type'=>$type,'providers'=>$providers,
          'market_cap'=>numberOrNull($rank['market_cap']??null),'market_cap_rank'=>intOrNull($rank['market_cap_rank']??null),'market_cap_rank_total'=>intOrNull($rank['market_cap_rank_total']??null),
          'volume_24h'=>numberOrNull($rank['volume_24h']??null),'volume_24h_rank'=>intOrNull($rank['volume_24h_rank']??null),'volume_24h_rank_total'=>intOrNull($rank['volume_24h_rank_total']??null),
          'underlying_source_utc'=>iso($rank['source_updated_at']??null),'underlying_fetch_utc'=>iso($rank['generated_at']??null),
          'price'=>$selected['price']??null,'price_kind'=>$selected['price_kind']??null,'price_provider'=>$selected['provider']??null,
          'price_source_utc'=>$selected['price_source_utc']??null,'price_fetch_utc'=>$selected['price_fetch_utc']??null,
          'pnl_24h'=>$selected['pnl_24h']??null,'pnl_source_utc'=>$selected['pnl_source_utc']??null];
    }
    if($p['technical']){
        $q=$db->query('SELECT ticker,provider,symbol,status,rsi14,sma20,sma50,sma200,close_price,source_candle_open_utc,api_fetch_at_utc FROM rwa_technical_latest');
        $tech=[];foreach($q as $r)$tech[strtoupper((string)$r['ticker'])]=$r;
        foreach($all as &$item){$r=$tech[$item['ticker']]??null;
            $item['technical']=$r?['provider'=>(string)$r['provider'],'status'=>(string)$r['status'],'rsi14'=>numberOrNull($r['rsi14']),
                'sma20'=>numberOrNull($r['sma20']),'sma50'=>numberOrNull($r['sma50']),'sma200'=>numberOrNull($r['sma200']),'close'=>numberOrNull($r['close_price']),
                'candle_open_utc'=>iso($r['source_candle_open_utc']),'api_fetch_utc'=>iso($r['api_fetch_at_utc'])]:null;
        }unset($item);
        if($p['technicalStatus']==='candidate')$all=array_values(array_filter($all,static fn($r)=>($r['technical']['status']??null)==='technical_candidate'));
        if($p['sort']==='rsi_asc')$all=array_values(array_filter($all,static fn($r)=>$r['technical']!==null && $r['technical']['rsi14']!==null));
    }
    $keys=['market_cap_desc'=>'market_cap','market_cap_asc'=>'market_cap','volume_desc'=>'volume_24h','volume_asc'=>'volume_24h','pnl_desc'=>'pnl_24h','pnl_asc'=>'pnl_24h','rsi_asc'=>'rsi14'];
    $key=$keys[$p['sort']]??'ticker';$asc=in_array($p['sort'],['market_cap_asc','volume_asc','pnl_asc','ticker_asc','rsi_asc'],true);
    usort($all,static function($a,$b)use($key,$asc){
        if($key==='ticker')return strcmp($a['ticker'],$b['ticker']);
        $v1=$key==='rsi14'?($a['technical']['rsi14']??null):$a[$key];
        $v2=$key==='rsi14'?($b['technical']['rsi14']??null):$b[$key];
        if($v1===null || $v2===null)return $v1===$v2?strcmp($a['ticker'],$b['ticker']):($v1===null?1:-1);
        $cmp=$v1<=>$v2;return $cmp===0?strcmp($a['ticker'],$b['ticker']):($asc?$cmp:-$cmp);
    });
    $count=count($all);
    $items=array_slice($all,0,$p['limit']);
    return ['ok'=>true,'mode'=>'assets','source'=>'saved_database','as_of_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
      'query'=>['sort'=>$p['sort'],'provider'=>$p['provider'],'asset_type'=>$p['assetType'],'tickers'=>$p['tickers'],'search'=>$p['search'],'limit'=>$p['limit'],'technical'=>$p['technical'],'technical_status'=>$p['technicalStatus']],
      'total_matching'=>$count,'items'=>$items,'notes'=>['Underlying market cap and volume are saved underlying metrics, not BSC token trade volume; provider figures are not added or averaged.',
       'Displayed prices are saved observations, not executable quotes. Source timestamps and API fetch timestamps are distinct. Missing values are null, not zero.',
       'Technical values are recorded token daily-candle indicators, not a live stock quote or confirmed future outcome.'],
      'binance_http_requests'=>0,'binance_request_weight'=>0];
}


/** Reads ONLY the published contract-keyed holder snapshot; no upstream HTTP requests. */
function getHolders(PDO $db, array $p): array {
    $meta=$db->query("SELECT snapshot_id,source,chain_id,rank_type,sort_by,received_total,fetched_at_utc
        FROM rwa_wallet_stock_rank_meta WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$meta || (int)$meta['chain_id']!==56 || (int)$meta['rank_type']!==40 || (int)$meta['sort_by']!==30
        || !preg_match('/\A[a-f0-9]{32}\z/',(string)$meta['snapshot_id'])) {
        return ['ok'=>true,'mode'=>'holders','source'=>'saved_database','available'=>false,
            'as_of_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'items'=>[],'total_matching'=>0,'total_ranked'=>null,
            'snapshot_fetched_at_utc'=>null,'snapshot_age_seconds'=>null,'stale'=>true,
            'notes'=>['The published stock-token holder snapshot is unavailable. No holder statistics have been inferred.'],
            'binance_http_requests'=>0,'binance_request_weight'=>0];
    }
    $timestamp=strtotime((string)$meta['fetched_at_utc'].' UTC');
    $age=$timestamp===false?null:max(0,time()-$timestamp);
    $stale=$age===null||$age>5400;
    // Only named underlying filters may include absent provider contracts (e.g. xStocks).
    // Rankings use the single Binance-reported token universe, never a synthetic provider total.
    $specific=count($p['tickers'])>0;
    $sql="SELECT t.provider,t.symbol,t.underlying_ticker,t.asset_type,
           LOWER(t.contract_address) AS contract_address,
           r.position,r.holders,r.kyc_holders,r.holders_top10_pct
         FROM rwa_tokens t
         LEFT JOIN rwa_wallet_stock_rank_rows r
           ON r.snapshot_id=? AND r.contract_address=LOWER(t.contract_address)
              AND r.provider=t.provider AND BINARY r.token_symbol=BINARY t.symbol
              AND BINARY r.underlying_ticker=BINARY UPPER(t.underlying_ticker)
         WHERE t.chain_id='56' AND t.is_active=1 AND t.provider IN ('bstock','ondo','xstocks')";
    $args=[(string)$meta['snapshot_id']];
    if ($specific) {
        $sql.=' AND UPPER(t.underlying_ticker) IN ('.implode(',',array_fill(0,count($p['tickers']),'?')).')';
        array_push($args,...$p['tickers']);
    } elseif ($p['search']==='') $sql.=' AND r.position IS NOT NULL';
    if ($p['provider']!=='all') {$sql.=' AND t.provider=?';$args[]=$p['provider'];}
    if ($p['assetType']!=='all')$sql.=' AND t.asset_type='.($p['assetType']==='stock'?'1':'3');
    if ($p['search']!=='') {
        $sql.=' AND (UPPER(t.underlying_ticker) LIKE ? OR t.symbol LIKE ?)';
        $s='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$p['search']).'%';
        $args[]=$s;$args[]=$s;
    }
    $order=match($p['sort']) {
        'top10_concentration_desc'=>'(r.holders_top10_pct IS NULL), r.holders_top10_pct DESC, r.position',
        'kyc_holders_desc'=>'(r.kyc_holders IS NULL), r.kyc_holders DESC, r.position',
        default=>'(r.position IS NULL), r.position'
    };
    $sql.=" ORDER BY {$order}, t.underlying_ticker, FIELD(t.provider,'bstock','ondo','xstocks'), t.symbol";
    $stmt=$db->prepare($sql);$stmt->execute($args);
    $items=[];
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $contract=(string)$row['contract_address'];
        if (!preg_match('/\A0x[a-f0-9]{40}\z/',$contract))continue;
        $items[]=['ticker'=>strtoupper((string)$row['underlying_ticker']),
            'provider'=>(string)$row['provider'],'symbol'=>(string)$row['symbol'],
            'contract_address'=>$contract,'holder_rank'=>intOrNull($row['position']),
            'holder_rank_total'=>intOrNull($meta['received_total']),
            'holders'=>intOrNull($row['holders']),'kyc_holders'=>intOrNull($row['kyc_holders']),
            'top10_holder_pct'=>numberOrNull($row['holders_top10_pct'])];
    }
    $count=count($items);$items=array_slice($items,0,$p['limit']);
    $cached=[];
    if ($specific) {
        foreach($p['tickers'] as $ticker){
            $cache=readSkillsCacheForTicker($db,$ticker);
            if($cache!==null)$cached[$ticker]=$cache;
        }
    }
    return ['ok'=>true,'mode'=>'holders','source'=>'saved_database','available'=>true,
        'as_of_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_fetched_at_utc'=>iso($meta['fetched_at_utc']),
        'snapshot_age_seconds'=>$age,'stale'=>$stale,'total_ranked'=>intOrNull($meta['received_total']),
        'total_matching'=>$count,'query'=>['provider'=>$p['provider'],'tickers'=>$p['tickers'],
           'search'=>$p['search'],'limit'=>$p['limit']],
        'items'=>$items,'cached_asset_skills'=>$cached,
        'notes'=>['Holder rank is a provider-token rank in the Binance BSC Stock Token Rank universe; it is not an underlying rank.',
          'These holder and KYC counts are Binance-reported per-contract values, not verified unique people; do not add values across providers.',
          'Null KYC is unavailable and differs from a real 0. Top-10 percentage measures the reported concentration, not proof of a security risk.',
          'Provider contracts absent from this ranked universe have null holder metrics. The ranking does not cover all active RWA tokens.',
          'Cached Ondo data, when present, has a separate collection time. Cross-chain Ondo holder counts must not be compared as BSC contract counts. Ondo market status is not a guarantee that a BSC swap can execute.',
          'Security audit is informational and not a trading safety approval; unavailable is not low risk.',
          'Hubble did not make any Binance requests. Older published snapshots must not be described as current.'],
        'binance_http_requests'=>0,'binance_request_weight'=>0];
}
/** Strict read of a still-valid local Skills cache entry; never triggers a fetch. */
function readSkillsCacheForTicker(PDO $db,string $ticker): ?array {
    $stmt=$db->prepare("SELECT provider,symbol,LOWER(contract_address) AS contract_address
        FROM rwa_tokens WHERE chain_id='56' AND is_active=1 AND UPPER(underlying_ticker)=?
          AND provider IN ('bstock','ondo','xstocks')
        ORDER BY FIELD(provider,'bstock','ondo','xstocks'),symbol ASC LIMIT 8");
    $stmt->execute([$ticker]);$records=[];$seen=[];
    foreach($stmt as $r){
        $contract=(string)$r['contract_address'];
        if(!preg_match('/\A0x[a-f0-9]{40}\z/',$contract)||isset($seen[$contract]))continue;
        $seen[$contract]=true;
        $records[]=['provider'=>$r['provider'],'symbol'=>substr((string)$r['symbol'],0,40),
            'contract_address'=>$contract];
        if(count($records)===4)break;
    }
    if(!$records)return null;
    $key=hash('sha256',$ticker.'|'.json_encode($records,JSON_THROW_ON_ERROR));
    $path=sys_get_temp_dir().'/bhub-wallet-skills-v1/'.$key.'.json';
    if(!is_file($path)||!is_readable($path))return null;
    $raw=@file_get_contents($path,false,null,0,60000);
    $cache=is_string($raw)?json_decode($raw,true,32):null;
    if(!is_array($cache)||!is_int($cache['expires']??null)||$cache['expires']<=time()
       ||!is_array($cache['payload']??null))return null;
    $payload=$cache['payload'];
    if(($payload['ok']??null)!==true||($payload['ticker']??null)!==$ticker||($payload['chain_id']??null)!=='56'
       ||($payload['source']??null)!=='binance_wallet_skills')return null;
    $audits=[];
    foreach(($payload['audits']??[]) as $audit) {
        if(!is_array($audit)||!is_array($audit['audit']??null))continue;
        $contract=strtolower((string)($audit['contract']??''));
        $matched=false;
        foreach($records as $rep)if($rep['contract_address']===$contract && $rep['provider']===($audit['provider']??null)
           && $rep['symbol']===($audit['symbol']??null)){$matched=true;break;}
        if(!$matched)continue;
        $entry=$audit['audit'];$available=($entry['available']??null)===true;
        $risk=$entry['risk']??null;$level=$entry['risk_level']??null;
        // Even a cached result must have supported risk semantics; never trust incidental LOW/-1.
        $valid=$available&&is_int($level)&&in_array($risk,['LOW','MEDIUM','HIGH'],true)
          && (($risk==='LOW'&&$level>=0&&$level<=1)||($risk==='MEDIUM'&&$level>=2&&$level<=3)
             ||($risk==='HIGH'&&$level>=4&&$level<=5));
        $audits[]=['provider'=>$audit['provider'],'symbol'=>$audit['symbol'],
           'audit_status'=>$valid?'supported_result':'not_available',
           'risk_level'=>$valid?$risk:null,
           'verified'=>$valid&&is_bool($entry['verified']??null)?$entry['verified']:null];
    }
    $ondo=null;$o=$payload['ondo']??null;
    if(is_array($o) && is_string($o['contract']??null)){
        foreach($records as $rep)if($rep['provider']==='ondo' && $rep['contract_address']===strtolower($o['contract'])
          && $rep['symbol']===($o['symbol']??null)){
            $ondo=['symbol'=>$rep['symbol'],'market_status'=>is_array($o['status']??null)?$o['status']:null,
              'fundamentals'=>is_array($o['fundamentals']??null)?$o['fundamentals']:null,
              'company'=>is_array($o['company']??null)?$o['company']:null];
            break;
        }
    }
    return ['source'=>'valid_local_cache','fetched_at_utc'=>$payload['fetched_at_utc']??null,
       'audit'=>$audits,'ondo'=>$ondo];
}

if (PHP_SAPI !== 'cli' || count($argv) !== 2 || strlen($argv[1])>1600) emit(['ok'=>false,'error'=>'invalid_request']);
try {
    $plan=validatePlan(json_decode($argv[1],true,16,JSON_THROW_ON_ERROR));
    if($plan===null)emit(['ok'=>false,'error'=>'invalid_request']);
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    $db=chatDb();
    if(!$db instanceof PDO)throw new RuntimeException('database_unavailable');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    emit($plan['mode']==='health'?getHealth($db,$plan['limit']):($plan['mode']==='holders'?getHolders($db,$plan):getAssets($db,$plan)));
} catch(Throwable $e) {
    error_log('[rwa-research] read failed: '.get_class($e));
    emit(['ok'=>false,'error'=>'research_data_unavailable']);
}
