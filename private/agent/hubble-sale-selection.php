<?php
declare(strict_types=1);
require_once __DIR__.'/rwa-swap-client.php';
function hubbleSaleSelection(PDO $db,string $ticker,string $provider,string $amount):array{
 if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$ticker)||!in_array($provider,['bstock','ondo','xstocks'],true))throw new RuntimeException('invalid_sale_selection');
 if(!preg_match('/\A(0|[1-9][0-9]{0,40})(?:\.([0-9]{1,18}))?\z/',$amount,$m))throw new RuntimeException('invalid_sale_amount');
 $raw=ltrim($m[1].str_pad($m[2]??'',18,'0'),'0');if($raw===''||strlen($raw)>78||swapCompare($raw,'115792089237316195423570985008687907853269984665640564039457584007913129639935')>0)throw new RuntimeException('invalid_sale_amount');
 $q=$db->prepare("SELECT underlying_ticker AS ticker,provider,symbol,contract_address FROM rwa_tokens WHERE underlying_ticker=? AND provider=? AND chain_id='56' AND is_active=1 LIMIT 2");$q->execute([$ticker,$provider]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 if(count($rows)!==1||!addressValid((string)$rows[0]['contract_address']))throw new RuntimeException('token_catalogue_mismatch');
 return $rows[0]+['token'=>strtolower($rows[0]['contract_address']),'raw'=>$raw,'amount'=>$amount];
}
function hubbleSaleCatalogue(PDO $db):array{
 $rows=$db->query("SELECT underlying_ticker AS ticker,provider,symbol,contract_address FROM rwa_tokens WHERE chain_id='56' AND is_active=1 AND provider IN ('bstock','ondo','xstocks') AND underlying_ticker IS NOT NULL AND underlying_ticker<>'' ORDER BY underlying_ticker,provider LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
 $groups=[];foreach($rows as $r){if(!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',(string)$r['ticker'])||!addressValid((string)$r['contract_address']))continue;$groups[$r['ticker'].'|'.$r['provider']][]=$r;}
 $out=[];foreach($groups as $g)if(count($g)===1)$out[]=$g[0];return $out;
}
