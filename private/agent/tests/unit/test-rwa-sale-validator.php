<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__, 2).'/rwa-sale-client.php';
$n=0;
try{
 $token='0x4553cfe1c09f37f38b12dc509f676964e392f8fc';$usdt='0x55d398326f99059ff775485246999027b3197955';$owner='0x'.str_repeat('1',40);$spender='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';$raw='20000000000000000';
 $route=['fromToken'=>['tokenContractAddress'=>$token,'decimal'=>'18'],'toToken'=>['tokenContractAddress'=>$usdt,'decimal'=>'18'],'binanceChainId'=>'56','fromTokenAmount'=>$raw,'toTokenAmount'=>'5073161285907644011','vendorName'=>'LiquidMesh'];saleRouteCheck($route,$token,$raw);$n++;
 foreach(['fromTokenAmount'=>'20000000000000001','binanceChainId'=>'1','vendorName'=>'Other'] as $key=>$v){$bad=$route;$bad[$key]=$v;$rejected=false;try{saleRouteCheck($bad,$token,$raw);}catch(RuntimeException){$rejected=true;}if(!$rejected)throw new RuntimeException('route_mismatch_accepted');$n++;}
 $minimum='5047795479478105790';$sim=['status'=>'SUCCESS','failReason'=>'','balanceChanges'=>[['owner'=>$owner,'contractAddress'=>$token,'tokenType'=>'Erc20','change'=>'-'.$raw],['owner'=>$owner,'contractAddress'=>$usdt,'tokenType'=>'Erc20','change'=>'5073184970311923348']],'allowanceChanges'=>[['owner'=>$owner,'tokenAddress'=>$token,'spender'=>$spender,'preAmount'=>$raw,'postAmount'=>'0']]];
 if(!swapEffects($sim,$owner,$token,$usdt,$spender,$raw,$minimum)['balance_changes_verified'])throw new RuntimeException('effects_failed');$n++;
 foreach(['debit','minimum','allowance'] as $kind){$bad=$sim;if($kind==='debit')$bad['balanceChanges'][0]['change']='-20000000000000001';if($kind==='minimum')$bad['balanceChanges'][1]['change']='1';if($kind==='allowance')$bad['allowanceChanges'][0]['postAmount']='1';$rejected=false;try{swapEffects($bad,$owner,$token,$usdt,$spender,$raw,$minimum);}catch(RuntimeException){$rejected=true;}if(!$rejected)throw new RuntimeException('bad_effects_accepted');$n++;}
 echo json_encode(['ok'=>true,'checks_passed'=>$n,'api_requests'=>0,'wallet_transactions'=>0]).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"FAIL: sale validator\n");exit(1);}
