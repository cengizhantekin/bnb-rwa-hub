<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function skillRespond(array $result, int $status = 200): never {
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$ticker = $_GET['ticker'] ?? null;
$ticker = is_string($ticker) ? strtoupper(trim($ticker)) : '';
if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/', $ticker)) {
    skillRespond(['ok'=>false, 'error'=>'invalid_ticker'], 400);
}

try {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    require '/home/bhub.tm.biz.tr/private/agent/wallet-skills-asset.php';
    $db = chatDb();
    $statement = $db->prepare(
        "SELECT provider, symbol, contract_address
         FROM rwa_tokens
         WHERE chain_id='56' AND is_active=1 AND UPPER(underlying_ticker)=?
           AND provider IN ('bstock','ondo','xstocks')
         ORDER BY FIELD(provider,'bstock','ondo','xstocks'), symbol ASC
         LIMIT 8"
    );
    $statement->execute([$ticker]);
    $reps = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (!$reps) skillRespond(['ok'=>false, 'error'=>'asset_not_found'], 404);
    $result = wsLoadAsset($reps, $ticker);
    if (($result['ok'] ?? false) !== true) {
        error_log('[asset-skills-api] ' . $ticker . ': ' . ($result['error'] ?? 'unknown_error'));
        skillRespond(['ok'=>false, 'error'=>'skills_temporarily_unavailable'], 503);
    }
    skillRespond($result);
} catch (Throwable $exception) {
    error_log('[asset-skills-api] ' . $ticker . ': ' . $exception->getMessage());
    skillRespond(['ok'=>false, 'error'=>'skills_temporarily_unavailable'], 503);
}
