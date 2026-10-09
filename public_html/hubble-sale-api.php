<?php
declare(strict_types=1);
ini_set('display_errors','0');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
function saleExecOutput(array $r,int $code=200):never{http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true))saleExecOutput(['ok'=>false,'error'=>'method_not_allowed'],405);
{
 $db=null;$locked=false;$budget=null;
 try{
  if($method==='POST'){
   if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')saleExecOutput(['ok'=>false,'error'=>'json_required'],415);
   if(isset($_SERVER['HTTP_SEC_FETCH_SITE'])&&$_SERVER['HTTP_SEC_FETCH_SITE']!=='same-origin')saleExecOutput(['ok'=>false,'error'=>'same_origin_required'],403);
   if((int)($_SERVER['CONTENT_LENGTH']??0)>2048)saleExecOutput(['ok'=>false,'error'=>'request_too_large'],413);
  }
  require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';require '/home/bhub.tm.biz.tr/private/agent/hubble-sale-client.php';require '/home/bhub.tm.biz.tr/private/agent/hubble-sale-approval-client.php';$db=chatDb();$session=chatSession($db);
  if($method==='GET')saleExecOutput(['ok'=>true,'csrf'=>$session['csrf'],'catalogue'=>hubbleSaleCatalogue($db),'source'=>'saved_active_bsc_catalogue','binance_http_requests'=>0]);
  $raw=file_get_contents('php://input',false,null,0,2049);$input=is_string($raw)&&strlen($raw)<=2048?json_decode($raw,true,8):null;
  if(!is_array($input)||!is_string($input['csrf']??null)||!hash_equals($session['csrf'],$input['csrf']))saleExecOutput(['ok'=>false,'error'=>'csrf_failed'],403);
  if(!is_string($input['wallet']??null)||!addressValid($input['wallet']))saleExecOutput(['ok'=>false,'error'=>'invalid_wallet'],400);
  foreach(['ticker','provider','amount','action'] as $key)if(!is_string($input[$key]??null))saleExecOutput(['ok'=>false,'error'=>'invalid_sale_selection'],400);
  if(!in_array($input['action'],['sale','approval'],true))saleExecOutput(['ok'=>false,'error'=>'invalid_action'],400);
  try{hubbleSaleSelection($db,$input['ticker'],$input['provider'],$input['amount']);}catch(Throwable){saleExecOutput(['ok'=>false,'error'=>'invalid_sale_selection_or_amount'],400);}
  if((int)$db->query("SELECT GET_LOCK('rwa_purchase_quotes',0)")->fetchColumn()!==1)saleExecOutput(['ok'=>false,'error'=>'quotes_busy'],409);$locked=true;
  $budget=fopen('/home/bhub.tm.biz.tr/private/agent/quote-runtime/budget.json','c+');if(!is_resource($budget)||!flock($budget,LOCK_EX|LOCK_NB))throw new RuntimeException('budget_unavailable');
  $state=json_decode(stream_get_contents($budget,2048)?:'{}',true);$now=microtime(true);
  if($now-(float)($state['last_request']??0)<3){$result=['ok'=>false,'error'=>'please_wait'];$http=429;}else{rewind($budget);ftruncate($budget,0);fwrite($budget,json_encode(['last_request'=>$now]));fflush($budget);$result=$input['action']==='sale'?hubbleSalePrepare($db,$input['wallet'],$input['ticker'],$input['provider'],$input['amount']):hubbleSaleApprovalPrepare($db,$input['wallet'],$input['ticker'],$input['provider'],$input['amount']);$http=$result['ok']?200:503;}
 }catch(Throwable){$result=['ok'=>false,'error'=>'sale_service_unavailable'];$http=503;}
 finally{if(is_resource($budget)){flock($budget,LOCK_UN);fclose($budget);}if($locked&&$db instanceof PDO)$db->query("SELECT RELEASE_LOCK('rwa_purchase_quotes')");}
 saleExecOutput($result??['ok'=>false,'error'=>'sale_service_unavailable'],$http??503);
}
