<?php
declare(strict_types=1);
// CLI-only, read-only diagnostic: fetch token candles; never call a model or trade.
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function finish(array $data, int $code = 0): never {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($code);
}
$ticker = 'TSLA'; $weekly = false; $sample = false; $catalog = false; $provider = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--catalog') $catalog = true;
    elseif (str_starts_with($arg, '--provider=')) $provider = substr($arg, 11);
    elseif ($arg === '--weekly') $weekly = true;
    elseif ($arg === '--sample') $sample = true;
    elseif (str_starts_with($arg, '--ticker=')) $ticker = strtoupper(substr($arg, 9));
    else finish(['ok'=>false,'error'=>'invalid_arguments'], 1);
}
if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/', $ticker)) finish(['ok'=>false,'error'=>'invalid_ticker'],1);
if ($provider !== null && !in_array($provider, ['bstock','ondo','xstocks'], true)) finish(['ok'=>false,'error'=>'invalid_provider'],1);
function utcStamp(): string {
    $now = microtime(true); $seconds = (int)$now;
    return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', (int)(($now-$seconds)*1000));
}
function acquireCandleApiLock(float &$waitTotalMs) {
    $lock=fopen('/run/lock/bnb-rwa-all-sync.lock','r');
    if(!is_resource($lock))throw new RuntimeException('lock_unavailable');
    $waitStart=hrtime(true);$deadline=microtime(true)+90.0;
    while(!flock($lock,LOCK_EX|LOCK_NB)){
        if(microtime(true)>=$deadline){fclose($lock);throw new RuntimeException('sync_busy_try_again');}
        usleep(100000);
    }
    $waitTotalMs+=round((hrtime(true)-$waitStart)/1e6,2);
    return $lock;
}
function releaseCandleApiLock($lock):void{
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
}
function getCandles(array $query, array $cfg, array &$metrics, string $symbol, float &$lockWaitMs): array {
    static $lastRequestEnd = 0.0;
    $delay = 1.3 - (microtime(true) - $lastRequestEnd);
    if ($delay > 0) usleep((int)ceil($delay * 1000000));
    $path = '/build/api/v1/dex/market/candles?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $apiLock=acquireCandleApiLock($lockWaitMs);
    try {
    $stamp = utcStamp();
    $sign = base64_encode(hash_hmac('sha256', $stamp.'GET'.$path, (string)$cfg['binance_web3_secret_key'], true));
    $headers = []; $body = ''; $oversize = false;
    $ch = curl_init('https://web3.binance.com'.$path);
    curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-OC-APIKEY: '.$cfg['binance_web3_api_key'],
            'X-OC-TIMESTAMP: '.$stamp,'X-OC-SIGN: '.$sign,'X-OC-RECV-WINDOW: 60000'],
        CURLOPT_HEADERFUNCTION=>function($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts)===2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>function($ch, string $chunk) use (&$body, &$oversize): int {
            if (strlen($body)+strlen($chunk)>1048576) { $oversize=true; return 0; }
            $body .= $chunk; return strlen($chunk);
        }]);
    $start = hrtime(true); $success = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } finally {
        releaseCandleApiLock($apiLock);
    }
    $lastRequestEnd = microtime(true);
    $json = json_decode($body, true);
    $business = is_array($json) ? (string)($json['code']??'') : '';
    $rate = $http===429 || $business==='42900';
    $metric = ['request_utc'=>$stamp,'bar'=>$query['bar'],'symbol'=>$symbol,
        'http'=>$http,'time_ms'=>round((hrtime(true)-$start)/1e6,2),
        'weight'=>$headers['x-oc-used-weight']??null,
        'remaining'=>$headers['x-oc-ratelimit-remaining']??null,
        'rate_limit'=>$headers['x-oc-ratelimit-limit']??null];
    $metrics[] = $metric;
    fwrite(STDERR, '[rwa-candle-request] '.json_encode($metric, JSON_UNESCAPED_SLASHES)."\n");
    if ($rate || $http===401 || $http===403) throw new RuntimeException('stop_upstream');
    if ($success===false || $oversize || $http!==200 || !is_array($json) || $business!=='0'
        || !isset($json['data']) || !is_array($json['data'])) throw new RuntimeException('candles_unavailable');
    if (isset($headers['x-oc-ratelimit-remaining']) && is_numeric($headers['x-oc-ratelimit-remaining'])
        && (int)$headers['x-oc-ratelimit-remaining']<1) throw new RuntimeException('stop_upstream');
    return $json['data'];
}
$metrics=[]; $results=[]; $started=hrtime(true); $lockWaitMs=0.0;
try {
    $cfg = require '/home/bhub.tm.biz.tr/private/config.php';
    if (!is_array($cfg) || empty($cfg['binance_web3_api_key']) || empty($cfg['binance_web3_secret_key']))
        throw new RuntimeException('config_unavailable');
    $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'').';charset=utf8mb4',
        (string)($cfg['db_user']??''),(string)($cfg['db_password']??$cfg['db_pass']??''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
    $pdo->exec('SET SESSION max_statement_time = 2');
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    if ($catalog) {
        $q=$pdo->query("SELECT provider, underlying_ticker AS ticker, COUNT(*) AS token_records
            FROM rwa_tokens WHERE chain_id='56' AND is_active=1
            AND provider IN ('bstock','ondo','xstocks')
            GROUP BY provider,underlying_ticker ORDER BY underlying_ticker,provider LIMIT 3001");
        $rows=$q->fetchAll(); $pdo->commit();
        if (count($rows)>3000) throw new RuntimeException('catalogue_limit_exceeded');
        $valid=[]; $excluded=[];
        foreach ($rows as $row) {
            $t=(string)($row['ticker']??'');
            if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/', $t)) {
                $excluded[]=['provider'=>$row['provider'],'token_records'=>(int)$row['token_records'],'reason'=>'unsupported_or_missing_ticker']; continue;
            }
            $valid[]=['provider'=>$row['provider'],'ticker'=>$t,'token_records'=>(int)$row['token_records']];
        }
        finish(['ok'=>true,'action'=>'discovery_universe_catalogue','captured_at_utc'=>utcStamp(),
            'rows'=>$valid,'excluded_groups'=>$excluded,'binance_http_requests'=>0,'model_called'=>false,'database_writes'=>0]);
    }
    $s=$pdo->prepare("SELECT provider,symbol,contract_address,token_to_share_ratio,multiplier
        FROM rwa_tokens WHERE chain_id='56' AND is_active=1 AND underlying_ticker=:ticker
        AND provider IN ('bstock','ondo','xstocks')
        AND (:all_providers=1 OR provider=:provider) ORDER BY provider,symbol LIMIT 4");
    $s->execute(['ticker'=>$ticker,'provider'=>$provider??'bstock','all_providers'=>$provider===null?1:0]); $tokens=$s->fetchAll(); $pdo->commit();
    if (!$tokens || count($tokens)>3) throw new RuntimeException('unexpected_token_count');
    foreach ($tokens as $token) {
        if (!preg_match('/\A0x[0-9a-fA-F]{40}\z/', (string)$token['contract_address']))
            throw new RuntimeException('invalid_contract');
    }
    $workStarted=hrtime(true);
    $cutoff=(int)floor(microtime(true)*1000);
    if ($sample) {
        $token=$tokens[0];
        $rows=getCandles(['binanceChainId'=>'56','tokenContractAddress'=>$token['contract_address'],
            'bar'=>'1d','limit'=>5],$cfg,$metrics,(string)$token['symbol'],$lockWaitMs);
        $sampleRows=array_slice($rows,0,3);
        finish(['ok'=>true,'action'=>'candle_schema_probe','ticker'=>$ticker,
            'symbol'=>$token['symbol'],'bar'=>'1d','returned_rows'=>count($rows),
            'sample_rows'=>$sampleRows,'sample_column_counts'=>array_map(
                static fn($row)=>is_array($row)?count($row):null,$sampleRows),
            'requests'=>$metrics,'binance_http_requests'=>count($metrics),
            'lock_wait_ms'=>$lockWaitMs,'model_called'=>false,'database_writes'=>0]);
    }
    foreach ($tokens as $token) {
        foreach ($weekly ? ['1d','1w'] : ['1d'] as $bar) {
            $rows=[]; $cursor=$cutoff; $error=null; $pages=0; $pageInfo=[]; $paginationWarning=null;
            $wanted=$bar==='1d'?300:100;
            for ($page=0;$page<($bar==='1d'?3:1);$page++) {
                if ((hrtime(true)-$workStarted)/1e9>150) throw new RuntimeException('test_budget_exceeded');
                try {
                    $batch=getCandles(['binanceChainId'=>'56','tokenContractAddress'=>$token['contract_address'],
                        'bar'=>$bar,'limit'=>100,'after'=>$cursor],$cfg,$metrics,(string)$token['symbol'],$lockWaitMs);
                } catch (RuntimeException $e) {
                    if ($e->getMessage()==='stop_upstream') throw $e;
                    $error='candles_unavailable'; break;
                }
                $pages++;
                if (!$batch) break;
                $oldest=$cursor; $accepted=[]; $times=[];
                foreach ($batch as $row) {
                    if (!is_array($row) || count($row)!==7 || !is_numeric($row[5]??null)) {
                        $error='unexpected_candle_schema'; break 2;
                    }
                    $time=(int)$row[5]; $times[]=$time;
                    if ($time < $cursor) { $oldest=min($oldest,$time); $accepted[]=$row; }
                }
                $pageInfo[]=['page'=>$pages,'requested_before_ms'=>$cursor,'returned'=>count($batch),
                    'accepted_older'=>count($accepted),'oldest_returned_ms'=>$times?min($times):null,
                    'newest_returned_ms'=>$times?max($times):null];
                if (!$accepted) { $paginationWarning='pagination_not_advancing'; break; }
                $rows=array_merge($rows,$accepted);
                $cursor=$oldest;
                if (count($rows)>=$wanted) break;
            }
            $results[]=['ticker'=>$ticker,'provider'=>$token['provider'],'symbol'=>$token['symbol'],
                'contract_address'=>$token['contract_address'],'bar'=>$bar,'pages'=>$pages,'error'=>$error,
                'pagination_warning'=>$paginationWarning,'page_info'=>$pageInfo,
                'current_share_ratio'=>$token['token_to_share_ratio']??$token['multiplier']??null,
                'rows'=>$rows];
        }
    }
    finish(['ok'=>true,'ticker'=>$ticker,'cutoff_ms'=>$cutoff,'results'=>$results,'requests'=>$metrics,
        'binance_http_requests'=>count($metrics),'lock_wait_ms'=>$lockWaitMs,'total_time_ms'=>round((hrtime(true)-$started)/1e6,2),
        'model_called'=>false,'database_writes'=>0]);
} catch (Throwable $e) {
    $safe=['sync_busy_try_again','lock_unavailable','unexpected_token_count','invalid_contract','stop_upstream','test_budget_exceeded'];
    $error=in_array($e->getMessage(),$safe,true)?$e->getMessage():'diagnostic_unavailable';
    finish(['ok'=>false,'error'=>$error,'requests'=>$metrics,'binance_http_requests'=>count($metrics),
        'model_called'=>false,'database_writes'=>0],1);
}
