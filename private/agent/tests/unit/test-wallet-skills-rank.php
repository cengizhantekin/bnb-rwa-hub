<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/wallet-skills-rank.php';
$passed = 0;
function check(bool $ok, string $message): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $passed++;
}
$addr1='0x' . str_repeat('a',40);
$addr2='0x' . str_repeat('b',40);
$addr3='0x' . str_repeat('c',40);
$records=[
    ['provider'=>'bstock','underlying_ticker'=>'TSLA','symbol'=>'TSLAB','contract_address'=>$addr1],
    ['provider'=>'ondo','underlying_ticker'=>'TSLA','symbol'=>'TSLAon','contract_address'=>$addr2],
];
$map=wsrCatalog($records);
check(count($map)===2,'catalog map count');
check($map[$addr2]['ticker']==='TSLA','catalog ticker');
check($map[$addr1]['provider']==='bStocks','provider display');
$raw=['success'=>true,'code'=>'000000','data'=>['total'=>'100','tokens'=>[
    ['chainId'=>'56','contractAddress'=>strtoupper($addr1),'symbol'=>'TSLAB','percentChange24h'=>'0'],
    ['chainId'=>'1','contractAddress'=>$addr2,'symbol'=>'wrong','percentChange24h'=>'5'],
    ['chainId'=>'56','contractAddress'=>$addr3,'symbol'=>'Other','percentChange24h'=>'-3.125'],
    ['chainId'=>'56','contractAddress'=>$addr2,'symbol'=>'TSLAon','percentChange24h'=>'-2.2'],
]]];
$got=wsrNormalize($raw,$map);
check(is_array($got),'valid response');
check(count($got['items'])===3,'reject other chain');
check($got['items'][0]['position']===1,'first position');
check($got['items'][1]['position']===3,'preserve positions after skip');
check($got['items'][0]['change_24h_pct']===0.0,'valid numeric zero');
check($got['items'][1]['matched_ticker']===null,'never guess unknown contract');
check($got['items'][2]['matched_ticker']==='TSLA','exact contract matched');
check($got['total']===100,'pagination total');
check(wsrNormalize(['success'=>false,'code'=>'000000','data'=>['tokens'=>[]]],$map)===null,'reject failed envelope');
check(wsrNormalize(['success'=>true,'code'=>'100004','data'=>['tokens'=>[]]],$map)===null,'reject rate limit');
check(wsrNormalize(['success'=>true,'code'=>'000000','data'=>[]],$map)===null,'reject unknown response schema');
check(count(wsrNormalize(['success'=>true,'code'=>'000000','data'=>['tokens'=>[]]],$map)['items'])===0,'valid empty list');
check(wsrPercent('')===null,'reject blank percent');
check(wsrPercent('Infinity')===null,'reject nonfinite string');
check(wsrPercent(-101)===null,'reject invalid percent');
check(wsrTicker('..')===null,'reject invalid ticker');
check(wsrContract('0x'.str_repeat('f',39))===null,'reject invalid contract');
check(wsrSymbol('<script>')===null,'reject unsafe symbol');
$dir=sys_get_temp_dir().'/wsr-unit-'.bin2hex(random_bytes(5));
$counter=0;
$fetch=static function() use (&$counter,$raw): array { $counter++;return ['ok'=>true,'data'=>$raw]; };
$first=wsrLoad($records,$fetch,$dir);
$second=wsrLoad($records,$fetch,$dir);
check($first['ok']===true&&$first['available']===true,'first cold result');
check($first['cache_hit']===false&&$first['http_requests']===1,'cold metrics');
check($second['cache_hit']===true&&$second['http_requests']===0,'cache hit metrics');
check($counter===1,'only one cold fetch');
check($second['request_weight']===null,'unknown weight not fabricated');
foreach (glob($dir.'/*')?:[] as $f) @unlink($f);
@rmdir($dir);
echo "PASS: {$passed} offline Wallet Skill rank assertions\n";
