<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function output(array $body,int $http=200): never {
    http_response_code($http); echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
$db=null; $advisory=false; $budget=null; $result=null; $http=200;
try {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    require '/home/bhub.tm.biz.tr/private/agent/rwa-quote-client.php';
    require '/home/bhub.tm.biz.tr/private/agent/rwa-build-client.php';
    require '/home/bhub.tm.biz.tr/private/agent/rwa-approval-client.php';
    require '/home/bhub.tm.biz.tr/private/agent/rwa-swap-client.php';
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','POST'],true)) output(['ok'=>false,'error'=>'method_not_allowed'],405);
    if ($method==='POST') {
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') output(['ok'=>false,'error'=>'json_required'],415);
        if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE']!=='same-origin') output(['ok'=>false,'error'=>'same_origin_required'],403);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>2048) output(['ok'=>false,'error'=>'request_too_large'],413);
    }
    $db=chatDb(); $session=chatSession($db);
    if ($method==='GET') {
        $rows=$db->query("SELECT underlying_ticker AS ticker,underlying_name,symbol FROM rwa_tokens WHERE chain_id='56' AND is_active=1 AND provider IN ('bstock','ondo','xstocks') AND underlying_ticker IS NOT NULL AND underlying_ticker<>'' ORDER BY underlying_ticker,provider,symbol LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
        $catalogue=[];
        foreach($rows as $row){
            $t=(string)($row['ticker']??''); if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$t))continue;
            if(!isset($catalogue[$t]))$catalogue[$t]=['ticker'=>$t,'name'=>null,'symbols'=>[]];
            $name=trim((string)($row['underlying_name']??'')); if($catalogue[$t]['name']===null&&$name!==''){$clean=preg_replace('/[\s\p{C}]+/u',' ',$name)??$name;$chars=preg_split('//u',$clean,-1,PREG_SPLIT_NO_EMPTY);$catalogue[$t]['name']=is_array($chars)&&count($chars)>80?implode('',array_slice($chars,0,79)).'…':$clean;}
            $symbol=(string)($row['symbol']??''); if($symbol!==''&&strlen($symbol)<=32&&!in_array($symbol,$catalogue[$t]['symbols'],true))$catalogue[$t]['symbols'][]=$symbol;
        }
        output(['ok'=>true,'csrf'=>$session['csrf'],'execution_enabled'=>false,'catalogue'=>array_values($catalogue),'source'=>'saved_active_bsc_catalogue','binance_http_requests'=>0]);
    }
    $raw=file_get_contents('php://input',false,null,0,2049);
    if (!is_string($raw)||strlen($raw)>2048) output(['ok'=>false,'error'=>'request_too_large'],413);
    $input=json_decode($raw,true,16);
    if (!is_array($input)||!is_string($input['csrf']??null)||!hash_equals($session['csrf'],$input['csrf'])) output(['ok'=>false,'error'=>'csrf_failed'],403);
    $ticker=$input['ticker']??null; $amount=$input['amount']??null; $wallet=$input['wallet']??null;
    if (!is_string($ticker)||!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$ticker)
        ||!is_string($amount)||!is_string($wallet)||!preg_match('/\A0x[0-9a-fA-F]{40}\z/',$wallet)
        ||strtolower($wallet)==='0x'.str_repeat('0',40)) output(['ok'=>false,'error'=>'invalid_request'],400);

    $action=$input['action']??'quotes';$selected=$input['provider']??null;$vendor=$input['vendor']??null;
    if(!in_array($action,['quotes','review','approval','swap_check','swap_prepare'],true))output(['ok'=>false,'error'=>'invalid_action'],400);
    if($action!=='quotes' && (!is_string($selected)||!in_array($selected,['bstock','ondo','xstocks'],true)||!is_string($vendor)||!preg_match('/\A[A-Za-z][A-Za-z0-9]{0,30}\z/',$vendor)))output(['ok'=>false,'error'=>'invalid_route'],400);
    try { if(!purchaseDemoAllowed($amount))output(['ok'=>false,'error'=>'purchase_amount_must_be_6_to_10_USDT'],400); } catch (Throwable $e) { output(['ok'=>false,'error'=>'invalid_amount'],400); }
    $q=$db->query("SELECT GET_LOCK('rwa_purchase_quotes',0)");
    if ((int)$q->fetchColumn()!==1) output(['ok'=>false,'error'=>'quotes_busy','error_stage'=>'request_coordination'],409);
    $advisory=true;
    $budget=fopen('/home/bhub.tm.biz.tr/private/agent/quote-runtime/budget.json','c+');
    if (!is_resource($budget)||!flock($budget,LOCK_EX|LOCK_NB)) throw new RuntimeException('quote_runtime_unavailable');
    $state=json_decode(stream_get_contents($budget,2048)?:'{}',true);
    $last=(float)($state['last_request']??0); $now=microtime(true);
    $minimum=$action==='quotes'?10:3;
    if ($now-$last<$minimum) { $result=['ok'=>false,'error'=>'please_wait','error_stage'=>'request_pacing','retry_after_seconds'=>(int)ceil($minimum-($now-$last))]; $http=429; }
    else {
        rewind($budget); ftruncate($budget,0); fwrite($budget,json_encode(['last_request'=>$now])); fflush($budget);
        $result=match($action){'swap_prepare'=>swapReport($ticker,$selected,$amount,$wallet,$vendor),'review'=>buildReport($ticker,$selected,$amount,$wallet,$vendor),'approval','swap_check'=>approvalReport($action,$ticker,$selected,$amount,$wallet,$vendor),default=>quoteReport($ticker,$amount,$wallet)};
        $result['execution_enabled']=$action==='swap_prepare'&&($result['execution_enabled']??false);
        if (!$result['ok']) {
            if (($result['error']??null)==='sync_busy_try_again') {
                rewind($budget); ftruncate($budget,0); fwrite($budget,json_encode(['last_request'=>$last])); fflush($budget);
                $http=409;
            } else $http=503;
        }
    }
} catch (Throwable $e) {
    error_log('[rwa-quote-api] quote_service_unavailable');
    $result=['ok'=>false,'error'=>'quote_service_unavailable','error_stage'=>'service']; $http=503;
} finally {
    if (is_resource($budget)) { flock($budget,LOCK_UN); fclose($budget); }
    if ($advisory && $db instanceof PDO) $db->query("SELECT RELEASE_LOCK('rwa_purchase_quotes')");
}
output($result??['ok'=>false,'error'=>'quote_service_unavailable','error_stage'=>'service'],$http);
