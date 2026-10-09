<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__, 2).'/rwa-build-client.php';

// Exact decimal conversion without PHP float, GMP or BCMath dependencies.
function preflightHex(string $decimal):string {
    if(!preg_match('/\A[0-9]{1,78}\z/',$decimal))throw new RuntimeException('invalid_integer');
    $decimal=ltrim($decimal,'0')?:'0';$hex='';
    while($decimal!=='0'){
        $quotient='';$remainder=0;
        foreach(str_split($decimal) as $digit){$v=$remainder*10+(int)$digit;$q=intdiv($v,16);$remainder=$v%16;if($quotient!==''||$q!==0)$quotient.=(string)$q;}
        $hex='0123456789abcdef'[$remainder].$hex;$decimal=$quotient!==''?$quotient:'0';
    }
    $hex=$hex!==''?$hex:'0';if(strlen($hex)>64)throw new RuntimeException('integer_overflow');return str_pad($hex,64,'0',STR_PAD_LEFT);
}
function preflightApproval(array $entry,string $spender,string $amount):array {
    $expected='0x095ea7b3'.str_pad(strtolower(substr($spender,2)),64,'0',STR_PAD_LEFT).preflightHex($amount);
    $verified=addressValid($spender)
        &&strtolower((string)($entry['dexContractAddress']??''))===strtolower($spender)
        &&is_string($entry['data']??null)&&strtolower($entry['data'])===$expected;
    return ['verified'=>$verified,'selector'=>'0x095ea7b3','spender'=>$spender,'amount_raw'=>$amount,
        'unlimited_approval'=>false,'calldata_bytes'=>68];
}
function preflightRequest(string $stage,string $method,string $endpoint,array $payload,array $cfg,array &$requests):array {
    $allowed=['GET /api/v1/dex/aggregator/approve-transaction','POST /api/v1/dex/pre-transaction/simulate'];
    if(!in_array($method.' '.$endpoint,$allowed,true))throw new RuntimeException('unsupported_diagnostic_endpoint');
    usleep(1300000);
    $path='/build'.$endpoint;
    $body='';if($method==='GET')$path.='?'.http_build_query($payload,'','&',PHP_QUERY_RFC3986);
    else $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $time=stamp();$signature=base64_encode(hash_hmac('sha256',$time.$method.$path.$body,(string)$cfg['binance_web3_secret_key'],true));
    $headers=[];$response='';$oversize=false;$ch=curl_init('https://web3.binance.com'.$path);
    curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','X-OC-APIKEY: '.$cfg['binance_web3_api_key'],
            'X-OC-TIMESTAMP: '.$time,'X-OC-SIGN: '.$signature,'X-OC-RECV-WINDOW: 60000'],
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers):int{$p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);},
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$response,&$oversize):int{if(strlen($response)+strlen($chunk)>1048576){$oversize=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
    if($method==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $start=hrtime(true);$transport=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $json=json_decode($response,true,512,JSON_BIGINT_AS_STRING);$code=is_array($json)?(string)($json['code']??''):'';
    $metric=['stage'=>$stage,'request_utc'=>$time,'http'=>$http,'business_code'=>$code!==''?$code:null,
        'time_ms'=>round((hrtime(true)-$start)/1e6,2),'weight'=>$headers['x-oc-used-weight']??null,
        'remaining'=>$headers['x-oc-ratelimit-remaining']??null,'rate_limit'=>$headers['x-oc-ratelimit-limit']??null];
    $requests[]=$metric;error_log('[rwa-preflight-request] '.json_encode($metric,JSON_UNESCAPED_SLASHES));
    $status=classify($http,$code);if($transport===false||$oversize)$status=$oversize?'response_too_large':'transport_error';
    elseif($http===200&&$code==='0'&&is_array($json['data']??null))$status='received';
    elseif(!is_array($json))$status='invalid_response';
    $stop=in_array($status,['rate_limited','authentication_or_permission','access_or_compliance_denied'],true)
        ||(is_numeric($metric['remaining'])&&(int)$metric['remaining']<1);
    return ['status'=>$status,'http_status'=>$http,'business_code'=>$code!==''?$code:null,'stop'=>$stop,
        'message'=>safeText(is_array($json)?($json['message']??$json['msg']??null):null,$cfg),'data'=>$status==='received'?$json['data']:[]];
}
function preflightProjection(array $reply,array $cfg):array {
    $data=$reply['data'];$project=['transport_status'=>$reply['status'],'http_status'=>$reply['http_status'],
        'business_code'=>$reply['business_code'],'message'=>$reply['message'],'response_fields'=>array_keys($data),
        'simulation_status'=>safeText($data['status']??null,$cfg),'fail_reason'=>safeText($data['failReason']??null,$cfg)];
    foreach(['balanceChanges'=>['contractAddress','tokenType','change','owner'],'allowanceChanges'=>['tokenAddress','owner','spender','preAmount','postAmount']] as $field=>$keys){
        $project[$field]=[];
        if(is_array($data[$field]??null))foreach(array_slice($data[$field],0,100) as $row){if(!is_array($row))continue;$safe=[];foreach($keys as $key)$safe[$key]=safeText($row[$key]??null,$cfg);$project[$field][]=$safe;}
        $project[$field.'_truncated']=is_array($data[$field]??null)&&count($data[$field])>100;
    }
    return $project;
}
if(in_array('--self-test',$argv,true)){
    $spender='0x'.str_repeat('2',40);$raw='100000000000000000000';$data='0x095ea7b3'.str_repeat('0',24).substr($spender,2).'0000000000000000000000000000000000000000000000056bc75e2d63100000';
    $good=preflightApproval(['data'=>$data,'dexContractAddress'=>$spender],$spender,$raw);
    $wrong=preflightApproval(['data'=>$data,'dexContractAddress'=>$spender],$spender,'99999999999999999999');
    $unlimited=preflightApproval(['data'=>substr($data,0,74).str_repeat('f',64),'dexContractAddress'=>$spender],$spender,$raw);
    $trailing=preflightApproval(['data'=>$data.'00','dexContractAddress'=>$spender],$spender,$raw);
    $wrongSpender=preflightApproval(['data'=>$data,'dexContractAddress'=>'0x'.str_repeat('3',40)],$spender,$raw);
    $ok=$good['verified']&&!$wrong['verified']&&!$unlimited['verified']&&!$trailing['verified']&&!$wrongSpender['verified'];
    echo json_encode(['ok'=>$ok,'test'=>'exact_approval_calldata_validation','api_requests'=>0,'wallet_signatures'=>0]).PHP_EOL;exit($ok?0:1);
}
$ticker='AMZN';$provider='ondo';$amount='100';$wallet='';$vendor='LiquidMesh';
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--ticker='))$ticker=strtoupper(substr($arg,9));
    elseif(str_starts_with($arg,'--provider='))$provider=substr($arg,11);
    elseif(str_starts_with($arg,'--amount='))$amount=substr($arg,9);
    elseif(str_starts_with($arg,'--wallet='))$wallet=substr($arg,9);
    elseif(str_starts_with($arg,'--vendor='))$vendor=substr($arg,9);
    else{echo '{"ok":false,"error":"invalid_arguments"}'.PHP_EOL;exit(1);}
}
if(!addressValid($wallet)||strtolower($wallet)==='0x'.str_repeat('0',40)){echo '{"ok":false,"error":"explicit_wallet_required"}'.PHP_EOL;exit(1);}
$details=['approval'=>null,'approval_simulation'=>null,'swap_simulation'=>null,'diagnostic_error'=>null];
$report=buildReport($ticker,$provider,$amount,$wallet,$vendor,
    static function(array $tx,array $route,array $router,mixed $mode,array $warnings,array $cfg,array &$requests)use(&$details,$amount,$wallet,$vendor):void{
        if($mode!=='SWAP'||$warnings!==[]){$details['diagnostic_error']='build_metadata_not_verified_for_swap';return;}
        $raw=units($amount);$spender=$route['approveTarget']??null;$usdt='0x55d398326f99059fF775485246999027B3197955';
        if(!addressValid($spender)){$details['diagnostic_error']='approval_spender_missing';return;}
        $approval=preflightRequest('approval_build','GET','/api/v1/dex/aggregator/approve-transaction',
            ['binanceChainId'=>'56','tokenContractAddress'=>$usdt,'approveAmount'=>$raw,'vendor'=>$vendor],$cfg,$requests);
        if($approval['status']!=='received'){$details['approval']=['status'=>$approval['status'],'http_status'=>$approval['http_status'],'business_code'=>$approval['business_code'],'message'=>$approval['message']];$details['diagnostic_error']='approval_build_unavailable';return;}
        if(count($approval['data'])!==1||!is_array($approval['data'][0]??null)){$details['diagnostic_error']='approval_response_shape_unverified';return;}
        $entry=$approval['data'][0];$details['approval']=preflightApproval($entry,$spender,$raw);
        $details['approval']['gas_limit']=safeText($entry['gasLimit']??null,$cfg);$details['approval']['gas_price']=safeText($entry['gasPrice']??null,$cfg);
        if(!$details['approval']['verified']){$details['diagnostic_error']='approval_calldata_mismatch';return;}
        if($approval['stop']){$details['diagnostic_error']='upstream_stop_before_simulation';return;}
        $a=preflightRequest('approval_simulation','POST','/api/v1/dex/pre-transaction/simulate',
            ['binanceChainId'=>'56','evmTx'=>['from'=>$wallet,'to'=>$usdt,'value'=>'0','data'=>$entry['data']]],$cfg,$requests);
        $details['approval_simulation']=preflightProjection($a,$cfg);
        if($a['stop']){$details['diagnostic_error']='upstream_stop_before_swap_simulation';return;}
        $s=preflightRequest('swap_simulation','POST','/api/v1/dex/pre-transaction/simulate',
            ['binanceChainId'=>'56','evmTx'=>['from'=>$wallet,'to'=>$tx['to'],'value'=>'0','data'=>$tx['data']]],$cfg,$requests);
        $details['swap_simulation']=preflightProjection($s,$cfg);
    });
$report['action']='rwa_preflight_probe';$report['diagnostics']=$details;$report['execution_enabled']=false;
$report['interpretation']='Independent off-chain simulations only. The approval simulation does not create on-chain allowance and does not carry state into the swap simulation. A swap may revert with insufficient current allowance. Simulation receipt or SUCCESS is not a settlement or safety guarantee. No financial transaction has been sent.';
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($report['ok']&&$details['diagnostic_error']===null?0:1);
