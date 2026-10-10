-- BNB RWA Hub: contract-keyed read-only Binance Stock Rank snapshot.
-- Apply once in the bhub_bhub database before enabling the collector.
-- InnoDB allows atomic pointer updates and non-destructive failed runs.
CREATE TABLE IF NOT EXISTS rwa_wallet_stock_rank_rows (
    snapshot_id CHAR(32) NOT NULL,
    contract_address CHAR(42) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position INT UNSIGNED NOT NULL,
    token_symbol VARCHAR(40) NOT NULL,
    provider VARCHAR(16) NOT NULL,
    underlying_ticker VARCHAR(16) NOT NULL,
    holders BIGINT UNSIGNED NOT NULL,
    kyc_holders BIGINT UNSIGNED NULL,
    holders_top10_pct DECIMAL(9,6) NOT NULL,
    PRIMARY KEY (snapshot_id, contract_address),
    UNIQUE KEY snapshot_position (snapshot_id, position),
    KEY snapshot_ticker (snapshot_id, underlying_ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rwa_wallet_stock_rank_meta (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    snapshot_id CHAR(32) NOT NULL,
    source VARCHAR(48) NOT NULL,
    chain_id SMALLINT UNSIGNED NOT NULL,
    rank_type SMALLINT UNSIGNED NOT NULL,
    sort_by SMALLINT UNSIGNED NOT NULL,
    period_code SMALLINT UNSIGNED NOT NULL,
    expected_total INT UNSIGNED NOT NULL,
    received_total INT UNSIGNED NOT NULL,
    fetched_at_utc DATETIME NOT NULL,
    http_requests SMALLINT UNSIGNED NOT NULL,
    http_duration_ms DECIMAL(12,1) NOT NULL,
    request_weight INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
