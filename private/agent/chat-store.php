<?php
declare(strict_types=1);
// Private application helper, placed outside public_html. No raw credentials in responses.
const CHAT_TTL = 604800;
function chatDb(): PDO {
    $c = require '/home/bhub.tm.biz.tr/private/config.php';
    $db = new PDO('mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . ($c['db_name'] ?? '') . ';charset=utf8mb4',
        (string)($c['db_user'] ?? ''), (string)($c['db_password'] ?? $c['db_pass'] ?? ''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function chatCookie(string $token): void {
    // This deployment is HTTPS-only; no forwarded-host/proto trust.
    setcookie('__Host-rwa_chat', $token, ['expires'=>time()+CHAT_TTL,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function chatSession(PDO $db): array {
    $token = $_COOKIE['__Host-rwa_chat'] ?? '';
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) $token = '';
    $row = null;
    if ($token !== '') {
        $q = $db->prepare('SELECT * FROM rwa_chat_sessions WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP(3)');
        $q->execute([hash('sha256',$token)]); $row=$q->fetch();
    }
    if (!$row) {
        $token=bin2hex(random_bytes(32));
        $q=$db->prepare("INSERT INTO rwa_chat_sessions (token_hash,state_json,expires_at) VALUES (?, '{}', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 7 DAY))");
        $q->execute([hash('sha256',$token)]);
        $row=['id'=>$db->lastInsertId(),'state_json'=>'{}'];
    }
    $q=$db->prepare('UPDATE rwa_chat_sessions SET last_active_at=UTC_TIMESTAMP(3), expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 7 DAY) WHERE id=?');
    $q->execute([$row['id']]); chatCookie($token);
    $row['csrf']=hash('sha256', 'rwa-chat-csrf:' . $token);
    return $row;
}
function chatHistory(PDO $db, int $id): array {
    $q=$db->prepare('SELECT id, request_id, user_text, assistant_json, status, created_at FROM rwa_chat_messages WHERE session_id=? ORDER BY id ASC LIMIT 201');
    $q->execute([$id]); $out=[];
    foreach ($q as $r) {
        $payload=$r['assistant_json'] === null ? null : json_decode($r['assistant_json'],true,64,JSON_THROW_ON_ERROR);
        $out[]=['id'=>(int)$r['id'],'request_id'=>$r['request_id'],'user_text'=>$r['user_text'],'status'=>$r['status'],'created_at_utc'=>str_replace(' ','T',$r['created_at']).'Z','reply'=>$payload];
    }
    return $out;
}
function chatService(array $input): array {
    $body=''; $c=curl_init('http://127.0.0.1:9000/rwa/chat');
    if ($c===false) throw new RuntimeException('service_unavailable');
    try {
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($input,JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>58,
            CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int {if(strlen($body)+strlen($chunk)>1500000)return 0;$body.=$chunk;return strlen($chunk);}]);
        if(curl_exec($c)===false || curl_getinfo($c,CURLINFO_HTTP_CODE)!==200)throw new RuntimeException('service_unavailable');
        $r=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['ok']??false)!==true||!is_array($r['state']??null)||!is_string($r['text']??null))throw new RuntimeException('invalid_reply');
        return $r;
    }finally{curl_close($c);}
}
function chatDashboard(PDO $db): array {
    $r=$db->query("SELECT COUNT(*) AS token_records, COUNT(DISTINCT underlying_ticker) AS underlying_assets, COUNT(DISTINCT provider) AS providers FROM rwa_tokens WHERE is_active=1 AND chain_id='56' AND provider IN ('bstock','ondo','xstocks')")->fetch();
    $m=$db->query("SELECT MAX(m.general_api_updated_at) AS market_fetch_utc, MAX(m.rwa_api_updated_at) AS rwa_fetch_utc FROM rwa_market_latest m JOIN rwa_tokens t ON t.id=m.token_id WHERE t.is_active=1 AND t.chain_id='56'")->fetch();
    return array_merge($r,$m);
}
