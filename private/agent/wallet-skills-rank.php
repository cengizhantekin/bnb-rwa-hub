<?php
declare(strict_types=1);
/**
 * Read-only server adapter following the Binance crypto-market-rank Skill's token-rank procedure.
 * Source: https://github.com/binance/binance-skills-hub/tree/main/skills/binance-web3/crypto-market-rank
 * This is NOT an installed Skill CLI or a Hubble LLM tool. Never claim otherwise.
 */

const WSR_ENDPOINT = 'https://web3.binance.com/bapi/defi/v1/public/wallet-direct/buw/wallet/market/token/pulse/unified/rank/list/ai';
const WSR_OK_TTL = 900;
const WSR_ERROR_TTL = 120;
const WSR_MAX_RESULT_ITEMS = 10;

function wsrContract(mixed $value): ?string {
    return is_string($value) && preg_match('/\A0[xX][a-fA-F0-9]{40}\z/', $value) ? strtolower($value) : null;
}
function wsrTicker(mixed $value): ?string {
    if (!is_string($value)) return null;
    $value = strtoupper(trim($value));
    return preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/', $value) ? $value : null;
}
function wsrSymbol(mixed $value): ?string {
    if (!is_string($value)) return null;
    $value = trim($value);
    return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,39}\z/', $value) ? $value : null;
}
function wsrPercent(mixed $value): ?float {
    if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
    if (!is_numeric($value)) return null;
    $n = (float)$value;
    return is_finite($n) && $n >= -100 && $n <= 100000 ? $n : null;
}
function wsrProvider(string $code): ?string {
    return ['bstock'=>'bStocks','ondo'=>'Ondo','xstocks'=>'xStocks'][$code] ?? null;
}
/** Map ONLY chain+exact-contract identities, never a ticker or symbol guess. */
function wsrCatalog(array $records): array {
    $lookup = [];
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        $contract = wsrContract($r['contract_address'] ?? null);
        $ticker = wsrTicker($r['underlying_ticker'] ?? null);
        $symbol = wsrSymbol($r['symbol'] ?? null);
        $provider = wsrProvider((string)($r['provider'] ?? ''));
        if ($contract === null || $ticker === null || $symbol === null || $provider === null) continue;
        if (!isset($lookup[$contract])) {
            $lookup[$contract] = ['ticker'=>$ticker,'symbol'=>$symbol,'provider'=>$provider];
        }
    }
    return $lookup;
}
/** Strictly normalize the documented data.tokens array. An empty valid list is not an error. */
function wsrNormalize(mixed $response, array $catalog): ?array {
    if (!is_array($response) || ($response['success'] ?? null) !== true
        || (string)($response['code'] ?? '') !== '000000'
        || !is_array($response['data'] ?? null)
        || !isset($response['data']['tokens']) || !is_array($response['data']['tokens'])
        || count($response['data']['tokens']) > 200) return null;

    $items = [];
    foreach ($response['data']['tokens'] as $i => $token) {
        if (!is_array($token) || (string)($token['chainId'] ?? '') !== '56') continue;
        $contract = wsrContract($token['contractAddress'] ?? null);
        $symbol = wsrSymbol($token['symbol'] ?? null);
        if ($contract === null || $symbol === null) continue;
        $matched = $catalog[$contract] ?? null;
        $items[] = [
            'position' => $i + 1, // Order in Binance's response, not a locally calculated financial rank.
            'symbol' => $symbol,
            'contract' => $contract,
            'change_24h_pct' => wsrPercent($token['percentChange24h'] ?? null),
            'matched_ticker' => $matched['ticker'] ?? null,
            'matched_symbol' => $matched['symbol'] ?? null,
            'provider' => $matched['provider'] ?? null,
        ];
        if (count($items) >= WSR_MAX_RESULT_ITEMS) break;
    }
    $total = $response['data']['total'] ?? null;
    $total = (is_int($total) || (is_string($total) && ctype_digit($total))) && (int)$total >= 0
        ? (int)$total : null;
    return ['items'=>$items, 'total'=>$total];
}

/** Fixed single POST; never follow redirects or expose a user-controlled URL. */
function wsrHttp(): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'http_requests'=>0];
    $body = json_encode(['rankType'=>40,'chainId'=>'56','period'=>50,
        'sortBy'=>0,'orderAsc'=>false,'page'=>1,'size'=>20], JSON_THROW_ON_ERROR);
    $ch = curl_init(WSR_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json', 'Accept: application/json',
            'Accept-Encoding: identity',
        ],
        CURLOPT_USERAGENT=>'binance-web3/3.0 (Skill)',
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $status !== 200 || !is_string($raw) || strlen($raw) > 262144) {
        return ['ok'=>false,'http_requests'=>1];
    }
    $value = json_decode($raw, true);
    return is_array($value) ? ['ok'=>true, 'data'=>$value, 'http_requests'=>1] : ['ok'=>false,'http_requests'=>1];
}
function wsrTakeBudget(string $directory): bool {
    $f = @fopen($directory . '/outbound-budget.json', 'c+');
    if (!$f) return false;
    @chmod($directory . '/outbound-budget.json', 0600);
    try {
        if (!flock($f, LOCK_EX)) return false;
        $state = json_decode((string)stream_get_contents($f), true);
        $now = time();
        $start = (int)($state['start'] ?? 0);
        $used = (int)($state['used'] ?? 0);
        if ($start <= 0 || $now < $start || $now - $start >= 3600) { $start=$now; $used=0; }
        if ($used >= 12) return false;
        rewind($f);
        if (!ftruncate($f, 0)) return false;
        $success = fwrite($f, json_encode(['start'=>$start, 'used'=>$used+1], JSON_THROW_ON_ERROR));
        fflush($f);
        return $success !== false;
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}
/** Single-flight cache, bounded upstream calls and a separate short error TTL. */
function wsrLoad(array $catalogRows, ?callable $fetch = null, ?string $cacheDir = null): array {
    $dir = $cacheDir ?? sys_get_temp_dir() . '/bhub-wallet-rank-v1';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return ['ok'=>false,'error'=>'cache_unavailable'];
    if (is_link($dir) || (((int)@fileperms($dir)) & 0077) !== 0) return ['ok'=>false,'error'=>'cache_unavailable'];
    $cache = $dir . '/bsc-stock-rank.json';
    $lock = @fopen($dir . '/cache.lock', 'c+');
    if (!$lock) return ['ok'=>false,'error'=>'cache_unavailable'];
    @chmod($dir . '/cache.lock', 0600);
    if (!flock($lock, LOCK_EX)) { fclose($lock); return ['ok'=>false,'error'=>'cache_unavailable']; }
    try {
        $now = time();
        if (is_file($cache)) {
            $value = json_decode((string)@file_get_contents($cache), true);
            if (is_array($value) && (int)($value['expires'] ?? 0) > $now && is_array($value['payload'] ?? null)) {
                $out = $value['payload'];
                $out['cache_hit'] = true;
                $out['http_requests'] = 0;
                $out['duration_ms'] = 0.0;
                return $out;
            }
        }
        $allowed = wsrTakeBudget($dir);
        $started = microtime(true);
        $response = $allowed ? ($fetch ?? 'wsrHttp')() : ['ok'=>false];
        $normalized = ($response['ok'] ?? false) === true
            ? wsrNormalize($response['data'] ?? null, wsrCatalog($catalogRows)) : null;
        $elapsed = round((microtime(true) - $started) * 1000, 1);
        $payload = [
            'ok'=>true,
            'source'=>'binance_wallet_skills',
            'skill'=>'crypto-market-rank',
            'chain_id'=>'56',
            'rank_type'=>40,
            'period'=>'24h',
            'available'=>$normalized !== null,
            'items'=>$normalized['items'] ?? [],
            'total'=>$normalized['total'] ?? null,
            'fetched_at_utc'=>$allowed ? gmdate('Y-m-d\TH:i:s\Z') : null,
            'ttl_seconds'=>$normalized !== null ? WSR_OK_TTL : WSR_ERROR_TTL,
            'cache_hit'=>false,
            'http_requests'=>$allowed ? (int)($response['http_requests'] ?? 1) : 0,
            'duration_ms'=>$elapsed,
            'request_weight'=>null,
        ];
        $tmp = $cache . '.' . bin2hex(random_bytes(5));
        $bytes = @file_put_contents($tmp, json_encode(['expires'=>$now+$payload['ttl_seconds'],'payload'=>$payload], JSON_THROW_ON_ERROR), LOCK_EX);
        if ($bytes === false || !@rename($tmp, $cache)) { @unlink($tmp); return ['ok'=>false,'error'=>'cache_unavailable']; }
        @chmod($cache, 0600);
        return $payload;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
