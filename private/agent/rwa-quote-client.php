<?php
declare(strict_types=1);
function stamp(): string {
    $t=microtime(true); $s=(int)$t;
    return gmdate('Y-m-d\TH:i:s',$s).sprintf('.%03dZ',(int)(($t-$s)*1000));
}
function units(string $amount): string {
    if (!preg_match('/\A(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,18}))?\z/',$amount,$m))
        throw new RuntimeException('invalid_amount_use_positive_USDT_below_10000');
    $raw=ltrim($m[1].str_pad($m[2]??'',18,'0'),'0');
    if ($raw==='') throw new RuntimeException('amount_must_be_positive');
    return $raw;
}
function humanUnits(mixed $raw, mixed $decimals): ?string {
    if (!is_string($raw) || !preg_match('/\A[0-9]{1,100}\z/',$raw)
        || !is_numeric($decimals) || (string)(int)$decimals!==(string)$decimals
        || (int)$decimals<0 || (int)$decimals>36) return null;
    $d=(int)$decimals; $raw=ltrim($raw,'0') ?: '0';
    if ($d===0) return $raw;
    $p=str_pad($raw,$d+1,'0',STR_PAD_LEFT);
    $fraction=rtrim(substr($p,-$d),'0');
    return substr($p,0,-$d).($fraction!==''?'.'.$fraction:'');
}
function safeText(mixed $value, array $cfg): ?string {
    if (!is_scalar($value)) return null;
    $text=str_replace([(string)$cfg['binance_web3_api_key'],(string)$cfg['binance_web3_secret_key']],
        ['[REDACTED]','[REDACTED]'],(string)$value);
    return substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$text)??'',0,400);
}
function classify(int $http, string $code): string {
    if ($http===429 || $code==='42900') return 'rate_limited';
    if ($http===401 || in_array($code,['40101','40102','40103','40104'],true)) return 'authentication_or_permission';
    if ($http===403 || preg_match('/\A403(?:0[1-4]|1[1-4])\z/',$code)) return 'access_or_compliance_denied';
    if (in_array($code,['40367','40369'],true)) return 'market_not_tradable';
    if (in_array($code,['40421','40441','40374'],true)) return 'quote_liquidity_unavailable';
    if (in_array($code,['40365','40368','40370'],true)) return 'pair_not_supported';
    if (in_array($code,['40366','40375'],true)) return 'amount_outside_limits';
    return 'quote_unavailable';
}
function requestQuote(array $query,array $cfg,array &$requests,string $provider,string $symbol): array {
    static $ended=0.0;
    $wait=1.3-(microtime(true)-$ended); if ($wait>0) usleep((int)ceil($wait*1e6));
    $path='/build/api/v1/dex/aggregator/quote?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $time=stamp(); $signature=base64_encode(hash_hmac('sha256',$time.'GET'.$path,
        (string)$cfg['binance_web3_secret_key'],true));
    $headers=[]; $body=''; $oversize=false;
    $ch=curl_init('https://web3.binance.com'.$path);
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-OC-APIKEY: '.$cfg['binance_web3_api_key'],
            'X-OC-TIMESTAMP: '.$time,'X-OC-SIGN: '.$signature,'X-OC-RECV-WINDOW: 60000'],
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$headers): int {
            $p=explode(':',$line,2); if (count($p)===2) $headers[strtolower(trim($p[0]))]=trim($p[1]);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$body,&$oversize): int {
            if (strlen($body)+strlen($chunk)>1048576) { $oversize=true; return 0; }
            $body.=$chunk; return strlen($chunk);
        }]);
    $start=hrtime(true); $transport=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); $ended=microtime(true);
    $json=json_decode($body,true,512,JSON_BIGINT_AS_STRING);
    $code=is_array($json)?(string)($json['code']??''):'';
    $metric=['request_utc'=>$time,'provider'=>$provider,'symbol'=>$symbol,'http'=>$http,
        'business_code'=>$code!==''?$code:null,'time_ms'=>round((hrtime(true)-$start)/1e6,2),
        'weight'=>$headers['x-oc-used-weight']??null,'remaining'=>$headers['x-oc-ratelimit-remaining']??null,
        'rate_limit'=>$headers['x-oc-ratelimit-limit']??null];
    $requests[]=$metric;
    error_log('[rwa-quote-request] '.json_encode($metric,JSON_UNESCAPED_SLASHES));
    $status=classify($http,$code);
    $stop=in_array($status,['rate_limited','authentication_or_permission','access_or_compliance_denied'],true)
        || (isset($metric['remaining']) && is_numeric($metric['remaining']) && (int)$metric['remaining']<1);
    if ($transport===false || $oversize) $status=$oversize?'response_too_large':'transport_error';
    elseif ($http===200 && $code==='0' && is_array($json['data']??null)) $status='received';
    elseif (!is_array($json)) $status='invalid_response';
    return ['status'=>$status,'http_status'=>$http,'business_code'=>$code!==''?$code:null,
        'message'=>safeText(is_array($json)?($json['message']??$json['msg']??null):null,$cfg),
        'data'=>$status==='received'?$json['data']:[], 'stop'=>$stop];
}

function quoteReport(string $ticker,string $amount,string $wallet): array {
$requests=[]; $results=[]; $lock=null; $pdo=null; $error=null; $lockWait=null;
$start=hrtime(true); $usdt='0x55d398326f99059fF775485246999027B3197955';
try {
    $rawAmount=units($amount);
    $cfg=require '/home/bhub.tm.biz.tr/private/config.php';
    if (!is_array($cfg) || empty($cfg['binance_web3_api_key']) || empty($cfg['binance_web3_secret_key']))
        throw new RuntimeException('config_unavailable');
    $wallet=$wallet??($cfg['evm_wallet']??null);
    if (!is_string($wallet) || !preg_match('/\A0x[0-9a-fA-F]{40}\z/',$wallet)
        || strtolower($wallet)==='0x'.str_repeat('0',40)) throw new RuntimeException('valid_test_wallet_required');
    $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'').';charset=utf8mb4',
        (string)($cfg['db_user']??''),(string)($cfg['db_password']??$cfg['db_pass']??''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
    $pdo->exec('SET SESSION max_statement_time = 2');
    $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT provider,symbol,contract_address FROM rwa_tokens
        WHERE chain_id='56' AND is_active=1 AND underlying_ticker=:ticker
        AND provider IN ('bstock','ondo','xstocks') ORDER BY provider,symbol LIMIT 4");
    $q->execute(['ticker'=>$ticker]); $tokens=$q->fetchAll(); $pdo->commit();
    $byProvider=[];
    foreach ($tokens as $token) {
        if (isset($byProvider[$token['provider']])) throw new RuntimeException('ambiguous_provider_token');
        if (!preg_match('/\A0x[0-9a-fA-F]{40}\z/',(string)$token['contract_address']))
            throw new RuntimeException('invalid_catalogue_contract');
        $byProvider[$token['provider']]=$token;
    }
    if (!$byProvider) throw new RuntimeException('ticker_not_in_catalogue');
    $lock=fopen('/run/lock/bnb-rwa-all-sync.lock','r');
    if ($lock===false) throw new RuntimeException('lock_unavailable');
    $waitStart=hrtime(true); $deadline=microtime(true)+3.0;
    while (!flock($lock,LOCK_EX|LOCK_NB)) {
        if (microtime(true)>=$deadline) throw new RuntimeException('sync_busy_try_again');
        usleep(100000);
    }
    $lockWait=round((hrtime(true)-$waitStart)/1e6,2); $stopped=false;
    foreach (['bstock','ondo','xstocks'] as $provider) {
        if (!isset($byProvider[$provider])) { $results[]=['provider'=>$provider,'status'=>'not_in_catalogue']; continue; }
        $token=$byProvider[$provider]; $base=['provider'=>$provider,'symbol'=>$token['symbol'],
            'contract_address'=>$token['contract_address']];
        if ($stopped) { $results[]=$base+['status'=>'skipped_after_upstream_stop']; continue; }
        $out=requestQuote(['binanceChainId'=>'56','amount'=>$rawAmount,'fromTokenAddress'=>$usdt,
            'toTokenAddress'=>$token['contract_address'],'userWalletAddress'=>$wallet],$cfg,$requests,$provider,(string)$token['symbol']);
        $routes=[];
        foreach (array_slice($out['data'],0,20) as $route) {
            if (!is_array($route)) continue;
            $from=is_array($route['fromToken']??null)?$route['fromToken']:[];
            $to=is_array($route['toToken']??null)?$route['toToken']:[];
            $warnings=[]; $mode=$route['executionMode']??null;
            if (!in_array($mode,['SWAP','RFQ'],true)) $warnings[]='unknown_execution_mode';
            if (strtolower((string)($from['tokenContractAddress']??''))!==strtolower($usdt)) $warnings[]='input_token_not_verified';
            if (strtolower((string)($to['tokenContractAddress']??''))!==strtolower($token['contract_address'])) $warnings[]='output_token_not_verified';
            if ((string)($from['decimal']??'')!=='18') $warnings[]='input_decimals_not_verified';
            if ((string)($route['fromTokenAmount']??'')!==$rawAmount) $warnings[]='input_amount_not_verified';
            if ((string)($route['binanceChainId']??'')!=='56') $warnings[]='chain_not_verified';
            $received=humanUnits($route['toTokenAmount']??null,$to['decimal']??null);
            if ($received===null || !preg_match('/[1-9]/',$received)) $warnings[]='positive_output_amount_not_verified';
            if (empty($route['quoteId'])) $warnings[]='quote_id_missing';
            $routes[]=['vendor'=>safeText($route['vendorName']??null,$cfg),
                'execution_mode'=>safeText($mode,$cfg),'quote_id'=>safeText($route['quoteId']??null,$cfg),
                'output_amount_raw'=>safeText($route['toTokenAmount']??null,$cfg),
                'output_decimals'=>$to['decimal']??null,'output_amount'=>$received,
                'trade_fee_estimate_usd'=>safeText($route['tradeFee']??null,$cfg),
                'gas_fee_estimate_wei'=>safeText($route['estimateGasFee']??null,$cfg),
                'price_impact_percent'=>safeText($route['priceImpactPercent']??null,$cfg),
                'approve_target'=>safeText($route['approveTarget']??null,$cfg),
                'api_is_best'=>$route['isBest']??null,'validation_warnings'=>$warnings,
                'diagnostic_fields_verified'=>$warnings===[]];
        }
        $results[]=$base+['status'=>$out['status']==='received'?($routes?'quotes_received':'no_routes'):$out['status'],
            'http_status'=>$out['http_status'],'business_code'=>$out['business_code'],
            'message'=>$out['message'],'routes'=>$routes];
        $stopped=$out['stop'];
    }
} catch (Throwable $e) {
    $known=['invalid_amount_use_positive_USDT_below_10000','amount_must_be_positive','config_unavailable',
        'valid_test_wallet_required','ambiguous_provider_token','invalid_catalogue_contract',
        'ticker_not_in_catalogue','lock_unavailable','sync_busy_try_again'];
    $error=in_array($e->getMessage(),$known,true)?$e->getMessage():'probe_failed';
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
}
$weight=0; $complete=true;
foreach ($requests as $r) {
    if (!is_string($r['weight']) || !ctype_digit($r['weight'])) $complete=false;
    else $weight+=(int)$r['weight'];
}
return ['ok'=>$error===null,'action'=>'rwa_quote_probe','error'=>$error,'ticker'=>$ticker,
    'chain_id'=>'56','input_token'=>'USDT','input_amount'=>$amount,'input_decimals'=>18,
    'input_amount_raw'=>$rawAmount??null,'wallet_address'=>$wallet,'checked_at_utc'=>stamp(),
    'providers'=>$results,'requests'=>$requests,'binance_http_requests'=>count($requests),
    'observed_weight_sum'=>$complete?$weight:null,'weight_headers_complete'=>$complete,
    'lock_wait_ms'=>$lockWait,'total_time_ms'=>round((hrtime(true)-$start)/1e6,2),
    'model_called'=>false,'database_writes'=>0,'wallet_signatures'=>0,'approval_transactions'=>0,
    'orders_submitted'=>0,'payment_attempted'=>false,
    'interpretation'=>'Quotes are temporary API estimates, not confirmed executable trades. Token quantities are not directly comparable across different token-to-share ratios.'];
}
