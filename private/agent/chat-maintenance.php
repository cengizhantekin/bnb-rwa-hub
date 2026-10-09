<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/chat-store.php';
try{
 $db=chatDb();$mode=$argv[1]??'';
 if($mode==='--migrate'){
  $sql=file_get_contents(__DIR__.'/chat-schema.sql');if($sql===false)throw new RuntimeException('schema_missing');
  foreach(explode(';',$sql)as$statement)if(trim($statement)!=='')$db->exec($statement);
  echo "Chat tables are ready. Market tables were not modified.\n";
 }elseif($mode==='--self-test'){
  $db->beginTransaction();
  $hash=hash('sha256',bin2hex(random_bytes(32)));$request=bin2hex(random_bytes(16));
  $q=$db->prepare("INSERT INTO rwa_chat_sessions(token_hash,state_json,expires_at) VALUES (?, '{}', DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 7 DAY))");$q->execute([$hash]);$id=(int)$db->lastInsertId();
  $payload=['text'=>"Saved answer\nwith a second line",'price'=>'357.174056904937269053','answered_at_utc'=>gmdate('c')];
  $q=$db->prepare("INSERT INTO rwa_chat_messages(session_id,request_id,status,user_text,action,assistant_json) VALUES (?,?,'complete',?,'message',?)");
  $args=[$id,$request,'Analyze TSLA',json_encode($payload,JSON_THROW_ON_ERROR)];$q->execute($args);
  $history=chatHistory($db,$id);
  if(count($history)!==1||$history[0]['reply']!==$payload)throw new RuntimeException('roundtrip_failed');
  $duplicateRejected=false;
  try{$q->execute($args);}catch(PDOException $e){if($e->getCode()!=='23000')throw $e;$duplicateRejected=true;}
  if(!$duplicateRejected)throw new RuntimeException('idempotency_failed');
  $q=$db->prepare('SELECT COUNT(*) FROM rwa_chat_messages WHERE session_id=? AND request_id=?');$q->execute([$id+1,$request]);
  if((int)$q->fetchColumn()!==0)throw new RuntimeException('isolation_failed');
  $db->rollBack();
  echo json_encode(['ok'=>true,'database_roundtrip'=>true,'precision_preserved'=>true,'request_id_unique'=>true,'session_isolation'=>true,'writes_rolled_back'=>true,'model_calls'=>0,'binance_calls'=>0],JSON_THROW_ON_ERROR)."\n";
 }elseif($mode==='--cleanup'){
  $count=$db->exec('DELETE FROM rwa_chat_sessions WHERE expires_at <= UTC_TIMESTAMP(3) LIMIT 2000');
  echo json_encode(['ok'=>true,'expired_sessions_deleted'=>$count,'utc'=>gmdate('c')],JSON_THROW_ON_ERROR)."\n";
 }else{fwrite(STDERR,"Usage: php chat-maintenance.php --migrate|--self-test|--cleanup\n");exit(1);}
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"Chat database operation failed. Check configuration, schema files and database privileges.\n");exit(1);}
