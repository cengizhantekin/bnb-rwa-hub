<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__, 2).'/hubble-sale-client.php';
class HubbleSelectionStatement extends PDOStatement{
 public function __construct(private array $rows){}
 public function execute(?array $params=null):bool{return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
class HubbleSelectionDB extends PDO{
 public function __construct(private array $rows){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new HubbleSelectionStatement($this->rows);}
}
try{
 $n=0;$token='0x'.str_repeat('2',40);$rows=[['ticker'=>'TSLA','provider'=>'bstock','symbol'=>'TSLAB','contract_address'=>$token]];$db=new HubbleSelectionDB($rows);
 $s=hubbleSaleSelection($db,'TSLA','bstock','0.03');if($s['raw']!=='30000000000000000'||$s['token']!==$token)throw new RuntimeException('selection_failed');$n++;
 foreach(['0','-1','1e2','0.0000000000000000001','00.03','abc'] as $amount){$blocked=false;try{hubbleSaleSelection($db,'TSLA','bstock',$amount);}catch(RuntimeException){$blocked=true;}if(!$blocked)throw new RuntimeException('invalid_amount_accepted');$n++;}
 foreach([[],[$rows[0],$rows[0]]] as $records){$blocked=false;try{hubbleSaleSelection(new HubbleSelectionDB($records),'TSLA','bstock','0.03');}catch(RuntimeException){$blocked=true;}if(!$blocked)throw new RuntimeException('ambiguous_token_accepted');$n++;}
 $route=['fromToken'=>['tokenContractAddress'=>$token,'decimal'=>'18'],'toToken'=>['tokenContractAddress'=>'0x55d398326f99059ff775485246999027b3197955','decimal'=>'18'],'binanceChainId'=>'56','fromTokenAmount'=>$s['raw'],'toTokenAmount'=>'7000000000000000000','vendorName'=>'LiquidMesh'];hubbleSaleRouteCheck($route,$token,$s['raw']);$n++;
 foreach(['tokenContractAddress'=>'0x'.str_repeat('3',40),'decimal'=>'6'] as $k=>$v){$bad=$route;$bad['fromToken'][$k]=$v;$blocked=false;try{hubbleSaleRouteCheck($bad,$token,$s['raw']);}catch(RuntimeException){$blocked=true;}if(!$blocked)throw new RuntimeException('wrong_route_accepted');$n++;}
 echo json_encode(['ok'=>true,'checks_passed'=>$n,'api_requests'=>0,'database_writes'=>0,'wallet_transactions'=>0]).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
