<?php
declare(strict_types=1);
require_once __DIR__.'/rwa-quote-client.php';
function requestBuild(array $query,array $cfg,array &$requests,string $provider,string $symbol): array {
    static $ended=0.0;
    $wait=1.3-(microtime(true)-$ended); if ($wait>0) usleep((int)ceil($wait*1e6));
    $path='/build/api/v1/dex/aggregator/swap?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
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
    $metric=['stage'=>'build','request_utc'=>$time,'provider'=>$provider,'symbol'=>$symbol,'http'=>$http,
        'business_code'=>$code!==''?$code:null,'time_ms'=>round((hrtime(true)-$start)/1e6,2),
        'weight'=>$headers['x-oc-used-weight']??null,'remaining'=>$headers['x-oc-ratelimit-remaining']??null,
        'rate_limit'=>$headers['x-oc-ratelimit-limit']??null];
    $requests[]=$metric;
    error_log('[rwa-build-request] '.json_encode($metric,JSON_UNESCAPED_SLASHES));
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
function addressValid(mixed $a): bool { return is_string($a)&&preg_match('/\A0x[0-9a-fA-F]{40}\z/',$a)===1; }

function buildReport(string $ticker,string $provider,string $amount,string $wallet,string $vendor,?callable $diagnostic=null):array {
$start=hrtime(true);$requests=[];$lock=null;$db=null;$error=null;$preview=null;$api=null;$failureStage='setup';$lockWait=null;
try {
    if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$ticker)||!in_array($provider,['bstock','ondo','xstocks'],true)
        ||!preg_match('/\A[A-Za-z][A-Za-z0-9]{0,30}\z/',$vendor))throw new RuntimeException('invalid_arguments');
    $raw=units($amount);$cfg=require '/home/bhub.tm.biz.tr/private/config.php';
    if(!is_array($cfg)||empty($cfg['binance_web3_api_key'])||empty($cfg['binance_web3_secret_key']))throw new RuntimeException('config_unavailable');
    $wallet=$wallet??($cfg['evm_wallet']??null);
    if(!addressValid($wallet)||strtolower($wallet)==='0x'.str_repeat('0',40))throw new RuntimeException('valid_test_wallet_required');
    $failureStage='catalogue';
    $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.($cfg['db_name']??'').';charset=utf8mb4',
        (string)($cfg['db_user']??''),(string)($cfg['db_password']??$cfg['db_pass']??''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
    $db->exec('SET SESSION max_statement_time = 2');$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    $q=$db->prepare("SELECT symbol,contract_address FROM rwa_tokens WHERE chain_id='56' AND is_active=1 AND underlying_ticker=? AND provider=? LIMIT 2");
    $q->execute([$ticker,$provider]);$rows=$q->fetchAll();$db->commit();
    if(count($rows)!==1||!addressValid($rows[0]['contract_address']))throw new RuntimeException('provider_token_unavailable_or_ambiguous');
    $token=$rows[0];$usdt='0x55d398326f99059fF775485246999027B3197955';
    $failureStage='api_coordination';
    $lock=fopen('/run/lock/bnb-rwa-all-sync.lock','r');
    if(!is_resource($lock))throw new RuntimeException('lock_unavailable');
    $waitStart=hrtime(true);$deadline=microtime(true)+3.0;
    while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>=$deadline)throw new RuntimeException('sync_busy_try_again');usleep(100000);}
    $lockWait=round((hrtime(true)-$waitStart)/1e6,2);
    $query=['binanceChainId'=>'56','amount'=>$raw,'fromTokenAddress'=>$usdt,'toTokenAddress'=>$token['contract_address'],'userWalletAddress'=>$wallet];
    $failureStage='quote';$quotedAt=microtime(true);$quote=requestQuote($query,$cfg,$requests,$provider,$token['symbol']);
    if($quote['status']!=='received'){$api=$quote;unset($api['data']);throw new RuntimeException('quote_unavailable');}
    if($quote['stop'])throw new RuntimeException('upstream_stop_before_build');
    $failureStage='quote_validation';
    $route=null;
    foreach($quote['data'] as $candidate){if(is_array($candidate)&&($candidate['vendorName']??null)===$vendor){$route=$candidate;break;}}
    if(!$route)throw new RuntimeException('selected_vendor_not_available');
    if(!in_array($route['executionMode']??null,['SWAP','RFQ'],true)
        ||(string)($route['binanceChainId']??'')!=='56'
        ||strtolower((string)($route['fromToken']['tokenContractAddress']??''))!==strtolower($usdt)
        ||strtolower((string)($route['toToken']['tokenContractAddress']??''))!==strtolower($token['contract_address'])
        ||(string)($route['fromTokenAmount']??'')!==$raw||(string)($route['fromToken']['decimal']??'')!=='18'
        ||!is_string($route['quoteId']??null)||!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/',$route['quoteId']))throw new RuntimeException('quote_metadata_not_verified');
    if(microtime(true)-$quotedAt>20){$failureStage='quote_freshness';throw new RuntimeException('fresh_quote_required');}
    usleep(1300000);
    $failureStage='build';
    $build=requestBuild($query+['quoteId'=>$route['quoteId'],'slippagePercent'=>'0.5','autoSlippage'=>'false',
        'approveTransaction'=>'false','priceImpactProtectionPercent'=>'1'],$cfg,$requests,$provider,$token['symbol']);
    if($build['status']!=='received'){$api=$build;unset($api['data']);throw new RuntimeException('build_unavailable');}
    $failureStage='build_validation';
    $data=$build['data'];$mode=$data['executionMode']??null;$router=is_array($data['routerResult']??null)?$data['routerResult']:[];
    $warnings=[];
    if($mode!==$route['executionMode'])$warnings[]='execution_mode_changed_or_missing';
    if((string)($router['binanceChainId']??'')!=='56')$warnings[]='build_chain_not_verified';
    if((string)($router['fromTokenAmount']??'')!==$raw)$warnings[]='build_input_amount_not_verified';
    if(($router['vendorName']??null)!==$vendor)$warnings[]='build_vendor_not_verified';
    if(strtolower((string)($router['fromToken']['tokenContractAddress']??''))!==strtolower($usdt))$warnings[]='build_input_token_not_verified';
    if(strtolower((string)($router['toToken']['tokenContractAddress']??''))!==strtolower($token['contract_address']))$warnings[]='build_output_token_not_verified';
    $tx=is_array($data['tx']??null)?$data['tx']:[];$rfq=is_array($data['rfq']??null)?$data['rfq']:[];
    $calldata=$tx['data']??null;$hasData=is_string($calldata)&&preg_match('/\A0x(?:[0-9a-fA-F]{2}){4,}\z/',$calldata)===1;
    if($mode==='SWAP'){
        if(strtolower((string)($tx['from']??''))!==strtolower($wallet))$warnings[]='transaction_sender_not_verified';
        if(!addressValid($tx['to']??null))$warnings[]='transaction_destination_missing_or_invalid';
        if(!$hasData)$warnings[]='transaction_calldata_missing_or_invalid';
        if(!isset($tx['value'])||!in_array((string)$tx['value'],['0','0x0','0x00'],true))$warnings[]='zero_native_value_not_verified';
    }elseif($mode==='RFQ'){
        if(empty($rfq['typedDataToSign']))$warnings[]='rfq_typed_data_missing';
    }else $warnings[]='unsupported_execution_mode';
    if(!addressValid($route['approveTarget']??null))$warnings[]='approval_spender_not_verified';
    $txSummary=[];
    foreach(['from','to','value','gas','gasPrice','maxFeePerGas','maxPriorityFeePerGas','minReceiveAmount','slippagePercent'] as $key)$txSummary[$key]=safeText($tx[$key]??null,$cfg);
    $txSummary['data_bytes']=$hasData?(strlen($calldata)-2)/2:null;
    $txSummary['method_selector']=$hasData?substr($calldata,0,10):null;
    $txSummary['calldata_sha256']=$hasData?hash('sha256',$calldata):null;
    if($diagnostic!==null){$failureStage='execution_validation';$diagnostic($tx,$route,$router,$mode,$warnings,$cfg,$requests);}
    $preview=['symbol'=>$token['symbol'],'contract_address'=>$token['contract_address'],'vendor'=>$vendor,
        'approve_target'=>safeText($route['approveTarget']??null,$cfg),'output_decimals'=>$router['toToken']['decimal']??null,'build_output_amount_raw'=>safeText($router['toTokenAmount']??null,$cfg),'quote_execution_mode'=>$route['executionMode'],'build_execution_mode'=>safeText($mode,$cfg),
        'build_top_level_fields'=>array_keys($data),'router_fields'=>array_keys($router),
        'quote_output_amount'=>humanUnits($route['toTokenAmount']??null,$route['toToken']['decimal']??null),
        'minimum_output_amount'=>humanUnits($tx['minReceiveAmount']??null,$router['toToken']['decimal']??null),'build_output_amount'=>humanUnits($router['toTokenAmount']??null,$router['toToken']['decimal']??null),
        'transaction_summary'=>$txSummary,'rfq_fields'=>array_keys($rfq),
        'typed_data_present'=>!empty($rfq['typedDataToSign']),'metadata_warnings'=>$warnings,
        'requires_further_execution_validation'=>true];
    $failureStage=null;
} catch(Throwable $e){
    $known=['invalid_arguments','config_unavailable','valid_test_wallet_required','provider_token_unavailable_or_ambiguous',
        'lock_unavailable','sync_busy_try_again','quote_unavailable','upstream_stop_before_build','selected_vendor_not_available',
        'quote_metadata_not_verified','fresh_quote_required','build_unavailable','invalid_amount_use_positive_USDT_below_10000','amount_must_be_positive'];
    $error=in_array($e->getMessage(),$known,true)?$e->getMessage():'build_probe_failed';
}finally{if($db instanceof PDO&&$db->inTransaction())$db->rollBack();if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
$weight=0;$complete=true;foreach($requests as $r){if(!is_string($r['weight'])||!ctype_digit($r['weight']))$complete=false;else$weight+=(int)$r['weight'];}
return ['ok'=>$error===null,'action'=>'rwa_purchase_review','error'=>$error,'ticker'=>$ticker,'provider'=>$provider,
    'input_amount_usdt'=>$amount,'wallet_address'=>$wallet,'slippage_percent'=>'0.5','price_impact_protection_percent'=>'1',
    'preview'=>$preview,'api_error'=>$api,'error_stage'=>$error===null?null:$failureStage,'requests'=>$requests,'binance_http_requests'=>count($requests),
    'observed_weight_sum'=>$complete?$weight:null,'weight_headers_complete'=>$complete,'lock_wait_ms'=>$lockWait,'total_time_ms'=>round((hrtime(true)-$start)/1e6,2),
    'checked_at_utc'=>stamp(),'model_called'=>false,'database_writes'=>0,'wallet_signatures'=>0,
    'approval_transactions'=>0,'orders_submitted'=>0,'payment_attempted'=>false,
    'interpretation'=>'Unsigned build diagnostic only. No calldata or typed data is supplied for execution. Successful receipt does not prove transaction safety, allowance, balance, simulation success or settlement.'];
}
