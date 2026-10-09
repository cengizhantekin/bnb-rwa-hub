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
    $dbPass = (string)($config['db_pass'] ?? $config['db_password'] ?? $config['DB_PASS'] ?? $config['DB_PASSWORD'] ?? '');
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

$args = $argv ?? [];
$all = PHP_SAPI === 'cli' ? in_array('--all', $args, true) : isset($_GET['all']);
$compare = PHP_SAPI === 'cli' ? in_array('--compare', $args, true) : isset($_GET['compare']);
$fullHeaders = PHP_SAPI === 'cli' ? in_array('--full-headers', $args, true) : isset($_GET['fullheaders']);
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
} elseif ($ticker !== null) {
    $ticker = strtoupper($ticker);
}

function timestampUtc(): string
{
    $now = microtime(true);
    $sec = (int)floor($now);
    $ms = (int)floor(($now - $sec) * 1000);
    return gmdate('Y-m-d\\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
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
    return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $millis);
}

function valueOrNull(array $row, string $key): mixed
{
    return !array_key_exists($key, $row) || $row[$key] === '' ? null : $row[$key];
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

function web3Get(string $path, array $query, string $apiKey, string $secretKey, int $maxAttempts = 3): array
{
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $requestPath = '/build' . $path . ($queryString !== '' ? '?' . $queryString : '');
    $attempt = 0;
    $rateLimitErrors = 0;

    while ($attempt < $maxAttempts) {
        $attempt++;
        $apiLock = acquireWeb3Coordinator();
        try {
        $timestamp = timestampUtc();
        $signature = base64_encode(hash_hmac('sha256', $timestamp . 'GET' . $requestPath, $secretKey, true));
        $headers = [];
        $ch = curl_init('https://web3.binance.com' . $requestPath);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-OC-APIKEY: ' . $apiKey,
                'X-OC-TIMESTAMP: ' . $timestamp,
                'X-OC-SIGN: ' . $signature,
                'X-OC-RECV-WINDOW: 60000',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        $start = microtime(true);
        $response = curl_exec($ch);
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        } finally {
            releaseWeb3Coordinator($apiLock);
        }

        if ($response === false || $error !== '') {
            throw new RuntimeException('cURL error: ' . $error);
        }

        if ($http === 414) {
            throw new RuntimeException(
                'HTTP 414 URI Too Long. Reduce RWA GET batch size. Request path: '
                . strlen($requestPath) . ' bytes.'
            );
        }

        $json = json_decode($response, true);
        $businessCode = is_array($json) ? (string)($json['code'] ?? '') : '';
        $rateLimited = $http === 429 || $businessCode === '42900';

        $result = [
            'http' => $http,
            'time_ms' => $elapsed,
            'headers' => $headers,
            'limit' => $headers['x-oc-ratelimit-limit'] ?? null,
            'weight' => $headers['x-oc-used-weight'] ?? null,
            'remaining' => $headers['x-oc-ratelimit-remaining'] ?? null,
            'attempts' => $attempt,
            'rate_limit_errors' => $rateLimitErrors,
            'response' => $json,
            'request_bytes' => strlen($requestPath),
        ];

        if (!$rateLimited || $attempt >= $maxAttempts) {
            return $result;
        }

        $rateLimitErrors++;
        $retryAfter = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 0;
        $wait = $retryAfter > 0 ? min($retryAfter, 60) : min(2 ** $attempt, 10);
        echo "RATE LIMIT: {$wait} saniye bekleniyor...\n";
        sleep($wait);
    }

    throw new RuntimeException('Unexpected request state.');
}

if ($ticker !== null) {
    $stmt = $pdo->prepare(
        "SELECT id, provider, symbol, underlying_ticker, chain_id, contract_address
         FROM rwa_tokens
         WHERE chain_id = '56' AND is_active = 1 AND underlying_ticker = ?
         ORDER BY provider"
    );
    $stmt->execute([$ticker]);
    $tokens = $stmt->fetchAll();
} elseif ($compare) {
    $tokens = $pdo->query(
        "SELECT t.id, t.provider, t.symbol, t.underlying_ticker, t.chain_id, t.contract_address
         FROM rwa_tokens t
         INNER JOIN (
             SELECT underlying_ticker
             FROM rwa_tokens
             WHERE chain_id = '56' AND is_active = 1
               AND underlying_ticker IS NOT NULL AND underlying_ticker <> ''
             GROUP BY underlying_ticker
             HAVING COUNT(DISTINCT provider) >= 2
         ) c ON c.underlying_ticker = t.underlying_ticker
         WHERE t.chain_id = '56' AND t.is_active = 1
         ORDER BY t.underlying_ticker, t.provider"
    )->fetchAll();
} elseif ($all) {
    $tokens = $pdo->query(
        "SELECT id, provider, symbol, underlying_ticker, chain_id, contract_address
         FROM rwa_tokens
         WHERE chain_id = '56' AND is_active = 1
         ORDER BY id"
    )->fetchAll();
} else {
    $tokens = $pdo->query(
        "SELECT id, provider, symbol, underlying_ticker, chain_id, contract_address
         FROM rwa_tokens
         WHERE chain_id = '56' AND is_active = 1
         ORDER BY id
         LIMIT 100"
    )->fetchAll();
}

if (!$tokens) {
    exit("ERROR: İşlenecek token bulunamadı.\n");
}

$tokenMap = [];
foreach ($tokens as $token) {
    $tokenMap[strtolower((string)$token['contract_address'])] = $token;
}

$rwaBatchSize = 40;
$batches = array_chunk($tokens, $rwaBatchSize);

$upsert = $pdo->prepare(
    "INSERT INTO rwa_market_latest (
        token_id,
        rwa_token_price,
        reference_price,
        rwa_price_updated_at,
        rwa_api_updated_at,
        raw_rwa_json
    ) VALUES (
        :token_id,
        :rwa_token_price,
        :reference_price,
        :rwa_price_updated_at,
        UTC_TIMESTAMP(),
        :raw_rwa_json
    )
    ON DUPLICATE KEY UPDATE
        rwa_token_price = VALUES(rwa_token_price),
        reference_price = VALUES(reference_price),
        rwa_price_updated_at = VALUES(rwa_price_updated_at),
        rwa_api_updated_at = UTC_TIMESTAMP(),
        raw_rwa_json = VALUES(raw_rwa_json)"
);

$totalReturned = 0;
$totalSaved = 0;
$totalMissing = 0;
$totalAttempts = 0;
$totalWeight = 0;
$rateLimitErrors = 0;
$lowestRemaining = null;
$started = microtime(true);

echo "BNB RWA HUB RWA PRICE SYNC\n";
echo "==========================\n";
echo 'START UTC: ' . gmdate('Y-m-d H:i:s') . "\n";
echo 'TOKENS: ' . count($tokens) . "\n";
echo 'BATCHES: ' . count($batches) . "\n";
echo "BATCH SIZE: {$rwaBatchSize} (safe GET URL size)\n";

if ($ticker !== null) {
    echo "MODE: TICKER\n";
    echo "TICKER: {$ticker}\n";
} elseif ($compare) {
    echo "MODE: CROSS-MARKET COMPARE\n";
} elseif ($all) {
    echo "MODE: ALL\n";
} else {
    echo 'MODE: TEST (' . count($tokens) . " tokens)\n";
}

echo "\n";

foreach ($batches as $batchIndex => $batch) {
    $batchNumber = $batchIndex + 1;
    echo "========================================\n";
    echo 'BATCH ' . $batchNumber . '/' . count($batches) . "\n";
    echo "========================================\n";

    $addresses = array_map(
        static fn(array $token): string => (string)$token['contract_address'],
        $batch
    );

    try {
        $result = web3Get(
            '/api/v1/dex/market/rwa/price',
            [
                'binanceChainId' => '56',
                'tokenContractAddresses' => implode(',', $addresses),
            ],
            $apiKey,
            $secretKey
        );

        $totalAttempts += $result['attempts'];
        $rateLimitErrors += $result['rate_limit_errors'];

        if (is_numeric($result['weight'])) {
            $totalWeight += (int)$result['weight'];
        }

        if (is_numeric($result['remaining'])) {
            $remaining = (int)$result['remaining'];
            $lowestRemaining = $lowestRemaining === null ? $remaining : min($lowestRemaining, $remaining);
        }

        echo 'REQUEST TOKENS: ' . count($batch) . "\n";
        echo "REQUEST PATH: {$result['request_bytes']} bytes\n";
        echo "HTTP: {$result['http']}\n";
        echo "TIME: {$result['time_ms']} ms\n";
        echo 'LIMIT: ' . ($result['limit'] ?? '-') . "\n";
        echo 'WEIGHT: ' . ($result['weight'] ?? '-') . "\n";
        echo 'REMAINING: ' . ($result['remaining'] ?? '-') . "\n";
        echo "ATTEMPTS: {$result['attempts']}\n";

        if ($fullHeaders) {
            echo "\nHEADERS\n-------\n";
            foreach ($result['headers'] as $name => $value) {
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
                'API ERROR: ' . ($json['code'] ?? '-') . ' / ' . ($json['msg'] ?? 'unknown')
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

                $contract = strtolower(trim((string)($row['tokenContractAddress'] ?? '')));
                if ($contract === '' || !isset($tokenMap[$contract])) {
                    continue;
                }

                $upsert->execute([
                    ':token_id' => (int)$tokenMap[$contract]['id'],
                    ':rwa_token_price' => valueOrNull($row, 'tokenPrice'),
                    ':reference_price' => valueOrNull($row, 'referencePrice'),
                    ':rwa_price_updated_at' => msToMysqlDateTime3(valueOrNull($row, 'tokenPriceUpdatedAt')),
                    ':raw_rwa_json' => json_encode(
                        $row,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
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
        $missing = max(0, count($batch) - $savedThisBatch);
        $totalMissing += $missing;

        echo "RETURNED: {$returned}\n";
        echo "SAVED: {$savedThisBatch}\n";
        echo "MISSING: {$missing}\n\n";

        if (count($batches) > 1 && $batchNumber < count($batches)) {
            usleep(1200000);
        }
    } catch (Throwable $e) {
        echo "ERROR: {$e->getMessage()}\n";
        exit(1);
    }
}

$elapsed = round(microtime(true) - $started, 2);

echo "========================================\n";
echo "FINAL SUMMARY\n";
echo "========================================\n";
echo 'REQUESTED TOKENS: ' . count($tokens) . "\n";
echo "RETURNED: {$totalReturned}\n";
echo "SAVED: {$totalSaved}\n";
echo "MISSING: {$totalMissing}\n";
echo 'BATCHES: ' . count($batches) . "\n";
echo "REQUEST ATTEMPTS: {$totalAttempts}\n";
echo "OBSERVED WEIGHT SUM: {$totalWeight}\n";
echo 'LOWEST REMAINING: ' . ($lowestRemaining ?? '-') . "\n";
echo "RATE LIMIT ERRORS: {$rateLimitErrors}\n";
echo "TOTAL TIME: {$elapsed} sec\n";
echo 'END UTC: ' . gmdate('Y-m-d H:i:s') . "\n";
