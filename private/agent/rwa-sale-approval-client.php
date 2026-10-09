<?php
declare(strict_types=1);
require_once __DIR__.'/rwa-preflight-client.php';
// Narrow prerequisite test, not a general sale execution endpoint.
function saleApprovalPrepare(PDO $db,string $wallet):array{
 $token='0x4553cfe1c09f37f38b12dc509f676964e392f8fc';$spender='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';$raw='20000000000000000';$requests=[];$lock=null;$error=null;$transaction=null;$simulation=null;$start=hrtime(true);
 try{
  if(!addressValid($wallet)||strtolower($wallet)==='0x'.str_repeat('0',40))throw new RuntimeException('invalid_wallet');
  $q=$db->prepare("SELECT contract_address FROM rwa_tokens WHERE underlying_ticker='AMZN' AND provider='ondo' AND chain_id='56' AND is_active=1 LIMIT 2");$q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1||strtolower((string)$rows[0]['contract_address'])!==$token)throw new RuntimeException('token_catalogue_mismatch');
  $cfg=require '/home/bhub.tm.biz.tr/private/config.php';if(!is_array($cfg)||empty($cfg['binance_web3_api_key'])||empty($cfg['binance_web3_secret_key']))throw new RuntimeException('config_unavailable');
  $lock=fopen('/run/lock/bnb-rwa-all-sync.lock','r');if(!is_resource($lock))throw new RuntimeException('lock_unavailable');$deadline=microtime(true)+45;
  while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>=$deadline)throw new RuntimeException('sync_busy_try_again');usleep(100000);}
  $reply=preflightRequest('sale_approval_build','GET','/api/v1/dex/aggregator/approve-transaction',['binanceChainId'=>'56','tokenContractAddress'=>$token,'approveAmount'=>$raw,'vendor'=>'LiquidMesh'],$cfg,$requests);
  if($reply['status']!=='received'||$reply['stop']||count($reply['data'])!==1||!is_array($reply['data'][0]??null))throw new RuntimeException('approval_build_unavailable');$entry=$reply['data'][0];if(!preflightApproval($entry,$spender,$raw)['verified'])throw new RuntimeException('approval_calldata_mismatch');
  $tx=['from'=>$wallet,'to'=>$token,'value'=>'0','data'=>$entry['data']];$reply=preflightRequest('sale_approval_simulation','POST','/api/v1/dex/pre-transaction/simulate',['binanceChainId'=>'56','evmTx'=>$tx],$cfg,$requests);$simulation=preflightProjection($reply,$cfg);
  $changes=$reply['data']['allowanceChanges']??null;$change=is_array($changes)&&count($changes)===1?$changes[0]:null;
  if($reply['status']!=='received'||($reply['data']['status']??null)!=='SUCCESS'||!in_array($reply['data']['failReason']??null,[null,''],true)||($reply['data']['balanceChanges']??null)!==[]||!is_array($change)||strtolower((string)($change['tokenAddress']??''))!==$token||strtolower((string)($change['owner']??''))!==strtolower($wallet)||strtolower((string)($change['spender']??''))!==$spender||($change['postAmount']??null)!==$raw)throw new RuntimeException('approval_simulation_unverified');
  if(($change['preAmount']??null)!=='0')throw new RuntimeException('existing_permission_use_status_check');
  $transaction=$tx+['chainId'=>'0x38','spender'=>$spender,'amount_raw'=>$raw,'unlimited_approval'=>false,'simulation_verified'=>true];
 }catch(Throwable $e){$known=['invalid_wallet','token_catalogue_mismatch','config_unavailable','lock_unavailable','sync_busy_try_again','approval_build_unavailable','approval_calldata_mismatch','approval_simulation_unverified','existing_permission_use_status_check'];$error=in_array($e->getMessage(),$known,true)?$e->getMessage():'approval_prepare_failed';}
 finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
 $weight=0;$complete=true;foreach($requests as $r){if(is_string($r['weight'])&&ctype_digit($r['weight']))$weight+=(int)$r['weight'];else$complete=false;}
 return ['ok'=>$error===null,'error'=>$error,'symbol'=>'AMZNon','token'=>$token,'spender'=>$spender,'amount'=>'0.02','approval_transaction'=>$error===null?$transaction:null,'simulation'=>$simulation,'approval_enabled'=>$error===null&&$transaction!==null,'execution_enabled'=>false,'requests'=>$requests,'binance_http_requests'=>count($requests),'observed_weight_sum'=>$complete?$weight:null,'total_time_ms'=>round((hrtime(true)-$start)/1e6,2),'checked_at_utc'=>stamp()];
}
