<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__, 2).'/rwa-swap-client.php';
$owner='0x'.str_repeat('1',40);$usdt='0x55d398326f99059ff775485246999027b3197955';$token='0x4553cfe1c09f37f38b12dc509f676964e392f8fc';$spender='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';$raw='6000000000000000000';$minimum='23411932089734255';
$data=['status'=>'SUCCESS','failReason'=>'','balanceChanges'=>[
 ['owner'=>$owner,'contractAddress'=>$usdt,'tokenType'=>'Erc20','change'=>'-'.$raw],
 ['owner'=>$owner,'contractAddress'=>$token,'tokenType'=>'Erc20','change'=>'23528724636165038']],
 'allowanceChanges'=>[['owner'=>$owner,'tokenAddress'=>$usdt,'spender'=>$spender,'preAmount'=>'100000000000000000000','postAmount'=>'94000000000000000000']]];
$passed=0;
function must(bool $ok):void{if(!$ok)throw new RuntimeException('test_failed');}
try{
 must(swapSubtract('100000000000000000000','94000000000000000000')===$raw);$passed++;
 must(swapMinimum('23529579989682669')===$minimum);$passed++;
 must(swapMinimum('1')==='0'&&swapMinimum('1000')==='995');$passed++;
 must(swapEffects($data,$owner,$usdt,$token,$spender,$raw,$minimum)['output_amount_raw']==='23528724636165038');$passed++;
 foreach(['failed','owner','input','output','allowance','spender','extra'] as $case){$d=$data;
 switch($case){case 'failed':$d['status']='FAILED';break;case 'owner':$d['balanceChanges'][1]['owner']=$spender;break;case 'input':$d['balanceChanges'][0]['change']='-6000000000000000001';break;case 'output':$d['balanceChanges'][1]['change']='1';break;case 'allowance':$d['allowanceChanges'][0]['postAmount']='94000000000000000001';break;case 'spender':$d['allowanceChanges'][0]['spender']=$owner;break;case 'extra':$d['balanceChanges'][]=$d['balanceChanges'][0];break;}
 $rejected=false;try{swapEffects($d,$owner,$usdt,$token,$spender,$raw,$minimum);}catch(RuntimeException){$rejected=true;}must($rejected);$passed++;
 }
 echo json_encode(['ok'=>true,'checks_passed'=>$passed,'api_requests'=>0,'wallet_transactions'=>0],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"FAIL: offline swap validation test\n");exit(1);}
