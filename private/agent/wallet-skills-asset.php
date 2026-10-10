<?php
declare(strict_types=1);
/**
 * Read-only implementation of two published Binance Wallet Skill procedures.
 * Official specifications (consult before changing response handling):
 * https://github.com/binance/binance-skills-hub/tree/main/skills/binance-web3/binance-tokenized-securities-info
 * https://github.com/binance/binance-skills-hub/tree/main/skills/binance-web3/query-token-audit
 *
 * Runs from the PHP server, not the Hubble agent. No wallet, signing, or approval capability.
 */

const WS_CACHE_TTL = 900;
const WS_ERROR_TTL = 120;
const WS_AUDIT_URI = 'https://web3.binance.com/bapi/defi/v1/public/wallet-direct/security/token/audit';
const WS_RWA_BASE = 'https://www.binance.com/bapi/defi/';

function wsContract(mixed $address): ?string {
    if (!is_string($address) || !preg_match('/\A0x[a-fA-F0-9]{40}\z/', $address)) return null;
    return strtolower($address);
}
function wsString(mixed $value, int $length = 180): ?string {
    if (!is_string($value) || trim($value) === '') return null;
    return function_exists('mb_substr') ? mb_substr(trim($value), 0, $length, 'UTF-8') : substr(trim($value), 0, $length);
}
function wsNumber(mixed $value): ?float {
    if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
    if (!is_numeric($value)) return null;
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}
function wsTime(mixed $millis): ?string {
    $number = wsNumber($millis);
    if ($number === null || $number < 946684800000 || $number > 4102444800000) return null;
    return gmdate('Y-m-d\TH:i:s\Z', (int)floor($number / 1000));
}
function wsReportUrl(mixed $value): ?string {
    if (!is_string($value) || strlen($value) > 350) return null;
    if (!preg_match('~\A/images/web3-data/public/token/ondo/pdf/[a-zA-Z0-9/_-]+\.pdf\z~', $value)) return null;
    return 'https://bin.bnbstatic.com' . $value;
}
function wsEnvelope(mixed $raw): ?array {
    if (!is_array($raw) || ($raw['success'] ?? null) !== true || (string)($raw['code'] ?? '') !== '000000' || !is_array($raw['data'] ?? null)) return null;
    return $raw['data'];
}
function wsAudit(mixed $raw): array {
    $data = wsEnvelope($raw);
    if ($data === null || ($data['hasResult'] ?? null) !== true || ($data['isSupported'] ?? null) !== true) {
        return ['available' => false];
    }
    $level = $data['riskLevelEnum'] ?? null;
    $riskNum = wsNumber($data['riskLevel'] ?? null);
    if (!in_array($level, ['LOW', 'MEDIUM', 'HIGH'], true) || $riskNum === null || $riskNum < 0 || $riskNum > 5
        || ($level === 'LOW' && $riskNum > 1) || ($level === 'MEDIUM' && ($riskNum < 2 || $riskNum > 3))
        || ($level === 'HIGH' && $riskNum < 4)) {
        return ['available' => false];
    }
    $extra = is_array($data['extraInfo'] ?? null) ? $data['extraInfo'] : [];
    $flags = [];
    foreach ((is_array($data['riskItems'] ?? null) ? $data['riskItems'] : []) as $item) {
        if (!is_array($item) || !is_array($item['details'] ?? null)) continue;
        foreach ($item['details'] as $detail) {
            if (!is_array($detail) || ($detail['isHit'] ?? false) !== true) continue;
            $title = wsString($detail['title'] ?? null, 120);
            if ($title === null) continue;
            $flags[] = [
                'type' => in_array($detail['riskType'] ?? '', ['RISK', 'CAUTION'], true) ? $detail['riskType'] : 'CAUTION',
                'title' => $title,
                'description' => wsString($detail['description'] ?? null, 250),
            ];
            if (count($flags) >= 12) break 2;
        }
    }
    $tax = static function (mixed $v): ?float {
        $n = wsNumber($v);
        return $n !== null && $n >= 0 && $n <= 100 ? $n : null;
    };
    return [
        'available' => true,
        'risk' => $level,
        'risk_level' => (int)$riskNum,
        'verified' => is_bool($extra['isVerified'] ?? null) ? $extra['isVerified'] : null,
        'buy_tax_pct' => $tax($extra['buyTax'] ?? null),
        'sell_tax_pct' => $tax($extra['sellTax'] ?? null),
        'flags' => $flags,
    ];
}
function wsOndo(array $rawResults, string $ticker): array {
    $market = wsEnvelope($rawResults['market'] ?? null);
    $meta = wsEnvelope($rawResults['meta'] ?? null);
    $dynamic = wsEnvelope($rawResults['dynamic'] ?? null);
    // Never attribute another underlying's facts to the requested asset.
    if ($meta !== null && strtoupper((string)($meta['ticker'] ?? '')) !== $ticker) $meta = null;
    if ($dynamic !== null && strtoupper((string)($dynamic['ticker'] ?? '')) !== $ticker) $dynamic = null;
    $status = $market === null ? null : [
        'open' => is_bool($market['openState'] ?? null) ? $market['openState'] : null,
        'session' => wsString($market['marketStatus'] ?? null, 32),
        'reason_code' => wsString($market['reasonCode'] ?? null, 48),
        'reason' => wsString($market['reasonMsg'] ?? null, 100),
        'next_open_utc' => wsTime($market['nextOpenTime'] ?? null),
        'next_close_utc' => wsTime($market['nextCloseTime'] ?? null),
    ];
    if ($status !== null && $status['open'] === null && $status['reason_code'] === null) $status = null;
    $stock = is_array($dynamic['stockInfo'] ?? null) ? $dynamic['stockInfo'] : null;
    $token = is_array($dynamic['tokenInfo'] ?? null) ? $dynamic['tokenInfo'] : null;
    $company = is_array($meta['companyInfo'] ?? null) ? $meta['companyInfo'] : null;
    return [
        'status' => $status,
        'fundamentals' => $stock === null ? null : [
            'pe_ttm' => wsNumber($stock['priceToEarnings'] ?? null),
            'dividend_yield_pct' => wsNumber($stock['dividendYield'] ?? null),
            'high_52w_usd' => wsNumber($stock['priceHigh52w'] ?? null),
            'low_52w_usd' => wsNumber($stock['priceLow52w'] ?? null),
        ],
        'supply' => $token === null ? null : [
            'holders_cross_chain' => wsNumber($token['totalHolders'] ?? null),
            'circulating_bsc_tokens' => wsNumber($token['circulatingSupply'] ?? null),
            'market_cap_bsc_usd' => wsNumber($token['marketCap'] ?? null),
        ],
        'company' => $company === null ? null : [
            'industry' => wsString($company['industry'] ?? null, 90),
            'ceo' => wsString($company['ceo'] ?? null, 90),
        ],
        'reports' => $meta === null ? null : [
            'daily' => wsReportUrl($meta['dailyAttestationReports'] ?? null),
            'monthly' => wsReportUrl($meta['monthlyAttestationReports'] ?? null),
        ],
    ];
}
/** Limit outbound cold-cache bursts across all visitors; cache hits never spend quota. */
function wsTakeBudget(string $dir): bool {
    $file = @fopen($dir . '/outbound-budget.json', 'c+');
    if (!$file) return false;
    try {
        if (!flock($file, LOCK_EX)) return false;
        $state = json_decode((string)stream_get_contents($file), true);
        $now = time();
        $start = is_array($state) ? (int)($state['start'] ?? 0) : 0;
        $used = is_array($state) ? (int)($state['used'] ?? 0) : 0;
        if ($start <= 0 || $now - $start >= 60 || $now < $start) { $start = $now; $used = 0; }
        if ($used >= 10) return false;
        rewind($file);
        if (!ftruncate($file, 0)) return false;
        $wrote = fwrite($file, json_encode(['start'=>$start,'used'=>$used + 1], JSON_THROW_ON_ERROR));
        fflush($file);
        return $wrote !== false;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

/** Fixed destination hosts; never follow redirects or interpolate user-controlled URLs. */
function wsHttpBatch(array $requests): array {
    if (!function_exists('curl_multi_init')) return [];
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $key => $req) {
        $ch = curl_init($req['url']);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $req['audit'] ? 'binance-web3/1.4 (Skill)' : 'binance-web3/1.1 (Skill)',
            CURLOPT_HTTPHEADER => $req['audit']
                ? ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: identity', 'source: agent']
                : ['Accept: application/json', 'Accept-Encoding: identity'],
        ];
        if ($req['audit']) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($req['body'], JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($multi, $ch);
        $handles[$key] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) break;
        if ($running > 0) curl_multi_select($multi, 0.5);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        if (curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200
            && is_string($body) && strlen($body) <= 500000) {
            try { $out[$key] = json_decode($body, true, 48, JSON_THROW_ON_ERROR); } catch (Throwable) { /* unavailable */ }
        }
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $out;
}
function wsBuildRequests(array $representations): array {
    $requests = [];
    $auditCount = 0;
    foreach ($representations as $i => $rep) {
        $contract = wsContract($rep['contract_address'] ?? null);
        if ($contract === null || $auditCount >= 4) continue;
        $uuid = bin2hex(random_bytes(16));
        $uuid[12] = '4';
        $uuid[16] = dechex((hexdec($uuid[16]) & 3) | 8);
        $uuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20);
        $requests['audit_' . $i] = [
            'url' => WS_AUDIT_URI, 'audit' => true,
            'body' => ['binanceChainId' => '56', 'contractAddress' => $contract, 'requestId' => $uuid],
        ];
        $auditCount++;
        if ($rep['provider'] === 'ondo' && !isset($requests['market'])) {
            $qs = '?chainId=56&contractAddress=' . rawurlencode($contract);
            $root = WS_RWA_BASE . 'v1/public/wallet-direct/buw/wallet/market/token/rwa/';
            $requests['market'] = ['url' => $root . 'asset/market/status/ai' . $qs, 'audit' => false];
            $requests['meta'] = ['url' => $root . 'meta/ai' . $qs, 'audit' => false];
            $requests['dynamic'] = ['url' => WS_RWA_BASE . 'v2/public/wallet-direct/buw/wallet/market/token/rwa/dynamic/ai' . $qs, 'audit' => false];
        }
    }
    return $requests;
}
function wsCompile(array $representations, string $ticker, array $responses): array {
    $audits = [];
    $auditAvailable = 0;
    foreach ($representations as $i => $rep) {
        $result = wsAudit($responses['audit_' . $i] ?? null);
        if ($result['available']) $auditAvailable++;
        $audits[] = ['provider' => $rep['provider'], 'symbol' => $rep['symbol'], 'contract' => $rep['contract_address'], 'audit' => $result];
    }
    $ondo = null;
    foreach ($representations as $rep) {
        if ($rep['provider'] === 'ondo' && wsContract($rep['contract_address']) !== null) {
            $ondo = wsOndo($responses, $ticker);
            $ondo['symbol'] = $rep['symbol'];
            $ondo['contract'] = $rep['contract_address'];
            break;
        }
    }
    $ondoAvailable = $ondo !== null && ($ondo['status'] !== null || $ondo['fundamentals'] !== null || $ondo['company'] !== null || $ondo['reports'] !== null);
    return ['available' => $auditAvailable > 0 || $ondoAvailable, 'audits' => $audits, 'ondo' => $ondo];
}
function wsLoadAsset(array $representations, string $ticker, ?callable $fetch = null, ?string $cacheRoot = null): array {
    // The caller MUST supply only DB-verified, active BSC token representations.
    $records = [];
    $seen = [];
    foreach ($representations as $r) {
        if (!is_array($r) || !in_array($r['provider'] ?? '', ['bstock','ondo','xstocks'], true)) continue;
        $contract = wsContract($r['contract_address'] ?? null);
        if ($contract === null || isset($seen[$contract])) continue;
        $seen[$contract] = true;
        $records[] = ['provider' => $r['provider'], 'symbol' => wsString($r['symbol'] ?? null, 40) ?? 'Unknown', 'contract_address' => $contract];
        if (count($records) === 4) break;
    }
    if (!$records) return ['ok' => true, 'available' => false, 'audits' => [], 'ondo' => null, 'http_requests' => 0, 'request_weight' => null];
    $path = ($cacheRoot ?? sys_get_temp_dir() . '/bhub-wallet-skills-v1');
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) return ['ok'=>false, 'error'=>'skill_cache_unavailable'];
    $cacheKey = hash('sha256', $ticker . '|' . json_encode($records, JSON_THROW_ON_ERROR));
    $cachePath = $path . '/' . $cacheKey . '.json';
    $lockFile = @fopen($path . '/' . $cacheKey . '.lock', 'c');
    if (!$lockFile || !flock($lockFile, LOCK_EX)) return ['ok'=>false, 'error'=>'skill_cache_unavailable'];
    try {
        $now = time();
        if (is_file($cachePath)) {
            $data = json_decode((string)@file_get_contents($cachePath), true);
            if (is_array($data) && ($data['expires'] ?? 0) > $now && is_array($data['payload'] ?? null)) {
                $cached = $data['payload'];
                $cached['http_requests'] = 0;
                $cached['duration_ms'] = 0.0;
                $cached['cache_hit'] = true;
                return $cached;
            }
        }
        if (!wsTakeBudget($path)) return ['ok'=>false, 'error'=>'skill_rate_limited'];
        $requests = wsBuildRequests($records);
        $start = microtime(true);
        $responses = ($fetch ?? 'wsHttpBatch')($requests);
        $elapsed = round((microtime(true) - $start) * 1000, 1);
        $result = wsCompile($records, $ticker, is_array($responses) ? $responses : []);
        $payload = [
            'ok' => true,
            'source' => 'binance_wallet_skills',
            'chain_id' => '56',
            'ticker' => $ticker,
            'fetched_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'ttl_seconds' => $result['available'] ? WS_CACHE_TTL : WS_ERROR_TTL,
            'available' => $result['available'],
            'audits' => $result['audits'],
            'ondo' => $result['ondo'],
            'duration_ms' => $elapsed,
            'http_requests' => count($requests),
            'cache_hit' => false,
            'request_weight' => null,  // Public BAPI weight is not documented; never assume zero.
        ];
        $file = $cachePath . '.' . bin2hex(random_bytes(5));
        if (@file_put_contents($file, json_encode(['expires' => $now + $payload['ttl_seconds'], 'payload' => $payload], JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !@rename($file, $cachePath)) {
            @unlink($file);
            return ['ok'=>false, 'error'=>'skill_cache_unavailable'];
        }
        @chmod($cachePath, 0600);
        return $payload;
    } finally {
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
    }
}
