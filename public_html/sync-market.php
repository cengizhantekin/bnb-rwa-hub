<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');

    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit("This script is CLI-only outside localhost.\n");
    }
}

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

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

$apiKey = trim((string)($config['binance_web3_api_key'] ?? ''));
$secretKey = trim((string)($config['binance_web3_secret_key'] ?? ''));

if ($apiKey === '' || $secretKey === '') {
    exit("ERROR: Binance Web3 API key/secret bulunamadı.\n");
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$isLocal = PHP_OS_FAMILY === 'Windows';

if ($isLocal) {
    $dbHost = 'localhost';
    $dbName = 'bhub';
    $dbUser = 'root';
    $dbPass = '';
} else {
    $dbHost = (string)($config['db_host'] ?? $config['DB_HOST'] ?? 'localhost');
    $dbName = (string)($config['db_name'] ?? $config['DB_NAME'] ?? '');
    $dbUser = (string)($config['db_user'] ?? $config['DB_USER'] ?? '');
    $dbPass = (string)(
        $config['db_pass']
        ?? $config['db_password']
        ?? $config['DB_PASS']
        ?? $config['DB_PASSWORD']
        ?? ''
    );
}

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

    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    exit("DB ERROR: {$e->getMessage()}\n");
}

/*
|--------------------------------------------------------------------------
| RUN MODE
|--------------------------------------------------------------------------
|
| Browser:
|
| sync-market.php
| sync-market.php?ticker=TSLA
| sync-market.php?compare=1
| sync-market.php?all=1
| sync-market.php?compare=1&fullheaders=1
|
| CLI:
|
| php sync-market.php
| php sync-market.php --compare
| php sync-market.php --all
| php sync-market.php --ticker=TSLA
|
*/

$args = $argv ?? [];

$all = PHP_SAPI === 'cli'
    ? in_array('--all', $args, true)
    : isset($_GET['all']);

$compare = PHP_SAPI === 'cli'
    ? in_array('--compare', $args, true)
    : isset($_GET['compare']);

$fullHeaders = PHP_SAPI === 'cli'
    ? in_array('--full-headers', $args, true)
    : isset($_GET['fullheaders']);

$ticker = null;

if (PHP_SAPI === 'cli') {
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--ticker=')) {
            $ticker = trim(substr($arg, 9));
            break;
        }
    }
} else {
    $ticker = trim((string)($_GET['ticker'] ?? ''));
}

if ($ticker === '') {
    $ticker = null;
}

if ($ticker !== null) {
    $ticker = strtoupper($ticker);
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function timestampUtc(): string
{
    $now = microtime(true);
    $sec = (int)floor($now);
    $ms = (int)floor(($now - $sec) * 1000);

    return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
}

function msToMysqlDateTime3(mixed $value): ?string
{
    if (!is_numeric($value)) {
        return null;
    }

    $ms = (int)$value;

    if ($ms <= 0) {
        return null;
    }

    $seconds = intdiv($ms, 1000);
    $millis = $ms % 1000;

    return gmdate('Y-m-d H:i:s', $seconds)
        . sprintf('.%03d', $millis);
}

function valueOrNull(array $row, string $key): mixed
{
    if (!array_key_exists($key, $row)) {
        return null;
    }

    if ($row[$key] === '') {
        return null;
    }

    return $row[$key];
}

/*
|--------------------------------------------------------------------------
| BINANCE WEB3 POST
|--------------------------------------------------------------------------
*/


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

function web3Post(
    string $path,
    array $payload,
    string $apiKey,
    string $secretKey,
    int $maxAttempts = 3
): array {
    $requestPath = '/build' . $path;

    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR
    );

    $attempt = 0;
    $rateLimitErrors = 0;

    while ($attempt < $maxAttempts) {
        $attempt++;
        $apiLock = acquireWeb3Coordinator();

        try {
        $timestamp = timestampUtc();

        $signature = base64_encode(
            hash_hmac(
                'sha256',
                $timestamp . 'POST' . $requestPath . $body,
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
            CURLOPT_TIMEOUT => 40,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
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
        $response = curl_exec($ch);

        $elapsed = round(
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

        if ($response === false || $error !== '') {
            throw new RuntimeException(
                'cURL error: ' . $error
            );
        }

        $json = json_decode($response, true);

        $businessCode = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $rateLimited =
            $http === 429
            || $businessCode === '42900';

        if (!$rateLimited) {
            return [
                'http' => $http,
                'time_ms' => $elapsed,
                'headers' => $headers,
                'limit' =>
                    $headers['x-oc-ratelimit-limit'] ?? null,
                'weight' =>
                    $headers['x-oc-used-weight'] ?? null,
                'remaining' =>
                    $headers['x-oc-ratelimit-remaining'] ?? null,
                'attempts' => $attempt,
                'rate_limit_errors' => $rateLimitErrors,
                'response' => $json,
                'request_bytes' => strlen($body),
            ];
        }

        $rateLimitErrors++;

        if ($attempt >= $maxAttempts) {
            return [
                'http' => $http,
                'time_ms' => $elapsed,
                'headers' => $headers,
                'limit' =>
                    $headers['x-oc-ratelimit-limit'] ?? null,
                'weight' =>
                    $headers['x-oc-used-weight'] ?? null,
                'remaining' =>
                    $headers['x-oc-ratelimit-remaining'] ?? null,
                'attempts' => $attempt,
                'rate_limit_errors' => $rateLimitErrors,
                'response' => $json,
                'request_bytes' => strlen($body),
            ];
        }

        $retryAfter = isset($headers['retry-after'])
            ? (int)$headers['retry-after']
            : 0;

        $wait = $retryAfter > 0
            ? min($retryAfter, 60)
            : min(2 ** $attempt, 10);

        echo "RATE LIMIT: {$wait} saniye bekleniyor...\n";

        sleep($wait);
    }

    throw new RuntimeException(
        'Unexpected request state.'
    );
}

/*
|--------------------------------------------------------------------------
| TOKEN SELECTION
|--------------------------------------------------------------------------
*/

if ($ticker !== null) {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            provider,
            symbol,
            underlying_ticker,
            chain_id,
            contract_address
         FROM rwa_tokens
         WHERE chain_id = '56'
           AND is_active = 1
           AND underlying_ticker = ?
         ORDER BY provider"
    );

    $stmt->execute([$ticker]);

    $tokens = $stmt->fetchAll();

} elseif ($compare) {
    /*
     * En az iki farklı provider'da bulunan
     * underlying tickerların tüm tokenları.
     */
    $tokens = $pdo->query(
        "SELECT
            t.id,
            t.provider,
            t.symbol,
            t.underlying_ticker,
            t.chain_id,
            t.contract_address
         FROM rwa_tokens t
         INNER JOIN (
             SELECT underlying_ticker
             FROM rwa_tokens
             WHERE chain_id = '56'
               AND is_active = 1
               AND underlying_ticker IS NOT NULL
               AND underlying_ticker <> ''
             GROUP BY underlying_ticker
             HAVING COUNT(DISTINCT provider) >= 2
         ) c
             ON c.underlying_ticker = t.underlying_ticker
         WHERE t.chain_id = '56'
           AND t.is_active = 1
         ORDER BY
             t.underlying_ticker,
             t.provider"
    )->fetchAll();

} elseif ($all) {
    $tokens = $pdo->query(
        "SELECT
            id,
            provider,
            symbol,
            underlying_ticker,
            chain_id,
            contract_address
         FROM rwa_tokens
         WHERE chain_id = '56'
           AND is_active = 1
         ORDER BY id"
    )->fetchAll();

} else {
    /*
     * Varsayılan development sample.
     */
    $tokens = $pdo->query(
        "SELECT
            id,
            provider,
            symbol,
            underlying_ticker,
            chain_id,
            contract_address
         FROM rwa_tokens
         WHERE chain_id = '56'
           AND is_active = 1
         ORDER BY id
         LIMIT 100"
    )->fetchAll();
}

if (!$tokens) {
    exit("ERROR: İşlenecek token bulunamadı.\n");
}

/*
|--------------------------------------------------------------------------
| LOCAL CONTRACT MAP
|--------------------------------------------------------------------------
*/

$tokenMap = [];

foreach ($tokens as $token) {
    $tokenMap[
        strtolower((string)$token['contract_address'])
    ] = $token;
}

/*
|--------------------------------------------------------------------------
| BATCHES
|--------------------------------------------------------------------------
*/

$batches = array_chunk($tokens, 100);

/*
|--------------------------------------------------------------------------
| MARKET UPSERT
|--------------------------------------------------------------------------
*/

$upsertSql = "
    INSERT INTO rwa_market_latest (
        token_id,
        price,
        price_time,
        market_cap,

        price_change_5m,
        price_change_1h,
        price_change_4h,
        price_change_24h,

        volume_5m,
        volume_1h,
        volume_4h,
        volume_24h,

        buy_volume_24h,
        sell_volume_24h,

        txs_24h,
        buy_txs_24h,
        sell_txs_24h,

        high_24h,
        low_24h,

        circulating_supply,
        liquidity,
        holders,

        binance_volume_24h,
        binance_buy_volume_24h,
        binance_sell_volume_24h,
        binance_txs_24h,

        general_api_updated_at,
        raw_general_json
    ) VALUES (
        :token_id,
        :price,
        :price_time,
        :market_cap,

        :price_change_5m,
        :price_change_1h,
        :price_change_4h,
        :price_change_24h,

        :volume_5m,
        :volume_1h,
        :volume_4h,
        :volume_24h,

        :buy_volume_24h,
        :sell_volume_24h,

        :txs_24h,
        :buy_txs_24h,
        :sell_txs_24h,

        :high_24h,
        :low_24h,

        :circulating_supply,
        :liquidity,
        :holders,

        :binance_volume_24h,
        :binance_buy_volume_24h,
        :binance_sell_volume_24h,
        :binance_txs_24h,

        UTC_TIMESTAMP(),
        :raw_general_json
    )

    ON DUPLICATE KEY UPDATE
        price = VALUES(price),
        price_time = VALUES(price_time),
        market_cap = VALUES(market_cap),

        price_change_5m = VALUES(price_change_5m),
        price_change_1h = VALUES(price_change_1h),
        price_change_4h = VALUES(price_change_4h),
        price_change_24h = VALUES(price_change_24h),

        volume_5m = VALUES(volume_5m),
        volume_1h = VALUES(volume_1h),
        volume_4h = VALUES(volume_4h),
        volume_24h = VALUES(volume_24h),

        buy_volume_24h = VALUES(buy_volume_24h),
        sell_volume_24h = VALUES(sell_volume_24h),

        txs_24h = VALUES(txs_24h),
        buy_txs_24h = VALUES(buy_txs_24h),
        sell_txs_24h = VALUES(sell_txs_24h),

        high_24h = VALUES(high_24h),
        low_24h = VALUES(low_24h),

        circulating_supply = VALUES(circulating_supply),
        liquidity = VALUES(liquidity),
        holders = VALUES(holders),

        binance_volume_24h = VALUES(binance_volume_24h),
        binance_buy_volume_24h =
            VALUES(binance_buy_volume_24h),
        binance_sell_volume_24h =
            VALUES(binance_sell_volume_24h),
        binance_txs_24h =
            VALUES(binance_txs_24h),

        general_api_updated_at =
            UTC_TIMESTAMP(),

        raw_general_json =
            VALUES(raw_general_json)
";

$upsert = $pdo->prepare($upsertSql);

/*
|--------------------------------------------------------------------------
| COUNTERS
|--------------------------------------------------------------------------
*/

$totalReturned = 0;
$totalSaved = 0;
$totalMissing = 0;
$totalAttempts = 0;
$totalWeight = 0;
$rateLimitErrors = 0;
$lowestRemaining = null;

$started = microtime(true);

/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

echo "BNB RWA HUB MARKET SYNC\n";
echo "=======================\n";
echo 'START UTC: ' . gmdate('Y-m-d H:i:s') . "\n";
echo "TOKENS: " . count($tokens) . "\n";
echo "BATCHES: " . count($batches) . "\n";
echo "BATCH SIZE: 100 max\n";

if ($ticker !== null) {
    echo "MODE: TICKER\n";
    echo "TICKER: {$ticker}\n";
} elseif ($compare) {
    echo "MODE: CROSS-MARKET COMPARE\n";
} elseif ($all) {
    echo "MODE: ALL\n";
} else {
    echo "MODE: TEST (" . count($tokens) . " tokens)\n";
}

echo "\n";

/*
|--------------------------------------------------------------------------
| SYNC
|--------------------------------------------------------------------------
*/

foreach ($batches as $batchIndex => $batch) {
    $batchNumber = $batchIndex + 1;

    echo "========================================\n";
    echo "BATCH {$batchNumber}/" . count($batches) . "\n";
    echo "========================================\n";

    $payload = [];

    foreach ($batch as $token) {
        $payload[] = [
            'binanceChainId' =>
                (string)$token['chain_id'],

            'tokenContractAddress' =>
                (string)$token['contract_address'],
        ];
    }

    try {
        $result = web3Post(
            '/api/v1/dex/market/price-info',
            $payload,
            $apiKey,
            $secretKey
        );

        $totalAttempts += $result['attempts'];

        $rateLimitErrors +=
            $result['rate_limit_errors'];

        if (is_numeric($result['weight'])) {
            $totalWeight +=
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

        echo "REQUEST TOKENS: " . count($batch) . "\n";
        echo "REQUEST BODY: {$result['request_bytes']} bytes\n";
        echo "HTTP: {$result['http']}\n";
        echo "TIME: {$result['time_ms']} ms\n";
        echo "LIMIT: " . ($result['limit'] ?? '-') . "\n";
        echo "WEIGHT: " . ($result['weight'] ?? '-') . "\n";
        echo "REMAINING: " . ($result['remaining'] ?? '-') . "\n";
        echo "ATTEMPTS: {$result['attempts']}\n";

        if ($fullHeaders) {
            echo "\nHEADERS\n";
            echo "-------\n";

            foreach (
                $result['headers']
                as $name => $value
            ) {
                echo "{$name}: {$value}\n";
            }
        }

        $json = $result['response'];

        if (
            !is_array($json)
            || (string)($json['code'] ?? '') !== '0'
            || !($json['success'] ?? false)
            || !isset($json['data'])
            || !is_array($json['data'])
        ) {
            throw new RuntimeException(
                'API ERROR: '
                . ($json['code'] ?? '-')
                . ' / '
                . ($json['msg'] ?? 'unknown')
            );
        }

        $returned = count($json['data']);

        $totalReturned += $returned;

        $savedThisBatch = 0;

        $pdo->beginTransaction();

        try {
            foreach ($json['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $contract = strtolower(trim(
                    (string)(
                        $row['tokenContractAddress']
                        ?? ''
                    )
                ));

                if (
                    $contract === ''
                    || !isset($tokenMap[$contract])
                ) {
                    continue;
                }

                $tokenId =
                    (int)$tokenMap[$contract]['id'];

                $upsert->execute([
                    ':token_id' =>
                        $tokenId,

                    ':price' =>
                        valueOrNull(
                            $row,
                            'price'
                        ),

                    ':price_time' =>
                        msToMysqlDateTime3(
                            valueOrNull(
                                $row,
                                'time'
                            )
                        ),

                    ':market_cap' =>
                        valueOrNull(
                            $row,
                            'marketCap'
                        ),

                    ':price_change_5m' =>
                        valueOrNull(
                            $row,
                            'priceChange5M'
                        ),

                    ':price_change_1h' =>
                        valueOrNull(
                            $row,
                            'priceChange1H'
                        ),

                    ':price_change_4h' =>
                        valueOrNull(
                            $row,
                            'priceChange4H'
                        ),

                    ':price_change_24h' =>
                        valueOrNull(
                            $row,
                            'priceChange24H'
                        ),

                    ':volume_5m' =>
                        valueOrNull(
                            $row,
                            'volume5M'
                        ),

                    ':volume_1h' =>
                        valueOrNull(
                            $row,
                            'volume1H'
                        ),

                    ':volume_4h' =>
                        valueOrNull(
                            $row,
                            'volume4H'
                        ),

                    ':volume_24h' =>
                        valueOrNull(
                            $row,
                            'volume24H'
                        ),

                    ':buy_volume_24h' =>
                        valueOrNull(
                            $row,
                            'buyVolume24H'
                        ),

                    ':sell_volume_24h' =>
                        valueOrNull(
                            $row,
                            'sellVolume24H'
                        ),

                    ':txs_24h' =>
                        valueOrNull(
                            $row,
                            'txs24H'
                        ),

                    ':buy_txs_24h' =>
                        valueOrNull(
                            $row,
                            'buyTxs24H'
                        ),

                    ':sell_txs_24h' =>
                        valueOrNull(
                            $row,
                            'sellTxs24H'
                        ),

                    ':high_24h' =>
                        valueOrNull(
                            $row,
                            'maxPrice'
                        ),

                    ':low_24h' =>
                        valueOrNull(
                            $row,
                            'minPrice'
                        ),

                    ':circulating_supply' =>
                        valueOrNull(
                            $row,
                            'circSupply'
                        ),

                    ':liquidity' =>
                        valueOrNull(
                            $row,
                            'liquidity'
                        ),

                    ':holders' =>
                        valueOrNull(
                            $row,
                            'holders'
                        ),

                    ':binance_volume_24h' =>
                        valueOrNull(
                            $row,
                            'bnVolume24H'
                        ),

                    ':binance_buy_volume_24h' =>
                        valueOrNull(
                            $row,
                            'bnBuyVolume24H'
                        ),

                    ':binance_sell_volume_24h' =>
                        valueOrNull(
                            $row,
                            'bnSellVolume24H'
                        ),

                    ':binance_txs_24h' =>
                        valueOrNull(
                            $row,
                            'bnTxs24H'
                        ),

                    ':raw_general_json' =>
                        json_encode(
                            $row,
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_THROW_ON_ERROR
                        ),
                ]);

                $savedThisBatch++;
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $totalSaved += $savedThisBatch;

        $missing =
            count($batch) - $savedThisBatch;

        $totalMissing += max(0, $missing);

        echo "RETURNED: {$returned}\n";
        echo "SAVED: {$savedThisBatch}\n";
        echo "MISSING: " . max(0, $missing) . "\n\n";

        /*
         * Birden fazla batch varsa requestleri
         * arka arkaya bindirmiyoruz.
         */
        if (
            count($batches) > 1
            && $batchNumber < count($batches)
        ) {
            usleep(1200000);
        }

    } catch (Throwable $e) {
        echo "ERROR: {$e->getMessage()}\n";
        exit(1);
    }
}

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$elapsed = round(
    microtime(true) - $started,
    2
);

echo "========================================\n";
echo "FINAL SUMMARY\n";
echo "========================================\n";
echo "REQUESTED TOKENS: " . count($tokens) . "\n";
echo "RETURNED: {$totalReturned}\n";
echo "SAVED: {$totalSaved}\n";
echo "MISSING: {$totalMissing}\n";
echo "BATCHES: " . count($batches) . "\n";
echo "REQUEST ATTEMPTS: {$totalAttempts}\n";
echo "OBSERVED WEIGHT SUM: {$totalWeight}\n";
echo "LOWEST REMAINING: "
    . ($lowestRemaining ?? '-') . "\n";
echo "RATE LIMIT ERRORS: {$rateLimitErrors}\n";
echo "TOTAL TIME: {$elapsed} sec\n";
echo "END UTC: "
    . gmdate('Y-m-d H:i:s') . "\n";