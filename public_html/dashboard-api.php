<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

const PROVIDERS = ['bstock','ondo','xstocks'];

function respond(array $payload, int $status=200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
function scalarInt(mixed $value): int { return is_numeric($value) ? (int)$value : 0; }
function scalarFloat(mixed $value): ?float { return is_numeric($value) && is_finite((float)$value) ? (float)$value : null; }
function utc(?string $value): ?string {
    if (!$value) return null;
    try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'); }
    catch (Throwable) { return null; }
}
function sourceAgeSeconds(?string $value): ?int {
    if (!$value) return null;
    try { $d=new DateTimeImmutable($value,new DateTimeZone('UTC')); return max(0,time()-$d->getTimestamp()); }
    catch (Throwable) { return null; }
}
function providerLabel(string $provider): string {
    return match($provider){'bstock'=>'bStocks','ondo'=>'Ondo','xstocks'=>'xStocks',default=>$provider};
}
function providerRank(string $provider): int {
    return match($provider){'bstock'=>0,'ondo'=>1,'xstocks'=>2,default=>9};
}
function technicalMeta(PDO $db): ?array {
    try {
        $r=$db->query("SELECT generated_at_utc, scope_complete, eligible_count, scanned_count, candidate_count, data_unavailable_count, bstock_count, ondo_count, imported_at_utc FROM rwa_technical_snapshot_meta WHERE id=1")->fetch();
        return $r?:null;
    } catch (Throwable) { return null; }
}
function names(PDO $db): array {
    $q=$db->query("SELECT underlying_ticker, underlying_name, token_name FROM rwa_tokens WHERE is_active=1 AND chain_id='56' AND provider IN ('bstock','ondo','xstocks') ORDER BY FIELD(provider,'bstock','ondo','xstocks'), id");
    $out=[];
    foreach($q as $r){$ticker=strtoupper(trim((string)$r['underlying_ticker']));if($ticker==='')continue;$name=trim((string)($r['underlying_name']??''));if($name==='')$name=trim((string)($r['token_name']??''));if(!isset($out[$ticker])&&$name!=='')$out[$ticker]=$name;}
    return $out;
}
function underlyingMarketMetrics(mixed $raw, string $provider): array {
    $empty=['market_cap'=>null,'volume_24h'=>null];
    if (!is_string($raw) || $raw==='') return $empty;
    $meta=json_decode($raw,true,16);
    if (!is_array($meta)) return $empty;

    $market=$meta['_underlyingMarket']??null;
    if (is_array($market)) {
        return [
            'market_cap'=>scalarFloat($market['marketCap']??null),
            'volume_24h'=>scalarFloat($market['volume24H']??null),
        ];
    }

    // Compatibility for any older bStocks/Ondo rows that retained the raw
    // RWA Token List fields before they were renamed explicitly. Do not
    // infer the same semantics for the separate xStocks public catalogue.
    if (in_array($provider,['bstock','ondo'],true)) {
        return [
            'market_cap'=>scalarFloat($meta['marketCap']??null),
            'volume_24h'=>scalarFloat($meta['volume24H']??null),
        ];
    }

    return $empty;
}
function dashboardLogo(mixed $raw): ?string {
    if (!is_string($raw) || $raw==='') return null;
    $meta=json_decode($raw,true,16);
    if (!is_array($meta)) return null;
    foreach (['tokenLogoUrl','icon'] as $key) {
        $value=$meta[$key]??null;
        if (!is_string($value) || strlen($value)>1024) continue;
        if (str_starts_with($value,'/images/web3-data/public/token/logos/')) $value='https://bin.bnbstatic.com'.$value;
        $u=parse_url($value);
        if (!is_array($u) || ($u['scheme']??'')!=='https' || isset($u['user']) || isset($u['pass']) || isset($u['port']) || isset($u['query']) || isset($u['fragment'])) continue;
        if (!in_array($u['host']??'',['onchainos.bnbstatic.com','bin.bnbstatic.com','public.bnbstatic.com'],true)) continue;
        if (!preg_match('~\A/images/web3-data/public/token/logos/[A-Za-z0-9._-]+\.(?:png|jpg|jpeg|webp|svg)\z~i',$u['path']??'')) continue;
        return $value;
    }
    return null;
}
function revision(array $data): string {return substr(hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),0,20);}

$section=$_GET['section']??'overview';
if(!is_string($section)||!in_array($section,['overview','pulse','opportunities','providers','health'],true)) respond(['ok'=>false,'error'=>'invalid_section'],400);

try{
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    $db=chatDb();

    if($section==='overview'){
        $coverage=$db->query("SELECT COUNT(*) token_records, COUNT(DISTINCT underlying_ticker) underlying_assets, COUNT(DISTINCT provider) providers, MAX(last_seen_at) catalogue_seen_utc FROM rwa_tokens WHERE is_active=1 AND chain_id='56' AND provider IN ('bstock','ondo','xstocks')")->fetch();
        $fetch=$db->query("SELECT MAX(m.general_api_updated_at) market_fetch_utc, MAX(m.rwa_api_updated_at) rwa_fetch_utc FROM rwa_market_latest m JOIN rwa_tokens t ON t.id=m.token_id WHERE t.is_active=1 AND t.chain_id='56' AND t.provider IN ('bstock','ondo','xstocks')")->fetch();
        $w=technicalMeta($db);
        $data=[
            'token_records'=>scalarInt($coverage['token_records']??0),
            'underlying_assets'=>scalarInt($coverage['underlying_assets']??0),
            'providers'=>scalarInt($coverage['providers']??0),
            'technical_candidates'=>$w?scalarInt($w['candidate_count']??0):null,
            'technical_scanned'=>$w?scalarInt($w['scanned_count']??0):null,
            'technical_eligible'=>$w&&is_numeric($w['eligible_count']??null)?scalarInt($w['eligible_count']):null,
            'market_fetch_utc'=>utc($fetch['market_fetch_utc']??null),
            'rwa_fetch_utc'=>utc($fetch['rwa_fetch_utc']??null),
            'catalogue_seen_utc'=>utc($coverage['catalogue_seen_utc']??null),
            'technical_generated_utc'=>$w?utc($w['generated_at_utc']??null):null,
        ];
    }elseif($section==='pulse'){
        $q=$db->query("SELECT t.underlying_ticker, t.underlying_name, t.token_name, t.provider, t.symbol, LEFT(t.metadata_json,65536) metadata_json, m.price_change_1h, m.price_change_24h, m.price_time, m.general_api_updated_at, um.market_cap underlying_market_cap_db, um.volume_24h underlying_volume_24h_db FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id=t.id LEFT JOIN rwa_underlying_market_latest um ON um.provider=t.provider AND um.underlying_ticker=t.underlying_ticker WHERE t.is_active=1 AND t.chain_id='56' AND t.provider IN ('bstock','ondo','xstocks')");
        $groups=[];
        foreach($q as $r){
            $ticker=strtoupper(trim((string)$r['underlying_ticker']));if($ticker==='')continue;
            $name=trim((string)($r['underlying_name']??''));if($name==='')$name=trim((string)($r['token_name']??''));
            $provider=(string)$r['provider'];
            $underlying=underlyingMarketMetrics($r['metadata_json']??null,$provider);
            $dbMarketCap=scalarFloat($r['underlying_market_cap_db']??null);
            $dbVolume24h=scalarFloat($r['underlying_volume_24h_db']??null);
            $candidate=['ticker'=>$ticker,'name'=>$name,'provider'=>$provider,'symbol'=>(string)$r['symbol'],'logo_url'=>dashboardLogo($r['metadata_json']??null),'change_1h'=>scalarFloat($r['price_change_1h']??null),'change_24h'=>scalarFloat($r['price_change_24h']??null),'source_utc'=>utc($r['price_time']??null),'fetch_utc'=>utc($r['general_api_updated_at']??null),'source_age'=>sourceAgeSeconds($r['price_time']??null),'underlying_market_cap'=>$dbMarketCap??$underlying['market_cap'],'underlying_volume_24h'=>$dbVolume24h??$underlying['volume_24h']];
            if(!isset($groups[$ticker]))$groups[$ticker]=['name'=>$name,'rows'=>[]];elseif($groups[$ticker]['name']===''&&$name!=='')$groups[$ticker]['name']=$name;
            $groups[$ticker]['rows'][]=$candidate;
        }
        $rows=[];
        foreach($groups as $ticker=>$g){
            $usable=array_values(array_filter($g['rows'],fn($r)=>$r['change_24h']!==null&&$r['source_age']!==null&&$r['source_age']<=86400));
            if(!$usable)continue;
            usort($usable,fn($a,$b)=>providerRank($a['provider'])<=>providerRank($b['provider']));
            $r=$usable[0];$r['name']=$g['name'];$r['provider_label']=providerLabel($r['provider']);unset($r['source_age']);$rows[]=$r;
        }
        usort($rows,fn($a,$b)=>($b['change_24h']<=>$a['change_24h'])?:strcmp($a['ticker'],$b['ticker']));

        $marketCapRows=[];$volumeRows=[];
        try{
            $rankRows=$db->query("SELECT underlying_ticker ticker, underlying_name name, selected_provider provider, token_symbol symbol, market_cap underlying_market_cap, market_cap_rank, market_cap_rank_total, volume_24h underlying_volume_24h, volume_24h_rank, volume_24h_rank_total FROM rwa_underlying_rank_latest WHERE market_cap_rank IS NOT NULL OR volume_24h_rank IS NOT NULL ORDER BY underlying_ticker")->fetchAll();
            foreach($rankRows as $rankRow){
                $ticker=strtoupper(trim((string)$rankRow['ticker']));if($ticker==='')continue;
                $logo='';
                if(isset($groups[$ticker])){
                    $assetRows=$groups[$ticker]['rows'];
                    usort($assetRows,fn($a,$b)=>providerRank($a['provider'])<=>providerRank($b['provider']));
                    foreach($assetRows as $assetRow){if((string)($rankRow['provider']??'')===(string)$assetRow['provider']){$logo=$assetRow['logo_url'];break;}}
                    if($logo===''&&$assetRows)$logo=$assetRows[0]['logo_url']??'';
                }
                $base=['ticker'=>$ticker,'name'=>(string)($rankRow['name']??''),'provider'=>(string)($rankRow['provider']??''),'provider_label'=>providerLabel((string)($rankRow['provider']??'')),'symbol'=>(string)($rankRow['symbol']??''),'logo_url'=>$logo,'underlying_market_cap'=>scalarFloat($rankRow['underlying_market_cap']??null),'market_cap_rank'=>isset($rankRow['market_cap_rank'])?(int)$rankRow['market_cap_rank']:null,'market_cap_rank_total'=>(int)($rankRow['market_cap_rank_total']??0),'underlying_volume_24h'=>scalarFloat($rankRow['underlying_volume_24h']??null),'volume_24h_rank'=>isset($rankRow['volume_24h_rank'])?(int)$rankRow['volume_24h_rank']:null,'volume_24h_rank_total'=>(int)($rankRow['volume_24h_rank_total']??0)];
                if($base['market_cap_rank']!==null)$marketCapRows[]=$base;
                if($base['volume_24h_rank']!==null)$volumeRows[]=$base;
            }
            usort($marketCapRows,fn($a,$b)=>$a['market_cap_rank']<=>$b['market_cap_rank']);
            usort($volumeRows,fn($a,$b)=>$a['volume_24h_rank']<=>$b['volume_24h_rank']);
        }catch(Throwable $e){error_log('[dashboard] underlying rank table unavailable');}
        $changes=array_column($rows,'change_24h');sort($changes,SORT_NUMERIC);$n=count($changes);$median=$n?($n%2?$changes[intdiv($n,2)]:($changes[$n/2-1]+$changes[$n/2])/2):null;
        $gainers=array_slice($rows,0,5);$losers=$rows;usort($losers,fn($a,$b)=>($a['change_24h']<=>$b['change_24h'])?:strcmp($a['ticker'],$b['ticker']));$losers=array_slice($losers,0,5);
        $data=['usable_assets'=>$n,'advancing'=>count(array_filter($rows,fn($r)=>$r['change_24h']>0)),'declining'=>count(array_filter($rows,fn($r)=>$r['change_24h']<0)),'flat'=>count(array_filter($rows,fn($r)=>$r['change_24h']==0.0)),'median_24h'=>$median,'gainers'=>$gainers,'losers'=>$losers,'top_underlying_market_cap'=>array_slice($marketCapRows,0,5),'top_underlying_volume_24h'=>array_slice($volumeRows,0,5),'freshness_rule'=>'Mover rankings use representative provider rows with a source timestamp no older than 24 hours. Underlying market-cap and 24h-volume rankings come from the database rank snapshot. One provider is selected per underlying with preference bStocks, then Ondo, then xStocks; provider values are not combined or averaged.'];
    }elseif($section==='opportunities'){
        $w=technicalMeta($db);
        if(!$w){$data=['available'=>false,'message'=>'The database technical snapshot is unavailable.'];}
        else{
            $nameMap=names($db);$candidates=[];
            try{$q=$db->query("SELECT r.ticker, r.provider, r.symbol, r.close_price, r.sma20, r.sma50, r.rsi14, r.source_candle_open_utc, r.api_fetch_at_utc, r.signals_json, (SELECT LEFT(t.metadata_json,65536) FROM rwa_tokens t WHERE t.is_active=1 AND t.chain_id='56' AND t.underlying_ticker=r.ticker AND t.provider=r.provider ORDER BY CASE WHEN t.symbol=r.symbol THEN 0 ELSE 1 END, t.id LIMIT 1) metadata_json FROM rwa_technical_latest r WHERE r.status='technical_candidate' ORDER BY r.attention_points DESC, r.ticker ASC LIMIT 12");
                foreach($q as $r){$ticker=strtoupper((string)$r['ticker']);$signals=[];try{$decoded=json_decode((string)$r['signals_json'],true,16,JSON_THROW_ON_ERROR);if(is_array($decoded))foreach($decoded as $v)if(is_string($v)&&preg_match('/^[a-z_]{1,40}$/',$v))$signals[]=$v;}catch(Throwable){}
                    $candidates[]=['ticker'=>$ticker,'name'=>$nameMap[$ticker]??'','provider'=>(string)$r['provider'],'provider_label'=>providerLabel((string)$r['provider']),'symbol'=>(string)$r['symbol'],'logo_url'=>dashboardLogo($r['metadata_json']??null),'rsi14'=>scalarFloat($r['rsi14']??null),'sma20'=>scalarFloat($r['sma20']??null),'sma50'=>scalarFloat($r['sma50']??null),'close'=>scalarFloat($r['close_price']??null),'source_candle_open_utc'=>utc($r['source_candle_open_utc']??null),'api_fetch_utc'=>utc($r['api_fetch_at_utc']??null),'signals'=>array_slice($signals,0,5)];}
            }catch(Throwable){$candidates=[];}
            $data=['available'=>true,'generated_at_utc'=>utc($w['generated_at_utc']??null),'scope_complete'=>(bool)($w['scope_complete']??false),'eligible_count'=>scalarInt($w['eligible_count']??0),'scanned_count'=>scalarInt($w['scanned_count']??0),'candidate_count'=>scalarInt($w['candidate_count']??0),'data_unavailable_count'=>scalarInt($w['data_unavailable_count']??0),'provider_counts'=>['bstock'=>scalarInt($w['bstock_count']??0),'ondo'=>scalarInt($w['ondo_count']??0)],'candidates'=>$candidates];
        }
    }elseif($section==='providers'){
        $rows=[];$q=$db->query("SELECT provider, COUNT(*) token_records, COUNT(DISTINCT underlying_ticker) underlyings, MAX(last_seen_at) last_seen_utc FROM rwa_tokens WHERE is_active=1 AND chain_id='56' AND provider IN ('bstock','ondo','xstocks') GROUP BY provider");
        foreach($q as $r)$rows[]=['provider'=>(string)$r['provider'],'label'=>providerLabel((string)$r['provider']),'token_records'=>scalarInt($r['token_records']),'underlyings'=>scalarInt($r['underlyings']),'last_seen_utc'=>utc($r['last_seen_utc']??null)];
        usort($rows,fn($a,$b)=>providerRank($a['provider'])<=>providerRank($b['provider']));
        $distribution=[1=>0,2=>0,3=>0];$q=$db->query("SELECT provider_count, COUNT(*) underlyings FROM (SELECT underlying_ticker, COUNT(DISTINCT provider) provider_count FROM rwa_tokens WHERE is_active=1 AND chain_id='56' AND provider IN ('bstock','ondo','xstocks') GROUP BY underlying_ticker) x GROUP BY provider_count");foreach($q as $r){$k=scalarInt($r['provider_count']);if(isset($distribution[$k]))$distribution[$k]=scalarInt($r['underlyings']);}
        $data=['providers'=>$rows,'underlying_distribution'=>['one_provider'=>$distribution[1],'two_providers'=>$distribution[2],'three_providers'=>$distribution[3]],'total_underlyings'=>array_sum($distribution)];
    }else{
        $r=$db->query("SELECT COUNT(*) total_records, MAX(m.general_api_updated_at) market_fetch_latest, MIN(m.general_api_updated_at) market_fetch_oldest, MAX(m.rwa_api_updated_at) rwa_fetch_latest, MAX(t.last_seen_at) catalogue_seen_utc, SUM(m.general_api_updated_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE)) general_fetch_10m, SUM(m.general_api_updated_at IS NULL) general_fetch_missing, SUM(m.rwa_api_updated_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE)) rwa_fetch_10m, SUM(m.rwa_api_updated_at IS NULL) rwa_fetch_missing, SUM(m.price_time >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE)) source_15m, SUM(m.price_time < DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE) AND m.price_time >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 HOUR)) source_2h, SUM(m.price_time < DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 HOUR) AND m.price_time >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 24 HOUR)) source_24h, SUM(m.price_time < DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 24 HOUR)) source_old, SUM(m.price_time IS NULL) source_missing, SUM(m.rwa_price_updated_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE)) rwa_source_15m, SUM(m.rwa_price_updated_at < DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE) AND m.rwa_price_updated_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 HOUR)) rwa_source_2h, SUM(m.rwa_price_updated_at < DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 HOUR) AND m.rwa_price_updated_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 24 HOUR)) rwa_source_24h, SUM(m.rwa_price_updated_at < DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 24 HOUR)) rwa_source_old, SUM(m.rwa_price_updated_at IS NULL) rwa_source_missing FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id=t.id WHERE t.is_active=1 AND t.chain_id='56' AND t.provider IN ('bstock','ondo','xstocks')")->fetch();
        $w=technicalMeta($db);
        $data=['total_records'=>scalarInt($r['total_records']??0),'market_fetch_latest'=>utc($r['market_fetch_latest']??null),'market_fetch_oldest'=>utc($r['market_fetch_oldest']??null),'rwa_fetch_latest'=>utc($r['rwa_fetch_latest']??null),'catalogue_seen_utc'=>utc($r['catalogue_seen_utc']??null),'general_fetch_10m'=>scalarInt($r['general_fetch_10m']??0),'general_fetch_missing'=>scalarInt($r['general_fetch_missing']??0),'rwa_fetch_10m'=>scalarInt($r['rwa_fetch_10m']??0),'rwa_fetch_missing'=>scalarInt($r['rwa_fetch_missing']??0),'market_source_age'=>['under_15m'=>scalarInt($r['source_15m']??0),'15m_to_2h'=>scalarInt($r['source_2h']??0),'2h_to_24h'=>scalarInt($r['source_24h']??0),'over_24h'=>scalarInt($r['source_old']??0),'missing'=>scalarInt($r['source_missing']??0)],'rwa_source_age'=>['under_15m'=>scalarInt($r['rwa_source_15m']??0),'15m_to_2h'=>scalarInt($r['rwa_source_2h']??0),'2h_to_24h'=>scalarInt($r['rwa_source_24h']??0),'over_24h'=>scalarInt($r['rwa_source_old']??0),'missing'=>scalarInt($r['rwa_source_missing']??0)],'technical'=>$w?['generated_at_utc'=>utc($w['generated_at_utc']??null),'scanned_count'=>scalarInt($w['scanned_count']??0),'eligible_count'=>scalarInt($w['eligible_count']??0),'candidate_count'=>scalarInt($w['candidate_count']??0),'data_unavailable_count'=>scalarInt($w['data_unavailable_count']??0),'scope_complete'=>(bool)($w['scope_complete']??false)]:null];
    }
    respond(['ok'=>true,'section'=>$section,'revision'=>revision($data),'data'=>$data]);
}catch(Throwable $e){
    error_log('[dashboard-api] '.$section.' unavailable: '.$e->getMessage());
    respond(['ok'=>false,'error'=>'dashboard_unavailable'],503);
}
