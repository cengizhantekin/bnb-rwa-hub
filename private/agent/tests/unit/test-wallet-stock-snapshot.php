<?php
declare(strict_types=1);
define('BNB_WALLET_STOCK_FIXTURE_TEST', true);
require dirname(__DIR__, 2) . '/wallet-skills-rank.php';
require dirname(__DIR__, 2) . '/sync-wallet-stock-rank.php';
$n=0;
function check(bool $condition, string $label): void {
    global $n;
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    $n++;
}
$c='0x'.str_repeat('a',40);
$catalog=[$c=>['symbol'=>'NVDAB','provider'=>'bstock','underlying_ticker'=>'NVDA']];
$item=['chainId'=>'56','contractAddress'=>$c,'symbol'=>'NVDAB','holders'=>'123',
    'kycHolders'=>'0','holdersTop10Percent'=>'87.5678'];
$parsed=stockParse($item,$catalog,1);
check($parsed['holders']===123,'holder integer');
check($parsed['kyc']===0,'real zero KYC');
check($parsed['top']===87.5678,'top10 percent');
$item['kycHolders']=null;
check(stockParse($item,$catalog,1)['kyc']===null,'missing KYC remains null');
$item['holdersTop10Percent']=101;
try {stockParse($item,$catalog,1);throw new RuntimeException('invalid 101% accepted');}
catch (RuntimeException $e) {check($e->getMessage()==='invalid_holder_metrics','reject invalid 101%');}
$item['holdersTop10Percent']='12.5'; $item['symbol']='NVDAX';
try {stockParse($item,$catalog,1);throw new RuntimeException('mismatch accepted');}
catch (RuntimeException $e) {check($e->getMessage()==='contract_symbol_mismatch','reject mismatched symbol');}
$item['symbol']='NVDAB'; $item['chainId']='1';
try {stockParse($item,$catalog,1);throw new RuntimeException('wrong chain accepted');}
catch (RuntimeException $e) {check($e->getMessage()==='wrong_chain','reject non BSC');}
check(stockInt('-1')===null,'negative holder rejected');
$item['chainId']='56';$item['holders']=0;$item['kycHolders']=0;
check(stockParse($item,$catalog,1)['holders']===0,'real zero holder count remains valid');
check(stockPct(null)===null,'missing top10 remains null');
echo "Passed $n offline fixture assertions. No live API/DB/wallet request.\n";
