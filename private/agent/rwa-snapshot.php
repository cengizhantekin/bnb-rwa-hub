<?php
declare(strict_types=1);

// CLI-only database reader. No API calls, model calls, writes or transactions on-chain.
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
function emit(array $payload, int $code = 0): never {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    exit($code);
}
$catalog = ($argv[1] ?? '') === '--catalog';
$ticker = strtoupper(trim((string) ($argv[1] ?? '')));
if (count($argv) !== 2 || (!$catalog && !preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,15}\z/', $ticker))) {
    emit(['ok' => false, 'error' => 'invalid_ticker'], 1);
}
$started = hrtime(true);
try {
    $config = require '/home/bhub.tm.biz.tr/private/config.php';
    if (!is_array($config)) throw new RuntimeException('Invalid configuration');
    $pdo = new PDO(
        'mysql:host=' . ($config['db_host'] ?? 'localhost') . ';dbname=' . ($config['db_name'] ?? '') . ';charset=utf8mb4',
        (string) ($config['db_user'] ?? ''),
        (string) ($config['db_password'] ?? $config['db_pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false,
         PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec('SET SESSION max_statement_time = 2');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    if ($catalog) {
        $rows = $pdo->query("SELECT underlying_ticker AS ticker, COUNT(*) AS variants,
            COUNT(DISTINCT provider) AS providers,
            LEFT(COALESCE(MAX(CASE WHEN provider = 'ondo' THEN NULLIF(TRIM(underlying_name), '') END),
                 MAX(NULLIF(TRIM(underlying_name), ''))), 128) AS name FROM rwa_tokens
            WHERE is_active = 1 AND chain_id = '56'
              AND provider IN ('bstock','ondo','xstocks')
              AND BINARY underlying_ticker REGEXP '^[A-Z0-9][A-Z0-9._-]{0,15}$'
            GROUP BY underlying_ticker HAVING COUNT(*) BETWEEN 2 AND 10
              AND COUNT(DISTINCT provider) >= 2 ORDER BY underlying_ticker LIMIT 2001")->fetchAll();
        $pdo->commit();
        if (count($rows) > 2000) emit(['ok'=>false,'error'=>'catalog_too_large'], 1);
        foreach ($rows as &$row) { $row['variants']=(int)$row['variants']; $row['providers']=(int)$row['providers']; }
        unset($row);
        emit(['ok'=>true,'action'=>'rwa_catalog','chain_id'=>'56','source'=>'database_catalog',
              'tickers'=>$rows,'ticker_count'=>count($rows),'captured_at_utc'=>gmdate('c'),
              'db_time_ms'=>round((hrtime(true)-$started)/1e6,2),
              'binance_http_requests'=>0,'binance_request_weight'=>0,'model_called'=>false]);
    }
    $stmt = $pdo->prepare(
        "SELECT t.provider, t.symbol, t.underlying_ticker, t.chain_id, t.contract_address,
         m.price, m.reference_price, m.rwa_token_price, m.liquidity, m.volume_24h,
         m.holders, m.price_change_24h, m.price_time, m.rwa_price_updated_at,
         m.general_api_updated_at, m.rwa_api_updated_at,
         TIMESTAMPDIFF(SECOND, m.price_time, UTC_TIMESTAMP()) AS market_price_age_seconds,
         TIMESTAMPDIFF(SECOND, m.rwa_price_updated_at, UTC_TIMESTAMP()) AS rwa_price_age_seconds,
         TIMESTAMPDIFF(SECOND, m.general_api_updated_at, UTC_TIMESTAMP()) AS market_fetch_age_seconds,
         TIMESTAMPDIFF(SECOND, m.rwa_api_updated_at, UTC_TIMESTAMP()) AS rwa_fetch_age_seconds
         FROM rwa_tokens t LEFT JOIN rwa_market_latest m ON m.token_id = t.id
         WHERE t.is_active = 1 AND t.chain_id = '56' AND t.underlying_ticker = :ticker
           AND t.provider IN ('bstock','ondo','xstocks')
         ORDER BY t.provider, t.symbol LIMIT 11"
    );
    $stmt->execute(['ticker' => $ticker]);
    $tokens = $stmt->fetchAll();
    $pdo->commit();
    if (count($tokens) > 10) emit(['ok' => false, 'error' => 'too_many_variants'], 1);
    $warnings = [
        'This is a database snapshot, not a live Binance request. Read source ages and fetch ages separately.',
        'Missing liquidity is unknown; zero AMM liquidity does not establish that RFQ is unavailable.',
        'volume_24h units and token versus underlying-asset scope are unverified; do not rank token volume.',
        'The reference price has no separate source timestamp here; its independent source is not disproved.',
        'No executable quote, slippage or fees are supplied; price differences do not establish executable arbitrage.'
    ];
    foreach ($tokens as &$token) {
        $token['market_snapshot_present'] = $token['general_api_updated_at'] !== null;
        $token['rwa_snapshot_present'] = $token['rwa_api_updated_at'] !== null;
        foreach (['market_price_age_seconds', 'rwa_price_age_seconds', 'market_fetch_age_seconds', 'rwa_fetch_age_seconds'] as $field) {
            $token[$field] = $token[$field] === null ? null : (int) $token[$field];
            $token[$field . '_status'] = $token[$field] === null ? 'missing'
                : ($token[$field] < 0 ? 'clock_inconsistency' : ($token[$field] <= 300 ? 'fresh' : 'stale'));
        }
    }
    unset($token);
    emit([
        'ok' => true, 'action' => 'rwa_snapshot', 'ticker' => $ticker, 'chain_id' => '56',
        'source' => 'database_snapshot', 'captured_at_utc' => gmdate('c'),
        'found' => count($tokens) > 0, 'token_count' => count($tokens), 'tokens' => $tokens,
        'quality_warnings' => $warnings, 'freshness_threshold_seconds' => 300,
        'db_time_ms' => round((hrtime(true) - $started) / 1e6, 2),
        'binance_http_requests' => 0, 'binance_request_weight' => 0, 'model_called' => false
    ]);
} catch (Throwable $e) {
    // Never expose exception messages, DSNs, credentials, or configuration paths.
    emit(['ok' => false, 'error' => 'database_snapshot_unavailable'], 1);
}
