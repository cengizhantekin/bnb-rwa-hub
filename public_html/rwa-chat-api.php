<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
function chatContextText(string $text):string {
    $text=substr($text,0,600);
    while($text!=='' && preg_match('//u',$text)!==1)$text=substr($text,0,-1);
    return $text;
}
function respond(array $body,int $status=200):never {
    // One authoritative VPS clock for the browser's cooldown deadline comparison.
    $body['server_now_utc']=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
try {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    require '/home/bhub.tm.biz.tr/private/agent/chat-assets.php';
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true))respond(['ok'=>false,'error'=>'method_not_allowed'],405);
    if($method==='POST') {
        if(strtolower(explode(';',$_SERVER['CONTENT_TYPE']??'')[0])!=='application/json')respond(['ok'=>false,'error'=>'json_required'],415);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)respond(['ok'=>false,'error'=>'request_too_large'],413);
    }
    $db=chatDb();$session=chatSession($db);$id=(int)$session['id'];
    if($method==='GET') respond(['ok'=>true,'csrf'=>$session['csrf'],'messages'=>chatHistory($db,$id)]);
    $raw=file_get_contents('php://input',false,null,0,8193);
    if($raw===false||strlen($raw)>8192)respond(['ok'=>false,'error'=>'request_too_large'],413);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!is_string($input['csrf']??null)||!hash_equals($session['csrf'],$input['csrf']))respond(['ok'=>false,'error'=>'csrf_failed'],403);
    if(isset($input['message'])&&!is_string($input['message']))respond(['ok'=>false,'error'=>'invalid_message'],400);
    $request=$input['request_id']??'';$action=$input['action']??'message';$ticker=$input['ticker']??null;$text=trim((string)($input['message']??''));
    if(!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/',$request)||!is_string($action)||!in_array($action,['message','discover','more','detail','compare','refresh','buy','help','new','delete'],true)
      ||!($ticker===null||(is_string($ticker)&&preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/',$ticker)))||strlen($text)>2000||($action==='message'&&$text===''))respond(['ok'=>false,'error'=>'invalid_message'],400);
    $lock='rwa_chat_'.$id;
    $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1)respond(['ok'=>false,'error'=>'conversation_busy'],409);
    // Connection-scoped lock serializes turns across tabs; never hold a DB transaction during model calls.
    try {
        $q=$db->prepare('SELECT state_json FROM rwa_chat_sessions WHERE id=?');$q->execute([$id]);$stateRaw=$q->fetchColumn();
        if($stateRaw===false)respond(['ok'=>false,'error'=>'conversation_expired'],409);
        if(in_array($action,['new','delete'],true)) {
            $q=$db->prepare('DELETE FROM rwa_chat_sessions WHERE id=?');$q->execute([$id]);
            setcookie('__Host-rwa_chat','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
            respond(['ok'=>true,'reset'=>true]);
        }
        $q=$db->prepare('SELECT user_text,action,status FROM rwa_chat_messages WHERE session_id=? AND request_id=?');$q->execute([$id,$request]);$existing=$q->fetch();
        if($existing) {
            if($existing['user_text']!==$text||$existing['action']!==$action)respond(['ok'=>false,'error'=>'request_id_conflict'],409);
            // A crashed/unknown request is not reissued silently, avoiding duplicate model use.
            respond(['ok'=>true,'csrf'=>$session['csrf'],'messages'=>chatHistory($db,$id),'replayed'=>true]);
        }
        $q=$db->prepare('SELECT COUNT(*), SUM(created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)) FROM rwa_chat_messages WHERE session_id=?');$q->execute([$id]);$limit=$q->fetch(PDO::FETCH_NUM);
        if((int)$limit[0]>=200)respond(['ok'=>false,'error'=>'conversation_full'],429);
        if((int)$limit[1]>=30)respond(['ok'=>false,'error'=>'please_wait'],429);
        $q=$db->prepare("INSERT INTO rwa_chat_messages(session_id,request_id,status,user_text,action) VALUES (?,?,'pending',?,?)");$q->execute([$id,$request,$text,$action]);$messageId=(int)$db->lastInsertId();
        // Server-owned bounded context: last three completed turns, text only. No tables/cards/state.
        $q=$db->prepare("SELECT LEFT(user_text,600) AS user_text, action, assistant_json FROM rwa_chat_messages WHERE session_id=? AND status='complete' ORDER BY id DESC LIMIT 3");$q->execute([$id]);
        $context=[];
        foreach(array_reverse($q->fetchAll()) as $turn) {
            $answer=json_decode((string)$turn['assistant_json'],true,64);
            $user=(string)$turn['user_text'];
            if($user==='')$user='Requested app action: '.(string)$turn['action'];
            $context[]=['role'=>'user','text'=>chatContextText($user)];
            if(is_array($answer)&&is_string($answer['text']??null)) {
                $prefix=is_string($answer['ticker']??null)?'Asset: '.$answer['ticker'].". ":'';
                $context[]=['role'=>'assistant','text'=>chatContextText($prefix.$answer['text'])];
            }
        }
        $state=json_decode((string)$stateRaw,true,64,JSON_THROW_ON_ERROR);
        try {$reply=chatService(['message'=>$text,'action'=>$action,'ticker'=>$ticker,'state'=>$state?:new stdClass(),'context'=>$context]);}
        catch(Throwable $e){$reply=['ok'=>true,'state'=>$state,'text'=>'The agent service is temporarily unavailable. Your message and conversation are saved. Please try again later.','cards'=>[],'tables'=>[],'actions'=>[],'model_status'=>'unavailable','model_requests'=>null,'binance_http_requests'=>0,'binance_request_weight'=>0,'answered_at_utc'=>gmdate('c')];}
        if(is_string($reply['ticker']??null))$reply['asset']=chatAsset($db,$reply['ticker'],is_string($reply['asset_provider']??null)?$reply['asset_provider']:null);
        $nextState=$reply['state'];
        // Preserve the model-ranking cooldown across the service -> PHP -> stored-reply boundary.
        // The browser may render a saved reply later, so store an absolute deadline as well as seconds.
        $rankDeadline=is_array($nextState)&&is_string($nextState['next_rank_at_utc']??null)?$nextState['next_rank_at_utc']:null;
        if($rankDeadline!==null){
            $deadlineTs=strtotime($rankDeadline);
            $hasMore=false;
            foreach(($reply['actions']??[]) as $replyAction){
                if(is_array($replyAction)&&($replyAction['action']??null)==='more'){$hasMore=true;break;}
            }
            if($hasMore&&$deadlineTs!==false&&$deadlineTs>time()){
                $reply['retry_after_until_utc']=$rankDeadline;
                $reply['retry_after_seconds']=max(1,min(300,$deadlineTs-time()));
            }
        }
        unset($reply['state']);
        $db->beginTransaction();
        $q=$db->prepare('UPDATE rwa_chat_sessions SET state_json=? WHERE id=?');$q->execute([json_encode($nextState?:new stdClass(),JSON_THROW_ON_ERROR),$id]);
        $q=$db->prepare("UPDATE rwa_chat_messages SET status='complete',assistant_json=? WHERE id=?");$q->execute([json_encode($reply,JSON_THROW_ON_ERROR),$messageId]);$db->commit();
        respond(['ok'=>true,'csrf'=>$session['csrf'],'messages'=>chatHistory($db,$id)]);
    }finally{if($db->inTransaction())$db->rollBack();$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}catch(Throwable $e){error_log('[rwa-chat] request failed: '.get_class($e));respond(['ok'=>false,'error'=>'chat_unavailable'],503);}
