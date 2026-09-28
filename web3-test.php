<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo '<pre>';

try {
    // 1. Config
    $configFile = '/home1/occnxzte/private/bhub/config.php';
	$configFile = 'C:\\www\\htdocs\\_private\\bhub\\config.php';
    if (!is_readable($configFile)) {
        throw new RuntimeException('Config okunamıyor: ' . $configFile);
    }

    $config = require $configFile;

    $apiKey = trim((string)($config['binance_web3_api_key'] ?? ''));
    $secretKey = trim((string)($config['binance_web3_secret_key'] ?? ''));

    if ($apiKey === '' || $secretKey === '') {
        throw new RuntimeException('API Key veya Secret boş.');
    }

    echo "CONFIG: OK\n";

    // 2. Timestamp
    $now = microtime(true);
    $seconds = (int)floor($now);
    $milliseconds = (int)floor(($now - $seconds) * 1000);
    $timestamp = gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $milliseconds);

    // 3. Request
    $method = 'GET';
    $requestPath = '/build/api/v1/dex/market/rwa/platforms';
    $url = 'https://web3.binance.com' . $requestPath;
    $body = '';

    // 4. Signature
    $preHash = $timestamp . $method . $requestPath . $body;
    $signature = base64_encode(
        hash_hmac('sha256', $preHash, $secretKey, true)
    );

    echo "TIMESTAMP: {$timestamp}\n";
    echo "ENDPOINT: {$requestPath}\n";
    echo "SIGNATURE GENERATED: YES\n\n";

    // 5. API Request
    $responseHeaders = [];

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('curl_init başarısız.');
    }

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
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $length;
        },
    ]);

    $start = microtime(true);
    $response = curl_exec($ch);
    $elapsed = round((microtime(true) - $start) * 1000, 2);

    if ($response === false) {
        throw new RuntimeException(
            'cURL #' . curl_errno($ch) . ': ' . curl_error($ch)
        );
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $remoteIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);

    curl_close($ch);

    echo "HTTP STATUS: {$httpCode}\n";
    echo "RESPONSE TIME: {$elapsed} ms\n";
    echo "REMOTE IP: {$remoteIp}\n\n";

    echo "RATE LIMIT HEADERS\n";
    echo "------------------\n";

    foreach ($responseHeaders as $name => $value) {
        if (
            str_contains($name, 'rate') ||
            str_contains($name, 'limit') ||
            str_contains($name, 'weight') ||
            $name === 'retry-after'
        ) {
            echo "{$name}: {$value}\n";
        }
    }

    echo "\nAPI RESPONSE\n";
    echo "------------\n";

    $json = json_decode($response, true);

    if (is_array($json)) {
        echo json_encode(
            $json,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    } else {
        echo $response;
    }

} catch (Throwable $e) {
    echo "\nERROR\n";
    echo "-----\n";
    echo get_class($e) . "\n";
    echo $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

echo '</pre>';