<?php

declare(strict_types=1);

// Template only. Copy to private/config.php on the server and protect file permissions.
// Never commit the filled configuration or expose it through public_html.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'REPLACE_WITH_DATABASE_NAME',
    'db_user' => 'REPLACE_WITH_DATABASE_USER',
    'db_password' => 'REPLACE_WITH_DATABASE_PASSWORD',
    'binance_web3_api_key' => 'REPLACE_WITH_BINANCE_API_KEY',
    'binance_web3_secret_key' => 'REPLACE_WITH_BINANCE_SECRET_KEY',
    // Optional, used by selected integration test paths:
    'evm_wallet' => '',
];
