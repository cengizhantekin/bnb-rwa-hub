<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');

    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit("This sync script is CLI-only outside localhost.\n");
    }
}

$fullHeaders = PHP_SAPI === 'cli'
    ? in_array('--full-headers', $argv ?? [], true)
    : isset($_GET['fullheaders']);

$configFiles = [
    '/home/bhub.tm.biz.tr/private/config.php',
    'C:\\www\\htdocs\\_private\\bhub\\config.php',
    '/home1/occnxzte/private/bhub/config.php',
];

$configFile = null;

foreach ($configFiles as $file) {
    if (is_readable($file)) {
        $configFile = $file;
        break;
    }
}

if ($configFile === null) {
    exit("ERROR: config.php bulunamadı.\n");
}

$config = require $configFile;

function configValue(array $config, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $config)) {
            return $config[$key];
        }
    }

    return $default;
}

$apiKey = trim((string)configValue(
    $config,
    ['binance_web3_api_key'],
    ''
));

$secretKey = trim((string)configValue(
    $config,
    ['binance_web3_secret_key'],
    ''
));

$isLocal = PHP_OS_FAMILY === 'Windows';

if ($isLocal) {
    $dbHost = 'localhost';
    $dbName = 'bhub';
    $dbUser = 'root';
    $dbPass = '';
} else {
    $dbHost = (string)configValue(
        $config,
        ['db_host', 'DB_HOST'],
        'localhost'
    );

    $dbName = (string)configValue(
        $config,
        ['db_name', 'DB_NAME'],
        ''
    );

    $dbUser = (string)configValue(
        $config,
        ['db_user', 'DB_USER'],
        ''
    );

    $dbPass = (string)configValue(
        $config,
        ['db_pass', 'db_password', 'DB_PASS', 'DB_PASSWORD'],
        ''
    );
}

echo "DB HOST: {$dbHost}\n";
echo "DB NAME: {$dbName}\n";
echo "DB USER: {$dbUser}\n";
echo "DB PASS: " . ($dbPass === '' ? 'EMPTY' : 'SET') . "\n\n";
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Local ve production aynı şekilde UTC kullansın.
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    exit("DB ERROR: {$e->getMessage()}\n");
}

function ensureUnderlyingMarketTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rwa_underlying_market_latest (
            provider VARCHAR(32) NOT NULL,
            underlying_ticker VARCHAR(32) NOT NULL,
            underlying_name VARCHAR(255) NULL,
            token_symbol VARCHAR(64) NULL,
            market_cap DECIMAL(40,8) NULL,
            volume_24h DECIMAL(40,8) NULL,
            pe_ratio_ttm DECIMAL(30,10) NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'binance_rwa_token_list',
            api_updated_at DATETIME NOT NULL,
            PRIMARY KEY (provider, underlying_ticker),
            KEY idx_underlying_market_cap (market_cap),
            KEY idx_underlying_volume_24h (volume_24h),
            KEY idx_underlying_api_updated_at (api_updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensureUnderlyingRankTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rwa_underlying_rank_latest (
            underlying_ticker VARCHAR(32) NOT NULL,
            underlying_name VARCHAR(255) NULL,
            selected_provider VARCHAR(32) NULL,
            token_symbol VARCHAR(64) NULL,
            market_cap DECIMAL(40,8) NULL,
            market_cap_rank INT UNSIGNED NULL,
            market_cap_rank_total INT UNSIGNED NOT NULL DEFAULT 0,
            volume_24h DECIMAL(40,8) NULL,
            volume_24h_rank INT UNSIGNED NULL,
            volume_24h_rank_total INT UNSIGNED NOT NULL DEFAULT 0,
            pe_ratio_ttm DECIMAL(30,10) NULL,
            asset_total INT UNSIGNED NOT NULL DEFAULT 0,
            source_updated_at DATETIME NULL,
            generated_at DATETIME NOT NULL,
            PRIMARY KEY (underlying_ticker),
            KEY idx_market_cap_rank (market_cap_rank),
            KEY idx_volume_24h_rank (volume_24h_rank),
            KEY idx_selected_provider (selected_provider),
            KEY idx_generated_at (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function refreshUnderlyingRanks(PDO $pdo): array
{
    $priority = ['bstock' => 1, 'ondo' => 2, 'xstocks' => 3];

    $tokenStmt = $pdo->query(
        "SELECT underlying_ticker, underlying_name, provider, symbol
         FROM rwa_tokens
         WHERE is_active = 1
           AND chain_id = '56'
           AND underlying_ticker IS NOT NULL
           AND underlying_ticker <> ''
           AND provider IN ('bstock','ondo','xstocks')"
    );

    $assets = [];

    foreach ($tokenStmt->fetchAll() as $row) {
        $ticker = strtoupper(trim((string)$row['underlying_ticker']));
        $provider = strtolower(trim((string)$row['provider']));

        if ($ticker === '' || !isset($priority[$provider])) {
            continue;
        }

        if (!isset($assets[$ticker])) {
            $assets[$ticker] = [
                'underlying_ticker' => $ticker,
                'underlying_name' => trim((string)($row['underlying_name'] ?? '')) ?: null,
                'providers' => [],
            ];
        }

        $assets[$ticker]['providers'][$provider] = [
            'symbol' => trim((string)($row['symbol'] ?? '')) ?: null,
            'underlying_name' => trim((string)($row['underlying_name'] ?? '')) ?: null,
        ];

        if ($assets[$ticker]['underlying_name'] === null && $assets[$ticker]['providers'][$provider]['underlying_name'] !== null) {
            $assets[$ticker]['underlying_name'] = $assets[$ticker]['providers'][$provider]['underlying_name'];
        }
    }

    $marketStmt = $pdo->query(
        "SELECT provider, underlying_ticker, underlying_name, token_symbol,
                market_cap, volume_24h, pe_ratio_ttm, api_updated_at
         FROM rwa_underlying_market_latest"
    );

    $market = [];

    foreach ($marketStmt->fetchAll() as $row) {
        $ticker = strtoupper(trim((string)$row['underlying_ticker']));
        $provider = strtolower(trim((string)$row['provider']));

        if ($ticker === '' || !isset($priority[$provider])) {
            continue;
        }

        $market[$ticker][$provider] = $row;
    }

    $rows = [];

    foreach ($assets as $ticker => $asset) {
        $providers = array_keys($asset['providers']);
        usort($providers, fn(string $a, string $b): int => ($priority[$a] <=> $priority[$b]));

        $selectedProvider = null;
        $selected = null;

        // Select one provider for the whole underlying record. Do not mix
        // market-cap and volume values across providers.
        foreach ($providers as $provider) {
            if (isset($market[$ticker][$provider])) {
                $selectedProvider = $provider;
                $selected = $market[$ticker][$provider];
                break;
            }
        }

        if ($selectedProvider === null && $providers) {
            $selectedProvider = $providers[0];
        }

        $tokenSymbol = $selectedProvider !== null
            ? ($asset['providers'][$selectedProvider]['symbol'] ?? null)
            : null;

        $name = trim((string)($selected['underlying_name'] ?? '')) ?: $asset['underlying_name'];
        $marketCap = isset($selected['market_cap']) && is_numeric((string)$selected['market_cap'])
            ? (float)$selected['market_cap']
            : null;
        $volume = isset($selected['volume_24h']) && is_numeric((string)$selected['volume_24h'])
            ? (float)$selected['volume_24h']
            : null;
        $pe = isset($selected['pe_ratio_ttm']) && is_numeric((string)$selected['pe_ratio_ttm'])
            ? (float)$selected['pe_ratio_ttm']
            : null;

        $rows[$ticker] = [
            'underlying_ticker' => $ticker,
            'underlying_name' => $name,
            'selected_provider' => $selectedProvider,
            'token_symbol' => $tokenSymbol,
            'market_cap' => $marketCap,
            'market_cap_rank' => null,
            'volume_24h' => $volume,
            'volume_24h_rank' => null,
            'pe_ratio_ttm' => $pe,
            'source_updated_at' => $selected['api_updated_at'] ?? null,
        ];
    }

    $marketKeys = array_keys(array_filter(
        $rows,
        fn(array $row): bool => $row['market_cap'] !== null && $row['market_cap'] > 0
    ));
    usort($marketKeys, function (string $a, string $b) use ($rows): int {
        $cmp = $rows[$b]['market_cap'] <=> $rows[$a]['market_cap'];
        return $cmp !== 0 ? $cmp : strcmp($a, $b);
    });

    foreach ($marketKeys as $i => $ticker) {
        $rows[$ticker]['market_cap_rank'] = $i + 1;
    }

    $volumeKeys = array_keys(array_filter(
        $rows,
        fn(array $row): bool => $row['volume_24h'] !== null && $row['volume_24h'] >= 0
    ));
    usort($volumeKeys, function (string $a, string $b) use ($rows): int {
        $cmp = $rows[$b]['volume_24h'] <=> $rows[$a]['volume_24h'];
        return $cmp !== 0 ? $cmp : strcmp($a, $b);
    });

    foreach ($volumeKeys as $i => $ticker) {
        $rows[$ticker]['volume_24h_rank'] = $i + 1;
    }

    $assetTotal = count($rows);
    $marketTotal = count($marketKeys);
    $volumeTotal = count($volumeKeys);

    $insert = $pdo->prepare(
        "INSERT INTO rwa_underlying_rank_latest (
            underlying_ticker, underlying_name, selected_provider, token_symbol,
            market_cap, market_cap_rank, market_cap_rank_total,
            volume_24h, volume_24h_rank, volume_24h_rank_total,
            pe_ratio_ttm, asset_total, source_updated_at, generated_at
        ) VALUES (
            :underlying_ticker, :underlying_name, :selected_provider, :token_symbol,
            :market_cap, :market_cap_rank, :market_cap_rank_total,
            :volume_24h, :volume_24h_rank, :volume_24h_rank_total,
            :pe_ratio_ttm, :asset_total, :source_updated_at, UTC_TIMESTAMP()
        )"
    );

    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM rwa_underlying_rank_latest');

        foreach ($rows as $row) {
            $insert->execute([
                ':underlying_ticker' => $row['underlying_ticker'],
                ':underlying_name' => $row['underlying_name'],
                ':selected_provider' => $row['selected_provider'],
                ':token_symbol' => $row['token_symbol'],
                ':market_cap' => $row['market_cap'],
                ':market_cap_rank' => $row['market_cap_rank'],
                ':market_cap_rank_total' => $marketTotal,
                ':volume_24h' => $row['volume_24h'],
                ':volume_24h_rank' => $row['volume_24h_rank'],
                ':volume_24h_rank_total' => $volumeTotal,
                ':pe_ratio_ttm' => $row['pe_ratio_ttm'],
                ':asset_total' => $assetTotal,
                ':source_updated_at' => $row['source_updated_at'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'assets' => $assetTotal,
        'market_cap_ranked' => $marketTotal,
        'volume_ranked' => $volumeTotal,
    ];
}

function normalizeUnderlyingMarketRow(array $item, string $provider): ?array
{
    $ticker = strtoupper(trim((string)($item['underlyingTicker'] ?? '')));

    if ($ticker === '') {
        return null;
    }

    $marketCap = $item['marketCap'] ?? null;
    $volume24h = $item['volume24H'] ?? null;
    $peRatio = $item['peRatioTTM'] ?? null;

    $marketCap = is_scalar($marketCap) && is_numeric((string)$marketCap)
        ? (string)$marketCap
        : null;
    $volume24h = is_scalar($volume24h) && is_numeric((string)$volume24h)
        ? (string)$volume24h
        : null;
    $peRatio = is_scalar($peRatio) && is_numeric((string)$peRatio)
        ? (string)$peRatio
        : null;

    if ($marketCap === null && $volume24h === null && $peRatio === null) {
        return null;
    }

    return [
        'provider' => $provider,
        'underlying_ticker' => $ticker,
        'underlying_name' => trim((string)($item['underlyingName'] ?? '')) ?: null,
        'token_symbol' => trim((string)($item['tokenSymbol'] ?? '')) ?: null,
        'market_cap' => $marketCap,
        'volume_24h' => $volume24h,
        'pe_ratio_ttm' => $peRatio,
    ];
}

function syncUnderlyingMarketProvider(PDO $pdo, string $provider, array $items): int
{
    $rows = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $row = normalizeUnderlyingMarketRow($item, $provider);

        if ($row !== null) {
            $rows[$row['underlying_ticker']] = $row;
        }
    }

    if (!$rows) {
        throw new RuntimeException(
            "{$provider}: underlying market verisi gelmedi."
        );
    }

    $upsert = $pdo->prepare(
        "INSERT INTO rwa_underlying_market_latest (
            provider, underlying_ticker, underlying_name, token_symbol,
            market_cap, volume_24h, pe_ratio_ttm, source, api_updated_at
        ) VALUES (
            :provider, :underlying_ticker, :underlying_name, :token_symbol,
            :market_cap, :volume_24h, :pe_ratio_ttm,
            'binance_rwa_token_list', UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE
            underlying_name = VALUES(underlying_name),
            token_symbol = VALUES(token_symbol),
            market_cap = VALUES(market_cap),
            volume_24h = VALUES(volume_24h),
            pe_ratio_ttm = VALUES(pe_ratio_ttm),
            source = VALUES(source),
            api_updated_at = VALUES(api_updated_at)"
    );

    $pdo->beginTransaction();

    try {
        $delete = $pdo->prepare(
            'DELETE FROM rwa_underlying_market_latest WHERE provider = ?'
        );
        $delete->execute([$provider]);

        foreach ($rows as $row) {
            $upsert->execute([
                ':provider' => $row['provider'],
                ':underlying_ticker' => $row['underlying_ticker'],
                ':underlying_name' => $row['underlying_name'],
                ':token_symbol' => $row['token_symbol'],
                ':market_cap' => $row['market_cap'],
                ':volume_24h' => $row['volume_24h'],
                ':pe_ratio_ttm' => $row['pe_ratio_ttm'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return count($rows);
}

ensureUnderlyingMarketTable($pdo);
ensureUnderlyingRankTable($pdo);

function timestampUtc(): string
{
    $now = microtime(true);
    $sec = (int)floor($now);
    $ms = (int)floor(($now - $sec) * 1000);

    return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
}

function msToMysqlDate(mixed $value): ?string
{
    if (!is_numeric($value)) {
        return null;
    }

    $ms = (int)$value;

    if ($ms <= 0) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', intdiv($ms, 1000));
}


function acquireWeb3Coordinator(float $timeoutSeconds = 90.0) {
    $lock = fopen('/run/lock/bnb-rwa-all-sync.lock', 'r');
    if (!is_resource($lock)) {
        throw new RuntimeException('shared_api_lock_unavailable');
    }
    $deadline = microtime(true) + $timeoutSeconds;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            fclose($lock);
            throw new RuntimeException('shared_api_lock_timeout');
        }
        usleep(100000);
    }
    return $lock;
}

function releaseWeb3Coordinator($lock): void {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function web3Get(
    string $path,
    array $query,
    string $apiKey,
    string $secretKey,
    int $maxAttempts = 3
): array {
    $queryString = http_build_query(
        $query,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $requestPath = '/build' . $path;

    if ($queryString !== '') {
        $requestPath .= '?' . $queryString;
    }

    $rateLimitErrors = 0;
    $attempt = 0;

    do {
        $attempt++;
        $apiLock = acquireWeb3Coordinator();

        try {
        $timestamp = timestampUtc();

        $signature = base64_encode(
            hash_hmac(
                'sha256',
                $timestamp . 'GET' . $requestPath,
                $secretKey,
                true
            )
        );

        $headers = [];

        $ch = curl_init(
            'https://web3.binance.com' . $requestPath
        );

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-OC-APIKEY: ' . $apiKey,
                'X-OC-TIMESTAMP: ' . $timestamp,
                'X-OC-SIGN: ' . $signature,
                'X-OC-RECV-WINDOW: 60000',
            ],
            CURLOPT_HEADERFUNCTION =>
                function ($ch, string $line) use (&$headers): int {
                    $len = strlen($line);
                    $parts = explode(':', $line, 2);

                    if (count($parts) === 2) {
                        $headers[
                            strtolower(trim($parts[0]))
                        ] = trim($parts[1]);
                    }

                    return $len;
                },
        ]);

        $start = microtime(true);
        $body = curl_exec($ch);
        $timeMs = round(
            (microtime(true) - $start) * 1000,
            2
        );

        $http = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $error = curl_error($ch);

        curl_close($ch);
        } finally {
            releaseWeb3Coordinator($apiLock);
        }

        if ($body === false || $error !== '') {
            throw new RuntimeException(
                'cURL error: ' . $error
            );
        }

        $json = json_decode($body, true);

        $businessCode = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $isRateLimit =
            $http === 429 ||
            $businessCode === '42900';

        if (!$isRateLimit) {
            return [
                'path' => $requestPath,
                'http' => $http,
                'time_ms' => $timeMs,
                'headers' => $headers,
                'weight' =>
                    $headers['x-oc-used-weight'] ?? null,
                'remaining' =>
                    $headers['x-oc-ratelimit-remaining'] ?? null,
                'attempts' => $attempt,
                'rate_limit_errors' => $rateLimitErrors,
                'response' => $json,
            ];
        }

        $rateLimitErrors++;

        if ($attempt >= $maxAttempts) {
            return [
                'path' => $requestPath,
                'http' => $http,
                'time_ms' => $timeMs,
                'headers' => $headers,
                'weight' =>
                    $headers['x-oc-used-weight'] ?? null,
                'remaining' =>
                    $headers['x-oc-ratelimit-remaining'] ?? null,
                'attempts' => $attempt,
                'rate_limit_errors' => $rateLimitErrors,
                'response' => $json,
            ];
        }

        $retryAfter = isset($headers['retry-after'])
            ? (int)$headers['retry-after']
            : 0;

        $wait = $retryAfter > 0
            ? min($retryAfter, 60)
            : min(2 ** $attempt, 10);

        sleep($wait);

    } while ($attempt < $maxAttempts);

    throw new RuntimeException(
        'Unexpected Web3 request state.'
    );
}

function publicGet(string $url): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Encoding: identity',
            'User-Agent: binance-web3/1.1 (BNB-RWA-Hub)',
        ],
    ]);

    $start = microtime(true);
    $body = curl_exec($ch);

    $timeMs = round(
        (microtime(true) - $start) * 1000,
        2
    );

    $http = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $error = curl_error($ch);

    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException(
            'Public API cURL error: ' . $error
        );
    }

    return [
        'http' => $http,
        'time_ms' => $timeMs,
        'response' => json_decode($body, true),
    ];
}

function cleanRwaMetadata(array $item): array
{
    // Binance RWA Token List exposes volume24H / marketCap for the
    // underlying asset, not the BSC token. Keep those raw values in
    // the saved catalogue metadata under explicit names so consumers
    // cannot confuse them with token-market metrics.
    $underlyingMarket = [];

    foreach ([
        'volume24H' => 'volume24H',
        'marketCap' => 'marketCap',
        'peRatioTTM' => 'peRatioTTM',
    ] as $source => $target) {
        $value = $item[$source] ?? null;

        if (is_scalar($value) && is_numeric((string)$value)) {
            $underlyingMarket[$target] = (string)$value;
        }
    }

    if ($underlyingMarket !== []) {
        $item['_underlyingMarket'] = $underlyingMarket;
        $item['_underlyingMarketSource'] = 'binance_rwa_token_list';
    }

    // Token/reference prices continue to live in the dedicated latest
    // snapshot table. Remove the ambiguous original dynamic field names
    // after copying the verified underlying fields above.
    foreach ([
        'tokenPrice',
        'referencePrice',
        'volume24H',
        'marketCap',
        'peRatioTTM',
    ] as $key) {
        unset($item[$key]);
    }

    return $item;
}

function normalizeRwaToken(
    array $item,
    string $provider
): ?array {
    $chainId = trim(
        (string)($item['binanceChainId'] ?? '')
    );

    $contract = strtolower(trim(
        (string)($item['tokenContractAddress'] ?? '')
    ));

    $symbol = trim(
        (string)($item['tokenSymbol'] ?? '')
    );

    $ticker = trim(
        (string)($item['underlyingTicker'] ?? '')
    );

    if (
        $chainId !== '56' ||
        $contract === '' ||
        $symbol === '' ||
        $ticker === ''
    ) {
        return null;
    }

    return [
        'provider' => $provider,
        'platform_id' =>
            $item['platformId'] ?? null,
        'chain_id' => $chainId,
        'contract_address' => $contract,
        'symbol' => $symbol,
        'ticker' => $ticker,
        'token_name' =>
            $item['tokenName'] ?? null,
        'underlying_ticker' => $ticker,
        'underlying_name' =>
            $item['underlyingName'] ?? null,
        'decimals' => isset($item['decimals'])
            ? (int)$item['decimals']
            : null,
        'multiplier' => null,
        'token_to_share_ratio' =>
            $item['tokenToShareRatio'] ?? null,
        'asset_type' => isset($item['assetType'])
            ? (int)$item['assetType']
            : null,
        'source' => 'binance_web3_rwa',
        'source_updated_at' => null,
        'metadata_json' => json_encode(
            cleanRwaMetadata($item),
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ),
    ];
}

function normalizeXstock(array $item): ?array
{
    if ((string)($item['chainId'] ?? '') !== '56') {
        return null;
    }

    if ((int)($item['type'] ?? 0) !== 2) {
        return null;
    }

    $contract = strtolower(trim(
        (string)($item['contractAddress'] ?? '')
    ));

    $symbol = trim(
        (string)($item['symbol'] ?? '')
    );

    $ticker = trim(
        (string)($item['ticker'] ?? '')
    );

    if (
        $contract === '' ||
        $symbol === '' ||
        $ticker === ''
    ) {
        return null;
    }

    return [
        'provider' => 'xstocks',
        'platform_id' => null,
        'chain_id' => '56',
        'contract_address' => $contract,
        'symbol' => $symbol,
        'ticker' => $ticker,
        'token_name' => null,
        'underlying_ticker' => $ticker,
        'underlying_name' => null,
        'decimals' => isset($item['d'])
            ? (int)$item['d']
            : null,
        'multiplier' =>
            $item['multiplier'] ?? null,
        'token_to_share_ratio' => null,
        'asset_type' => isset($item['assetType'])
            ? (int)$item['assetType']
            : null,
        'source' =>
            'binance_public_rwa_catalog',
        'source_updated_at' =>
            msToMysqlDate(
                $item['lastUpdateTime'] ?? null
            ),
        'metadata_json' => json_encode(
            $item,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ),
    ];
}

function syncProvider(
    PDO $pdo,
    string $provider,
    array $rows
): array {
    if (!$rows) {
        throw new RuntimeException(
            "{$provider}: sync listesi boş."
        );
    }

    $existingStmt = $pdo->prepare(
        'SELECT contract_address, is_active
         FROM rwa_tokens
         WHERE provider = ?
           AND chain_id = ?'
    );

    $existingStmt->execute([
        $provider,
        '56',
    ]);

    $existing = [];
    $activeExisting = [];

    foreach ($existingStmt->fetchAll() as $row) {
        $address = strtolower(
            (string)$row['contract_address']
        );

        $existing[$address] = true;

        if ((int)$row['is_active'] === 1) {
            $activeExisting[$address] = true;
        }
    }

    $incoming = [];

    foreach ($rows as $row) {
        $incoming[
            strtolower($row['contract_address'])
        ] = true;
    }

    $inserted = 0;
    $updated = 0;

    foreach (array_keys($incoming) as $address) {
        if (isset($existing[$address])) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    $deactivated = count(
        array_diff_key(
            $activeExisting,
            $incoming
        )
    );

    $sql = '
        INSERT INTO rwa_tokens (
            provider,
            platform_id,
            chain_id,
            contract_address,
            symbol,
            ticker,
            token_name,
            underlying_ticker,
            underlying_name,
            decimals,
            multiplier,
            token_to_share_ratio,
            asset_type,
            source,
            source_updated_at,
            is_active,
            metadata_json,
            last_seen_at
        ) VALUES (
            :provider,
            :platform_id,
            :chain_id,
            :contract_address,
            :symbol,
            :ticker,
            :token_name,
            :underlying_ticker,
            :underlying_name,
            :decimals,
            :multiplier,
            :token_to_share_ratio,
            :asset_type,
            :source,
            :source_updated_at,
            1,
            :metadata_json,
            CURRENT_TIMESTAMP
        )
        ON DUPLICATE KEY UPDATE
            provider = VALUES(provider),
            platform_id = VALUES(platform_id),
            symbol = VALUES(symbol),
            ticker = VALUES(ticker),
            token_name = VALUES(token_name),
            underlying_ticker = VALUES(underlying_ticker),
            underlying_name = VALUES(underlying_name),
            decimals = VALUES(decimals),
            multiplier = VALUES(multiplier),
            token_to_share_ratio =
                VALUES(token_to_share_ratio),
            asset_type = VALUES(asset_type),
            source = VALUES(source),
            source_updated_at =
                VALUES(source_updated_at),
            is_active = 1,
            metadata_json = VALUES(metadata_json),
            last_seen_at = CURRENT_TIMESTAMP
    ';

    $upsert = $pdo->prepare($sql);

    $pdo->beginTransaction();

    try {
        // Yalnız API fetch tamamen başarılı olduktan sonra
        // provider kayıtlarını yeniden değerlendiriyoruz.
        $deactivateStmt = $pdo->prepare(
            'UPDATE rwa_tokens
             SET is_active = 0
             WHERE provider = ?
               AND chain_id = ?'
        );

        $deactivateStmt->execute([
            $provider,
            '56',
        ]);

        foreach ($rows as $row) {
            $upsert->execute([
                ':provider' =>
                    $row['provider'],
                ':platform_id' =>
                    $row['platform_id'],
                ':chain_id' =>
                    $row['chain_id'],
                ':contract_address' =>
                    $row['contract_address'],
                ':symbol' =>
                    $row['symbol'],
                ':ticker' =>
                    $row['ticker'],
                ':token_name' =>
                    $row['token_name'],
                ':underlying_ticker' =>
                    $row['underlying_ticker'],
                ':underlying_name' =>
                    $row['underlying_name'],
                ':decimals' =>
                    $row['decimals'],
                ':multiplier' =>
                    $row['multiplier'],
                ':token_to_share_ratio' =>
                    $row['token_to_share_ratio'],
                ':asset_type' =>
                    $row['asset_type'],
                ':source' =>
                    $row['source'],
                ':source_updated_at' =>
                    $row['source_updated_at'],
                ':metadata_json' =>
                    $row['metadata_json'],
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    return [
        'fetched' => count($rows),
        'inserted' => $inserted,
        'updated' => $updated,
        'deactivated' => $deactivated,
    ];
}

function printRateHeaders(
    array $result,
    bool $fullHeaders
): void {
    echo "HTTP: {$result['http']}\n";
    echo "TIME: {$result['time_ms']} ms\n";
    echo 'WEIGHT: ' .
        ($result['weight'] ?? '-') . "\n";
    echo 'REMAINING: ' .
        ($result['remaining'] ?? '-') . "\n";
    echo "ATTEMPTS: {$result['attempts']}\n";

    if ($fullHeaders) {
        echo "\nALL WEB3 RESPONSE HEADERS\n";
        echo "-------------------------\n";

        foreach ($result['headers'] as $name => $value) {
            echo "{$name}: {$value}\n";
        }
    } else {
        echo "\nRATE LIMIT HEADERS\n";
        echo "------------------\n";

        foreach ($result['headers'] as $name => $value) {
            if (
                str_contains($name, 'rate') ||
                str_contains($name, 'limit') ||
                str_contains($name, 'weight') ||
                $name === 'retry-after'
            ) {
                echo "{$name}: {$value}\n";
            }
        }
    }
}

$started = microtime(true);

$summary = [
    'xstocks' => null,
    'ondo' => null,
    'bstock' => null,
];

$errors = [];

$web3RequestAttempts = 0;
$web3RateLimitErrors = 0;
$observedWeightSum = 0;
$lowestRemaining = null;

echo "BNB RWA HUB TOKEN SYNC\n";
echo "======================\n";
echo 'START UTC: ' .
    gmdate('Y-m-d H:i:s') . "\n";
echo "DB: {$dbName}\n";
echo "CHAIN: BSC (56)\n";
echo 'FULL HEADERS: ' .
    ($fullHeaders ? 'YES' : 'NO') . "\n\n";

/*
 * 1. xStocks
 */
echo "========================================\n";
echo "xSTOCKS\n";
echo "========================================\n";

try {
    $url =
        'https://www.binance.com/' .
        'bapi/defi/v1/public/wallet-direct/' .
        'buw/wallet/market/token/rwa/' .
        'stock/detail/list/ai?type=2';

    $result = publicGet($url);
    $json = $result['response'];

    if (
        $result['http'] !== 200 ||
        !is_array($json) ||
        (string)($json['code'] ?? '') !== '000000' ||
        !isset($json['data']) ||
        !is_array($json['data'])
    ) {
        throw new RuntimeException(
            'xStocks katalog cevabı geçersiz.'
        );
    }

    $rows = [];

    foreach ($json['data'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $row = normalizeXstock($item);

        if ($row !== null) {
            $rows[] = $row;
        }
    }

    echo "HTTP: {$result['http']}\n";
    echo "TIME: {$result['time_ms']} ms\n";
    echo 'CATALOG RECORDS: ' .
        count($json['data']) . "\n";
    echo 'BSC RECORDS: ' .
        count($rows) . "\n";
    echo "WEIGHT: public endpoint / n/a\n";

    $summary['xstocks'] =
        syncProvider(
            $pdo,
            'xstocks',
            $rows
        );

    echo 'INSERTED: ' .
        $summary['xstocks']['inserted'] . "\n";
    echo 'UPDATED: ' .
        $summary['xstocks']['updated'] . "\n";
    echo 'DEACTIVATED: ' .
        $summary['xstocks']['deactivated'] . "\n\n";

} catch (Throwable $e) {
    $errors[] =
        'xstocks: ' . $e->getMessage();

    echo "ERROR: {$e->getMessage()}\n\n";
}

/*
 * Signed Web3 API credentials are required
 * for Ondo and bStocks.
 */
if ($apiKey === '' || $secretKey === '') {
    $errors[] =
        'Web3 API key/secret missing.';

    echo "WEB3 ERROR: API key veya secret boş.\n";
} else {
    /*
     * 2. Ondo
     */
    echo "========================================\n";
    echo "ONDO\n";
    echo "========================================\n";

    try {
        $result = web3Get(
            '/api/v1/dex/market/rwa/tokens',
            [
                'binanceChainId' => '56',
                'platformId' => 'ondo',
            ],
            $apiKey,
            $secretKey
        );

        $web3RequestAttempts +=
            $result['attempts'];

        $web3RateLimitErrors +=
            $result['rate_limit_errors'];

        if (is_numeric($result['weight'])) {
            $observedWeightSum +=
                (int)$result['weight'];
        }

        if (is_numeric($result['remaining'])) {
            $remaining =
                (int)$result['remaining'];

            $lowestRemaining =
                $lowestRemaining === null
                ? $remaining
                : min(
                    $lowestRemaining,
                    $remaining
                );
        }

        printRateHeaders(
            $result,
            $fullHeaders
        );

        $json = $result['response'];

        if (
            !is_array($json) ||
            (string)($json['code'] ?? '') !== '0' ||
            !($json['success'] ?? false) ||
            !isset($json['data']) ||
            !is_array($json['data'])
        ) {
            throw new RuntimeException(
                'Ondo API error: ' .
                ($json['msg'] ?? 'unknown')
            );
        }

        $rows = [];

        foreach ($json['data'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = normalizeRwaToken(
                $item,
                'ondo'
            );

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $summary['ondo'] =
            syncProvider(
                $pdo,
                'ondo',
                $rows
            );

        $underlyingSaved = syncUnderlyingMarketProvider(
            $pdo,
            'ondo',
            $json['data']
        );

        echo "\nUNDERLYING MARKET SAVED: {$underlyingSaved}\n";
        echo "FETCHED: " .
            $summary['ondo']['fetched'] . "\n";
        echo "INSERTED: " .
            $summary['ondo']['inserted'] . "\n";
        echo "UPDATED: " .
            $summary['ondo']['updated'] . "\n";
        echo "DEACTIVATED: " .
            $summary['ondo']['deactivated'] .
            "\n\n";

    } catch (Throwable $e) {
        $errors[] =
            'ondo: ' . $e->getMessage();

        echo "\nERROR: {$e->getMessage()}\n\n";
    }

    // Signed istekleri gereksiz şekilde üst üste bindirmeyelim.
    usleep(500000);

    /*
     * 3. bStocks
     */
    echo "========================================\n";
    echo "bSTOCKS\n";
    echo "========================================\n";

    try {
        $result = web3Get(
            '/api/v1/dex/market/rwa/tokens',
            [
                'binanceChainId' => '56',
                'platformId' => 'bstock',
            ],
            $apiKey,
            $secretKey
        );

        $web3RequestAttempts +=
            $result['attempts'];

        $web3RateLimitErrors +=
            $result['rate_limit_errors'];

        if (is_numeric($result['weight'])) {
            $observedWeightSum +=
                (int)$result['weight'];
        }

        if (is_numeric($result['remaining'])) {
            $remaining =
                (int)$result['remaining'];

            $lowestRemaining =
                $lowestRemaining === null
                ? $remaining
                : min(
                    $lowestRemaining,
                    $remaining
                );
        }

        printRateHeaders(
            $result,
            $fullHeaders
        );

        $json = $result['response'];

        if (
            !is_array($json) ||
            (string)($json['code'] ?? '') !== '0' ||
            !($json['success'] ?? false) ||
            !isset($json['data']) ||
            !is_array($json['data'])
        ) {
            throw new RuntimeException(
                'bStocks API error: ' .
                ($json['msg'] ?? 'unknown')
            );
        }

        $rows = [];

        foreach ($json['data'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = normalizeRwaToken(
                $item,
                'bstock'
            );

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $summary['bstock'] =
            syncProvider(
                $pdo,
                'bstock',
                $rows
            );

        $underlyingSaved = syncUnderlyingMarketProvider(
            $pdo,
            'bstock',
            $json['data']
        );

        echo "\nUNDERLYING MARKET SAVED: {$underlyingSaved}\n";
        echo "FETCHED: " .
            $summary['bstock']['fetched'] .
            "\n";
        echo "INSERTED: " .
            $summary['bstock']['inserted'] .
            "\n";
        echo "UPDATED: " .
            $summary['bstock']['updated'] .
            "\n";
        echo "DEACTIVATED: " .
            $summary['bstock']['deactivated'] .
            "\n\n";

    } catch (Throwable $e) {
        $errors[] =
            'bstock: ' . $e->getMessage();

        echo "\nERROR: {$e->getMessage()}\n\n";
    }
}

try {
    $rankSummary = refreshUnderlyingRanks($pdo);
    echo "UNDERLYING RANK SNAPSHOT: {$rankSummary['assets']} assets, {$rankSummary['market_cap_ranked']} market-cap ranked, {$rankSummary['volume_ranked']} volume ranked\n\n";
} catch (Throwable $e) {
    $errors[] = 'underlying-ranks: ' . $e->getMessage();
    echo "UNDERLYING RANK ERROR: {$e->getMessage()}\n\n";
}

$totalFetched = 0;
$totalInserted = 0;
$totalUpdated = 0;
$totalDeactivated = 0;

foreach ($summary as $stats) {
    if (!is_array($stats)) {
        continue;
    }

    $totalFetched +=
        $stats['fetched'];

    $totalInserted +=
        $stats['inserted'];

    $totalUpdated +=
        $stats['updated'];

    $totalDeactivated +=
        $stats['deactivated'];
}

$elapsed = round(
    microtime(true) - $started,
    2
);

echo "========================================\n";
echo "FINAL SUMMARY\n";
echo "========================================\n";
echo "FETCHED: {$totalFetched}\n";
echo "INSERTED: {$totalInserted}\n";
echo "UPDATED: {$totalUpdated}\n";
echo "DEACTIVATED: {$totalDeactivated}\n";
echo "ERRORS: " . count($errors) . "\n";
echo "TIME: {$elapsed} sec\n\n";

echo "WEB3 RATE SUMMARY\n";
echo "-----------------\n";
echo "REQUEST ATTEMPTS: {$web3RequestAttempts}\n";
echo "OBSERVED WEIGHT SUM: {$observedWeightSum}\n";
echo "LOWEST REMAINING: " .
    ($lowestRemaining ?? '-') . "\n";
echo "RATE LIMIT ERRORS: {$web3RateLimitErrors}\n";

if ($errors) {
    echo "\nERROR DETAILS\n";
    echo "-------------\n";

    foreach ($errors as $error) {
        echo "* {$error}\n";
    }
}

echo "\nEND UTC: " .
    gmdate('Y-m-d H:i:s') . "\n";

if (PHP_SAPI === 'cli' && $errors) {
    exit(1);
}