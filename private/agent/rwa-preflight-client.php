<?php
declare(strict_types=1);
require_once __DIR__.'/rwa-build-client.php';
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
