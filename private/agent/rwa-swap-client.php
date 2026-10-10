<?php
declare(strict_types=1);
require_once __DIR__.'/rwa-preflight-client.php';
function swapInteger(mixed $s):string {
    if(!is_string($s)||!preg_match('/\A(?:0|[1-9][0-9]{0,77})\z/',$s))throw new RuntimeException('invalid_swap_integer');return $s;
}
function swapCompare(string $a,string $b):int{return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
function swapSubtract(string $a,string $b):string {
    if(swapCompare($a,$b)<0)throw new RuntimeException('negative_swap_integer');$b=str_pad($b,strlen($a),'0',STR_PAD_LEFT);$r='';$borrow=0;
    for($i=strlen($a)-1;$i>=0;$i--){$v=(int)$a[$i]-(int)$b[$i]-$borrow;$borrow=$v<0?1:0;if($v<0)$v+=10;$r=(string)$v.$r;}return ltrim($r,'0')?:'0';
}
function swapMinimum(string $out):string {
    $r='';$carry=0;for($i=strlen($out)-1;$i>=0;$i--){$v=(int)$out[$i]*995+$carry;$r=(string)($v%10).$r;$carry=intdiv($v,10);}if($carry)$r=(string)$carry.$r;
    return strlen($r)>3?(ltrim(substr($r,0,-3),'0')?:'0'):'0';
}
function swapEffects(array $data,string $owner,string $usdt,string $token,string $spender,string $raw,string $minimum,bool $allowUnreportedAllowance=false):array {
    if(($data['status']??null)!=='SUCCESS'||!in_array($data['failReason']??null,[null,''],true))throw new RuntimeException('swap_simulation_failed');
    $rows=$data['balanceChanges']??null;if(!is_array($rows)||count($rows)!==2)throw new RuntimeException('swap_balance_changes_unverified');$in=null;$out=null;
    foreach($rows as $r){if(!is_array($r)||strtolower((string)($r['owner']??''))!==strtolower($owner)||strtolower((string)($r['tokenType']??''))!=='erc20')throw new RuntimeException('swap_owner_or_asset_unverified');
        $ca=strtolower((string)($r['contractAddress']??''));$delta=$r['change']??null;
        if($ca===strtolower($usdt)&&$in===null&&$delta==='-'.$raw)$in=$raw;
        elseif($ca===strtolower($token)&&$out===null&&is_string($delta))$out=swapInteger($delta);
        else throw new RuntimeException('unexpected_swap_balance_change');
    }
    if($in===null||$out===null||$out==='0'||swapCompare($out,$minimum)<0)throw new RuntimeException('swap_output_below_minimum');
    $changes=$data['allowanceChanges']??null;
    // Binance may return an empty allowanceChanges list even when a sale simulation
    // reports the exact input debit and output credit. This is NOT allowance proof.
    // Only the sale caller opts in; the wallet must independently read allowance
    // before displaying the sale and again immediately before requesting a send.
    if($changes===[]&&$allowUnreportedAllowance){
        return ['input_amount_raw'=>$in,'output_amount_raw'=>$out,'minimum_output_raw'=>$minimum,
            'balance_changes_verified'=>true,'allowance_debit_verified'=>false,'wallet_allowance_check_required'=>true];
    }
    if(!is_array($changes)||count($changes)!==1||!is_array($changes[0]))throw new RuntimeException('swap_allowance_changes_unverified');$c=$changes[0];
    if(strtolower((string)($c['tokenAddress']??''))!==strtolower($usdt)||strtolower((string)($c['owner']??''))!==strtolower($owner)||strtolower((string)($c['spender']??''))!==strtolower($spender))throw new RuntimeException('unexpected_swap_allowance_change');
    $before=swapInteger($c['preAmount']??null);$after=swapInteger($c['postAmount']??null);
    if(swapCompare($before,$raw)<0||swapSubtract($before,$after)!==$raw)throw new RuntimeException('swap_allowance_debit_unverified');
    return ['input_amount_raw'=>$in,'output_amount_raw'=>$out,'minimum_output_raw'=>$minimum,
        'balance_changes_verified'=>true,'allowance_debit_verified'=>true,'wallet_allowance_check_required'=>false];
}
function purchaseDemoAllowed(string $amount):bool { $raw=units($amount);return swapCompare($raw,'6000000000000000000')>=0&&swapCompare($raw,'10000000000000000000')<=0; }
function swapReport(string $ticker,string $provider,string $amount,string $wallet,string $vendor):array {
    $payload=null;$simulation=null;$problem=null;$problemStage=null;
    // Pilot purchase cap; approvals/research are unchanged.
    if(!purchaseDemoAllowed($amount))return ['ok'=>false,'error'=>'purchase_amount_must_be_6_to_10_USDT','execution_enabled'=>false];
    $report=buildReport($ticker,$provider,$amount,$wallet,$vendor,
        static function(array $tx,array $route,array $router,mixed $mode,array $warnings,array $cfg,array &$requests)use($amount,$wallet,$vendor,&$payload,&$simulation,&$problem,&$problemStage):void{
            try{
                $usdt='0x55d398326f99059fF775485246999027B3197955';$trusted='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';$raw=units($amount);
                if($mode!=='SWAP'||$warnings!==[]||$vendor!=='LiquidMesh'||strtolower((string)($tx['to']??''))!==$trusted||strtolower((string)($route['approveTarget']??''))!==$trusted||substr(strtolower((string)($tx['data']??'')),0,10)!=='0xad43f73d')throw new RuntimeException('pilot_route_not_supported');
                if((string)($router['toToken']['decimal']??'')!=='18')throw new RuntimeException('output_precision_unverified');
                $token=$router['toToken']['tokenContractAddress'];$out=swapInteger($router['toTokenAmount']??null);$minimum=swapInteger($tx['minReceiveAmount']??null);
                if($minimum==='0'||swapCompare($minimum,$out)>0||swapCompare($minimum,swapMinimum($out))<0)throw new RuntimeException('minimum_receive_unverified');
                $reply=preflightRequest('swap_simulation','POST','/api/v1/dex/pre-transaction/simulate',['binanceChainId'=>'56','evmTx'=>['from'=>$wallet,'to'=>$tx['to'],'value'=>'0','data'=>$tx['data']]],$cfg,$requests);
                $simulation=preflightProjection($reply,$cfg);if($reply['status']!=='received')throw new RuntimeException('swap_simulation_unavailable');
                $effects=swapEffects($reply['data'],$wallet,$usdt,$token,$trusted,$raw,$minimum);
                $expires=((int)strtotime($requests[0]['request_utc'])+25)*1000;if((int)floor(microtime(true)*1000)>=$expires)throw new RuntimeException('fresh_quote_required');
                $payload=['from'=>$wallet,'to'=>$tx['to'],'value'=>'0','data'=>$tx['data'],'chainId'=>'0x38','spender'=>$trusted,'input_token'=>$usdt,'output_token'=>$token,'output_decimals'=>18,'input_amount_raw'=>$raw,'minimum_output_raw'=>$minimum,'simulated_output_raw'=>$effects['output_amount_raw'],'effects_verified'=>true,'valid_until_ms'=>$expires,'calldata_sha256'=>hash('sha256',$tx['data'])];
            }catch(Throwable $e){$known=['pilot_route_not_supported','output_precision_unverified','minimum_receive_unverified','swap_simulation_unavailable','swap_simulation_failed','swap_balance_changes_unverified','swap_owner_or_asset_unverified','unexpected_swap_balance_change','swap_output_below_minimum','swap_allowance_changes_unverified','unexpected_swap_allowance_change','swap_allowance_debit_unverified','invalid_swap_integer','negative_swap_integer','fresh_quote_required'];$problem=in_array($e->getMessage(),$known,true)?$e->getMessage():'swap_validation_failed';$problemStage=$problem==='fresh_quote_required'?'quote_freshness':(str_contains($problem,'simulation')||str_contains($problem,'balance_change')||str_contains($problem,'allowance')||str_contains($problem,'output_below')?'swap_simulation':'route_validation');}
        });
    $report['action']='rwa_swap_prepare';$report['simulation']=$simulation;if($report['ok']&&$problem!==null){$report['ok']=false;$report['error']=$problem;$report['error_stage']=$problemStage??'execution_validation';}
    $report['server_now_ms']=(int)floor(microtime(true)*1000);$report['swap_transaction']=$report['ok']?$payload:null;$report['execution_enabled']=$report['ok']&&$payload!==null;
    $report['interpretation']='Fresh quote, unsigned build and matched simulation effects. The user must separately confirm the purchase in the wallet. Simulation is a prediction, not settlement.';return $report;
}
