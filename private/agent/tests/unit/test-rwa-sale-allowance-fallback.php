<?php
declare(strict_types=1);
// Offline fixture regression: sale-only fallback when Binance omits allowanceChanges.
// No network calls, database writes, or wallet transactions.
require dirname(__DIR__, 2).'/rwa-swap-client.php';

$owner='0x'.str_repeat('1',40);
$token='0x'.str_repeat('2',40);
$usdt='0x55d398326f99059ff775485246999027b3197955';
$spender='0xb44446b0c8e56988c34f7ff73ae904982b5fdda5';
$raw='20000000000000000';
$out='4613234056800000000';
$minimum='4590167886516000000';

$sim=['status'=>'SUCCESS','failReason'=>'','balanceChanges'=>[
    ['contractAddress'=>$usdt,'tokenType'=>'Erc20','change'=>$out,'owner'=>$owner],
    ['contractAddress'=>$token,'tokenType'=>'Erc20','change'=>'-'.$raw,'owner'=>$owner],
], 'allowanceChanges'=>[]];

$checked=0;
$accept=static function (array $data,bool $allowMissing,bool $debitVerified,bool $walletRequired) use ($owner,$token,$usdt,$spender,$raw,$minimum,&$checked):void {
    $result=swapEffects($data,$owner,$token,$usdt,$spender,$raw,$minimum,$allowMissing);
    if($result['balance_changes_verified']!==true||$result['allowance_debit_verified']!==$debitVerified
        ||$result['wallet_allowance_check_required']!==$walletRequired||$result['output_amount_raw']!=='4613234056800000000') {
        throw new RuntimeException('unexpected_sale_effects_flags');
    }
    $checked++;
};
$reject=static function(array $data,bool $allowMissing,string $expected) use ($owner,$token,$usdt,$spender,$raw,$minimum,&$checked):void {
    try { swapEffects($data,$owner,$token,$usdt,$spender,$raw,$minimum,$allowMissing); }
    catch(RuntimeException $e){if($e->getMessage()!==$expected)throw $e; $checked++;return;}
    throw new RuntimeException('invalid_simulation_accepted: '.$expected);
};

$accept($sim,true,false,true); // Sell: incomplete allowance telemetry, wallet must verify separately.
$reject($sim,false,'swap_allowance_changes_unverified'); // Buy must still fail closed.
$withChange=$sim;
$withChange['allowanceChanges']=[['owner'=>$owner,'tokenAddress'=>$token,'spender'=>$spender,'preAmount'=>$raw,'postAmount'=>'0']];
$accept($withChange,true,true,false);
$accept($withChange,false,true,false);
$bad=$sim;unset($bad['allowanceChanges']);$reject($bad,true,'swap_allowance_changes_unverified');
$bad=$sim;$bad['allowanceChanges']=[null];$reject($bad,true,'swap_allowance_changes_unverified');
$bad=$withChange;$bad['allowanceChanges'][]=$withChange['allowanceChanges'][0];$reject($bad,true,'swap_allowance_changes_unverified');
$bad=$withChange;$bad['allowanceChanges'][0]['spender']='0x'.str_repeat('3',40);$reject($bad,true,'unexpected_swap_allowance_change');
$bad=$withChange;$bad['allowanceChanges'][0]['postAmount']='1';$reject($bad,true,'swap_allowance_debit_unverified');
$bad=$sim;$bad['balanceChanges'][1]['change']='-'.$raw.'0';$reject($bad,true,'unexpected_swap_balance_change');
$bad=$sim;$bad['balanceChanges'][0]['owner']='0x'.str_repeat('4',40);$reject($bad,true,'swap_owner_or_asset_unverified');
$bad=$sim;$bad['balanceChanges'][0]['change']='1';$reject($bad,true,'swap_output_below_minimum');
$bad=$sim;$bad['status']='FAILED';$reject($bad,true,'swap_simulation_failed');
$bad=$sim;$bad['balanceChanges'][]=$bad['balanceChanges'][0];$reject($bad,true,'swap_balance_changes_unverified');
echo "PASS: {$checked} offline sale-only allowance regression checks; no API, database or wallet calls.\n";
