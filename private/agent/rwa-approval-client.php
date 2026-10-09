<?php
declare(strict_types=1);
require_once __DIR__.'/rwa-preflight-client.php';
function approvalReport(string $action,string $ticker,string $provider,string $amount,string $wallet,string $vendor):array {
    $approvalTx=null;$simulation=null;$problem=null;
    $report=buildReport($ticker,$provider,$amount,$wallet,$vendor,
        static function(array $tx,array $route,array $router,mixed $mode,array $warnings,array $cfg,array &$requests)
        use($action,$amount,$wallet,$vendor,&$approvalTx,&$simulation,&$problem):void{
            if($mode!=='SWAP'||$warnings!==[]){$problem='build_metadata_not_verified';return;}
            $usdt='0x55d398326f99059fF775485246999027B3197955';$raw=units($amount);
            if($action==='swap_check'){
                $reply=preflightRequest('swap_simulation','POST','/api/v1/dex/pre-transaction/simulate',
                    ['binanceChainId'=>'56','evmTx'=>['from'=>$wallet,'to'=>$tx['to'],'value'=>'0','data'=>$tx['data']]],$cfg,$requests);
                $simulation=preflightProjection($reply,$cfg);return;
            }
            $spender=$route['approveTarget']??null;
            if(!addressValid($spender)){$problem='approval_spender_unverified';return;}
            $reply=preflightRequest('approval_build','GET','/api/v1/dex/aggregator/approve-transaction',
                ['binanceChainId'=>'56','tokenContractAddress'=>$usdt,'approveAmount'=>$raw,'vendor'=>$vendor],$cfg,$requests);
            if($reply['status']!=='received'||$reply['stop']){$problem='approval_build_unavailable';return;}
            if(count($reply['data'])!==1||!is_array($reply['data'][0]??null)){$problem='approval_shape_unverified';return;}
            $entry=$reply['data'][0];$checked=preflightApproval($entry,$spender,$raw);
            if(!$checked['verified']){$problem='approval_calldata_mismatch';return;}
            $evm=['from'=>$wallet,'to'=>$usdt,'value'=>'0','data'=>$entry['data']];
            $reply=preflightRequest('approval_simulation','POST','/api/v1/dex/pre-transaction/simulate',
                ['binanceChainId'=>'56','evmTx'=>$evm],$cfg,$requests);
            $simulation=preflightProjection($reply,$cfg);$changes=$reply['data']['allowanceChanges']??null;
            $change=is_array($changes)&&count($changes)===1?($changes[0]??null):null;
            if($reply['status']!=='received'||($reply['data']['status']??null)!=='SUCCESS'
                ||!in_array($reply['data']['failReason']??null,[null,''],true)
                ||!is_array($reply['data']['balanceChanges']??null)||$reply['data']['balanceChanges']!==[]
                ||!is_array($change)
                ||strtolower((string)($change['tokenAddress']??''))!==strtolower($usdt)
                ||strtolower((string)($change['owner']??''))!==strtolower($wallet)
                ||strtolower((string)($change['spender']??''))!==strtolower($spender)
                ||(string)($change['postAmount']??'')!==$raw
                ||!preg_match('/\A[0-9]{1,78}\z/',(string)($change['preAmount']??''))){$problem='approval_simulation_not_verified';return;}
            // Never overwrite a nonzero allowance in this first approval flow.
            if((string)$change['preAmount']!=='0'){$problem='nonzero_allowance_requires_separate_review';return;}
            $approvalTx=$evm+['chainId'=>'0x38','spender'=>$spender,'amount_raw'=>$raw,'amount_usdt'=>$amount,
                'unlimited_approval'=>false,'simulation_verified'=>true];
        });
    $report['action']=$action==='approval'?'rwa_approval_prepare':'rwa_post_approval_swap_check';
    $report['approval_transaction']=$report['ok']&&$problem===null?$approvalTx:null;
    $report['simulation']=$simulation;
    if($report['ok']&&$problem!==null){$report['ok']=false;$report['error']=$problem;}
    $report['approval_enabled']=$report['ok']&&$approvalTx!==null;
    $report['execution_enabled']=false;
    $report['interpretation']='Preparation and off-chain simulation only. Approval requires a separate wallet-confirmation click. No purchase transaction is exposed or submitted by this endpoint.';
    return $report;
}
