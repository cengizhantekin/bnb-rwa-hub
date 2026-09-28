<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require 'C:\\www\\htdocs\\_private\\bhub\\config.php';

$apiKey = trim((string)$config['binance_web3_api_key']);
$secretKey = trim((string)$config['binance_web3_secret_key']);

function timestampUtc(): string
{
    $now = microtime(true);
    $sec = (int)floor($now);
    $ms = (int)floor(($now - $sec) * 1000);

    return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
}

function web3Get(
    string $path,
    array $query,
    string $apiKey,
    string $secretKey
): array {
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $requestPath = '/build' . $path;

    if ($queryString !== '') {
        $requestPath .= '?' . $queryString;
    }

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
    $ch = curl_init('https://web3.binance.com' . $requestPath);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
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

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'path' => $requestPath,
        'http' => $httpCode,
        'time_ms' => $elapsed,
        'weight' => $headers['x-oc-used-weight'] ?? null,
        'remaining' => $headers['x-oc-ratelimit-remaining'] ?? null,
        'error' => $error,
        'response' => is_string($response)
            ? json_decode($response, true)
            : null,
    ];
}

$tests = [
    'ONDO BSC' => web3Get(
        '/api/v1/dex/market/rwa/tokens',
        [
            'binanceChainId' => '56',
            'platformId' => 'ondo',
        ],
        $apiKey,
        $secretKey
    ),

    'BSTOCK BSC' => web3Get(
        '/api/v1/dex/market/rwa/tokens',
        [
            'binanceChainId' => '56',
            'platformId' => 'bstock',
        ],
        $apiKey,
        $secretKey
    ),

    'XSTOCKS TEST' => web3Get(
        '/api/v1/dex/market/rwa/tokens',
        [
            'binanceChainId' => '56',
            'platformId' => 'xstocks',
        ],
        $apiKey,
        $secretKey
    ),
];

header('Content-Type: text/plain; charset=utf-8');

foreach ($tests as $name => $test) {
    echo "========================================\n";
    echo $name . "\n";
    echo "========================================\n";
    echo "PATH: {$test['path']}\n";
    echo "HTTP: {$test['http']}\n";
    echo "TIME: {$test['time_ms']} ms\n";
    echo "WEIGHT: " . ($test['weight'] ?? '-') . "\n";
    echo "REMAINING: " . ($test['remaining'] ?? '-') . "\n";

    if ($test['error'] !== '') {
        echo "CURL ERROR: {$test['error']}\n";
    }

    $response = $test['response'];

    if (!is_array($response)) {
        echo "Invalid response\n\n";
        continue;
    }

    echo "CODE: " . ($response['code'] ?? '-') . "\n";
    echo "MSG: " . ($response['msg'] ?? '-') . "\n";
    echo "SUCCESS: " . (($response['success'] ?? false) ? 'true' : 'false') . "\n";

    if (isset($response['data']) && is_array($response['data'])) {
        echo "DATA COUNT: " . count($response['data']) . "\n";

        if (!empty($response['data'])) {
            echo "\nFIRST ITEM:\n";
            echo json_encode(
                $response['data'][0],
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE
            );
            echo "\n";
        }
    }

    echo "\n\n";
}