<?php
declare(strict_types=1);
require_once __DIR__.'/hubble-sale-selection.php';
function hubbleSaleRouteCheck(array $r,string $token,string $raw):void{
 foreach(['fromToken'=>[$token,'18'],'toToken'=>['0x55d398326f99059ff775485246999027b3197955','18']] as $key=>$expected){if(strtolower((string)($r[$key]['tokenContractAddress']??''))!==$expected[0]||(string)($r[$key]['decimal']??'')!==$expected[1])throw new RuntimeException('route_identity_unverified');}
 if((string)($r['binanceChainId']??'')!=='56'||($r['fromTokenAmount']??null)!==$raw||($r['vendorName']??null)!=='LiquidMesh')throw new RuntimeException('route_identity_unverified');swapInteger($r['toTokenAmount']??null);
}
function hubbleSalePrepare(PDO $db,string $wallet,string $ticker,string $provider,string $amount):array{
 $token='';$symbol='';$usdt='0x55d398326f99059ff775485246999027b3197955';$spender='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';$raw='';$requests=[];$lock=null;$error=null;$payload=null;$simulation=null;$out=null;$waitMs=null;$buildChecks=null;$start=hrtime(true);
 try{
  if(!addressValid($wallet)||strtolower($wallet)==='0x'.str_repeat('0',40))throw new RuntimeException('invalid_wallet');
  $selection=hubbleSaleSelection($db,$ticker,$provider,$amount);$token=$selection['token'];$raw=$selection['raw'];$symbol=$selection['symbol'];
  $cfg=require '/home/bhub.tm.biz.tr/private/config.php';if(!is_array($cfg)||empty($cfg['binance_web3_api_key'])||empty($cfg['binance_web3_secret_key']))throw new RuntimeException('config_unavailable');
  $lock=fopen('/run/lock/bnb-rwa-all-sync.lock','r');if(!is_resource($lock))throw new RuntimeException('lock_unavailable');$waitStart=hrtime(true);$deadline=microtime(true)+45;
  while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>=$deadline)throw new RuntimeException('sync_busy_try_again');usleep(100000);}$waitMs=round((hrtime(true)-$waitStart)/1e6,2);
  $query=['binanceChainId'=>'56','amount'=>$raw,'fromTokenAddress'=>$token,'toTokenAddress'=>$usdt,'userWalletAddress'=>$wallet];
  $quote=requestQuote($query,$cfg,$requests,$provider,$symbol);if($quote['status']!=='received'||$quote['stop'])throw new RuntimeException('quote_unavailable');$selected=null;
  foreach(array_slice($quote['data'],0,10) as $route){if(!is_array($route)||($route['vendorName']??null)!=='LiquidMesh'||($route['executionMode']??null)!=='SWAP')continue;hubbleSaleRouteCheck($route,$token,$raw);if(strtolower((string)($route['approveTarget']??''))!==$spender||!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/',(string)($route['quoteId']??'')))throw new RuntimeException('route_identity_unverified');$selected=$route;break;}
  if($selected===null)throw new RuntimeException('pilot_route_unavailable');
  usleep(1300000);$build=requestBuild($query+['quoteId'=>$selected['quoteId'],'slippagePercent'=>'0.5','autoSlippage'=>'false','approveTransaction'=>'false','priceImpactProtectionPercent'=>'1'],$cfg,$requests,$provider,$symbol);
  if($build['status']!=='received'||$build['stop'])throw new RuntimeException('build_unavailable');$b=$build['data'];$r=$b['routerResult']??[];$tx=$b['tx']??[];if(!is_array($r)||!is_array($tx))throw new RuntimeException('build_identity_unverified');hubbleSaleRouteCheck($r,$token,$raw);
  $buildChecks=[
   'execution_mode_matches'=>($b['executionMode']??null)==='SWAP',
   'sender_matches'=>strtolower((string)($tx['from']??''))===strtolower($wallet),
   'destination_matches'=>strtolower((string)($tx['to']??''))===$spender,
   'native_value_is_zero'=>in_array((string)($tx['value']??''),['0','0x0','0x00'],true),
   'calldata_format_matches'=>strlen((string)($tx['data']??''))>=12&&strlen((string)($tx['data']??''))<=65546&&preg_match('/\A0xad43f73d(?:[0-9a-fA-F]{2})+\z/',(string)($tx['data']??''))===1,
   'execution_mode_present'=>array_key_exists('executionMode',$b),
   'sender_present'=>array_key_exists('from',$tx),
   'destination_present'=>array_key_exists('to',$tx),
   'native_value_present'=>array_key_exists('value',$tx),
   'calldata_present'=>array_key_exists('data',$tx),
   'calldata_is_even_hex'=>preg_match('/\A0x(?:[0-9a-fA-F]{2}){4,}\z/',(string)($tx['data']??''))===1,
   'calldata_chars'=>strlen((string)($tx['data']??'')),
   'method_selector'=>preg_match('/\A0x[0-9a-fA-F]{8}/',(string)($tx['data']??''))===1?strtolower(substr($tx['data'],0,10)):null,
  ];
  foreach(['execution_mode_matches','sender_matches','destination_matches','native_value_is_zero','calldata_format_matches'] as $check){if(!$buildChecks[$check])throw new RuntimeException('build_identity_unverified');}
  $out=swapInteger($r['toTokenAmount']??null);$minimum=swapInteger($tx['minReceiveAmount']??null);if($minimum==='0'||swapCompare($minimum,$out)>0||swapCompare($minimum,swapMinimum($out))<0)throw new RuntimeException('minimum_receive_unverified');
  // Demo proceeds limit applies to every selected asset.
  if(swapCompare($out,'20000000000000000000')>0)throw new RuntimeException('sale_pilot_limit');
  usleep(1300000);$reply=preflightRequest('sale_swap_simulation','POST','/api/v1/dex/pre-transaction/simulate',['binanceChainId'=>'56','evmTx'=>['from'=>$wallet,'to'=>$spender,'value'=>'0','data'=>$tx['data']]],$cfg,$requests);
  $simulation=preflightProjection($reply,$cfg);if($reply['status']!=='received'||$reply['stop'])throw new RuntimeException('simulation_unavailable');$effects=swapEffects($reply['data'],$wallet,$token,$usdt,$spender,$raw,$minimum,true);
  $expires=((int)strtotime($requests[0]['request_utc'])+25)*1000;if((int)floor(microtime(true)*1000)>=$expires)throw new RuntimeException('fresh_quote_required');
  $payload=['from'=>$wallet,'to'=>$spender,'value'=>'0','data'=>$tx['data'],'chainId'=>'0x38','spender'=>$spender,'input_token'=>$token,'output_token'=>$usdt,'input_amount_raw'=>$raw,'minimum_output_raw'=>$minimum,'simulated_output_raw'=>$effects['output_amount_raw'],'quoted_output_raw'=>$out,'effects_verified'=>true,'allowance_debit_verified'=>$effects['allowance_debit_verified'],'wallet_allowance_check_required'=>$effects['wallet_allowance_check_required'],'valid_until_ms'=>$expires,'calldata_sha256'=>hash('sha256',$tx['data'])];
 }catch(Throwable $e){$known=['invalid_sale_selection','invalid_sale_amount','invalid_wallet','token_catalogue_mismatch','config_unavailable','lock_unavailable','sync_busy_try_again','quote_unavailable','pilot_route_unavailable','route_identity_unverified','build_unavailable','build_identity_unverified','minimum_receive_unverified','sale_pilot_limit','simulation_unavailable','fresh_quote_required','swap_simulation_failed','swap_balance_changes_unverified','swap_owner_or_asset_unverified','unexpected_swap_balance_change','swap_output_below_minimum','swap_allowance_changes_unverified','unexpected_swap_allowance_change','swap_allowance_debit_unverified','invalid_swap_integer'];$error=in_array($e->getMessage(),$known,true)?$e->getMessage():'sale_prepare_failed';}
 finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
 $weight=0;$complete=true;foreach($requests as $r){if(is_string($r['weight'])&&ctype_digit($r['weight']))$weight+=(int)$r['weight'];else$complete=false;}
 return ['ok'=>$error===null,'error'=>$error,'action'=>'rwa_sale_prepare','ticker'=>$ticker,'provider'=>$provider,'symbol'=>$symbol,'wallet'=>$wallet,'input_amount'=>$amount,'vendor'=>'LiquidMesh','mode'=>'SWAP','build_identity_checks'=>$buildChecks,'sale_transaction'=>$error===null?$payload:null,'simulation'=>$simulation,'execution_enabled'=>$error===null&&$payload!==null,'server_now_ms'=>(int)floor(microtime(true)*1000),'requests'=>$requests,'binance_http_requests'=>count($requests),'observed_weight_sum'=>$complete?$weight:null,'lock_wait_ms'=>$waitMs,'total_time_ms'=>round((hrtime(true)-$start)/1e6,2),'checked_at_utc'=>stamp()];
}
