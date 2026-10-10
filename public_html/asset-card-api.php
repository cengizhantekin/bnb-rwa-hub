<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

const CARD_PROVIDERS = ['bstock', 'ondo', 'xstocks'];
const CARD_SIGNALS = [
    'trend_alignment',
    'ma_pullback_recovery',
    'support_recovery',
    'possible_range_breakout',
    'possible_breakout_retest',
];

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

function floatOrNull(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function intOrNull(mixed $value): ?int
{
    return is_numeric($value) ? (int)$value : null;
}

function providerRank(string $provider): int
{
    return match ($provider) {
        'bstock' => 0,
        'ondo' => 1,
        'xstocks' => 2,
        default => 9,
    };
}

function providerLabel(string $provider): string
{
    return match ($provider) {
        'bstock' => 'bStocks',
        'ondo' => 'Ondo',
        'xstocks' => 'xStocks',
        default => $provider,
    };
}

function utcIso(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    } catch (Throwable) {
        return null;
    }
}

function safeLogo(mixed $raw): ?string
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $meta = json_decode($raw, true, 16);
    if (!is_array($meta)) {
        return null;
    }

    foreach (['tokenLogoUrl', 'icon'] as $key) {
        $value = $meta[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 1024) {
            continue;
        }

        if (str_starts_with($value, '/images/web3-data/public/token/logos/')) {
            $value = 'https://bin.bnbstatic.com' . $value;
        }

        $url = parse_url($value);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https') {
            continue;
        }
        if (isset($url['user'], $url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment'])) {
            continue;
        }
        if (!in_array($url['host'] ?? '', ['onchainos.bnbstatic.com', 'bin.bnbstatic.com', 'public.bnbstatic.com'], true)) {
            continue;
        }
        if (!preg_match('~\A/images/web3-data/public/token/logos/[A-Za-z0-9._-]+\.(?:png|jpg|jpeg|webp|svg)\z~i', $url['path'] ?? '')) {
            continue;
        }

        return $value;
    }

    return null;
}

$tickerRaw = $_GET['ticker'] ?? '';
$ticker = is_string($tickerRaw) ? strtoupper(trim($tickerRaw)) : '';
if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,15}$/', $ticker)) {
    respond(['ok' => false, 'error' => 'invalid_ticker'], 400);
}

try {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    $db = chatDb();

    $rankStmt = $db->prepare(
        "SELECT underlying_ticker, underlying_name, selected_provider, token_symbol,
                market_cap, market_cap_rank, market_cap_rank_total,
                volume_24h, volume_24h_rank, volume_24h_rank_total,
                pe_ratio_ttm, asset_total, source_updated_at, generated_at
         FROM rwa_underlying_rank_latest
         WHERE underlying_ticker = ?
         LIMIT 1"
    );
    $rankStmt->execute([$ticker]);
    $rank = $rankStmt->fetch() ?: null;

    $tokenStmt = $db->prepare(
        "SELECT t.id, t.provider, t.platform_id, t.symbol, t.token_name,
                t.underlying_name, t.contract_address, t.decimals,
                t.token_to_share_ratio, t.asset_type, t.source_updated_at,
                t.last_seen_at, LEFT(t.metadata_json, 65536) metadata_json,
                m.price, m.reference_price, m.rwa_token_price,
                m.price_change_24h, m.price_time, m.general_api_updated_at,
                m.rwa_price_updated_at, m.rwa_api_updated_at
         FROM rwa_tokens t
         LEFT JOIN rwa_market_latest m ON m.token_id = t.id
         WHERE t.is_active = 1
           AND t.chain_id = '56'
           AND UPPER(t.underlying_ticker) = ?
           AND t.provider IN ('bstock','ondo','xstocks')
         ORDER BY CASE t.provider
                    WHEN 'bstock' THEN 1
                    WHEN 'ondo' THEN 2
                    WHEN 'xstocks' THEN 3
                    ELSE 9
                  END,
                  t.symbol ASC"
    );
    $tokenStmt->execute([$ticker]);
    $tokenRows = $tokenStmt->fetchAll();

    if (!$tokenRows && $rank === null) {
        respond(['ok' => false, 'error' => 'asset_not_found'], 404);
    }

    $name = trim((string)($rank['underlying_name'] ?? ''));
    if ($name === '') {
        foreach ($tokenRows as $row) {
            $candidateName = trim((string)($row['underlying_name'] ?? ''));
            if ($candidateName !== '') {
                $name = $candidateName;
                break;
            }
        }
    }
    if ($name === '') {
        foreach ($tokenRows as $row) {
            $candidateName = trim((string)($row['token_name'] ?? ''));
            if ($candidateName !== '') {
                $name = $candidateName;
                break;
            }
        }
    }

    $byProvider = [];
    $representations = [];
    $logo = null;

    foreach ($tokenRows as $row) {
        $provider = strtolower(trim((string)($row['provider'] ?? '')));
        if (!in_array($provider, CARD_PROVIDERS, true)) {
            continue;
        }

        $rowLogo = safeLogo($row['metadata_json'] ?? null);
        if ($logo === null && $rowLogo !== null) {
            $logo = $rowLogo;
        }

        $referencePrice = floatOrNull($row['reference_price'] ?? null);
        $generalPrice = floatOrNull($row['price'] ?? null);
        $rwaTokenPrice = floatOrNull($row['rwa_token_price'] ?? null);

        if ($referencePrice !== null) {
            $price = $referencePrice;
            $priceSourceKind = 'RWA reference';
            $priceSourceTime = utcIso($row['rwa_price_updated_at'] ?? null);
            $priceFetchTime = utcIso($row['rwa_api_updated_at'] ?? null);
        } elseif ($generalPrice !== null) {
            $price = $generalPrice;
            $priceSourceKind = 'General market';
            $priceSourceTime = utcIso($row['price_time'] ?? null);
            $priceFetchTime = utcIso($row['general_api_updated_at'] ?? null);
        } else {
            $price = $rwaTokenPrice;
            $priceSourceKind = $rwaTokenPrice !== null ? 'RWA token' : null;
            $priceSourceTime = utcIso($row['rwa_price_updated_at'] ?? null);
            $priceFetchTime = utcIso($row['rwa_api_updated_at'] ?? null);
        }

        $candidate = [
            'provider' => $provider,
            'symbol' => trim((string)($row['symbol'] ?? '')),
            'price' => $price,
            'price_source_kind' => $priceSourceKind,
            'price_source_time' => $priceSourceTime,
            'price_fetch_time' => $priceFetchTime,
            'pnl_24h' => floatOrNull($row['price_change_24h'] ?? null),
            'logo_url' => $rowLogo,
            'pnl_source_time' => utcIso($row['price_time'] ?? null),
            'pnl_fetch_time' => utcIso($row['general_api_updated_at'] ?? null),
        ];

        if (!isset($byProvider[$provider])) {
            $byProvider[$provider] = $candidate;
        } else {
            $existing = $byProvider[$provider];
            $existingScore = ($existing['price'] !== null ? 2 : 0) + ($existing['pnl_24h'] !== null ? 1 : 0);
            $candidateScore = ($candidate['price'] !== null ? 2 : 0) + ($candidate['pnl_24h'] !== null ? 1 : 0);
            if ($candidateScore > $existingScore) {
                $byProvider[$provider] = $candidate;
            }
        }

        $providerTokenPrice = $rwaTokenPrice ?? $generalPrice;
        $providerTokenPriceSource = $rwaTokenPrice !== null
            ? 'RWA token'
            : ($generalPrice !== null ? 'General market' : null);

        $representations[] = [
            'provider' => $provider,
            'provider_label' => providerLabel($provider),
            'symbol' => trim((string)($row['symbol'] ?? '')),
            'token_name' => trim((string)($row['token_name'] ?? '')) ?: null,
            'contract_address' => strtolower(trim((string)($row['contract_address'] ?? ''))),
            'decimals' => intOrNull($row['decimals'] ?? null),
            'token_to_share_ratio' => is_numeric($row['token_to_share_ratio'] ?? null)
                ? (string)$row['token_to_share_ratio']
                : null,
            'token_price' => $providerTokenPrice,
            'token_price_source_kind' => $providerTokenPriceSource,
            'asset_type_code' => intOrNull($row['asset_type'] ?? null),
            'catalogue_source_utc' => utcIso($row['source_updated_at'] ?? null),
            'catalogue_seen_utc' => utcIso($row['last_seen_at'] ?? null),
        ];
    }

    // One atomic read statement binds the published snapshot and contract rows.
    // KYC/holders missing from the ranking remain NULL, not zero.
    $holderSnapshotAt = null;
    $holderSnapshotStale = true;
    try {
        $addresses = array_values(array_unique(array_filter(
            array_column($representations, 'contract_address'),
            static fn($v) => is_string($v) && preg_match('/\A0x[a-f0-9]{40}\z/', $v)
        )));
        if ($addresses) {
            $marks = implode(',', array_fill(0, count($addresses), '?'));
            $holderStmt = $db->prepare("SELECT m.fetched_at_utc, r.contract_address, r.provider,
                   r.token_symbol, r.underlying_ticker, r.holders, r.kyc_holders, r.holders_top10_pct,
                   r.position holder_rank, m.received_total holder_rank_total
                FROM rwa_wallet_stock_rank_meta m
                LEFT JOIN rwa_wallet_stock_rank_rows r ON r.snapshot_id=m.snapshot_id
                    AND r.contract_address IN ($marks)
                WHERE m.id=1");
            $holderStmt->execute($addresses);
            $byContract = [];
            $fetchedAt = null;
            foreach ($holderStmt as $row) {
                $fetchedAt = $row['fetched_at_utc'] ?? null;
                if (!empty($row['contract_address'])) $byContract[$row['contract_address']] = $row;
            }
            $holderSnapshotAt = utcIso($fetchedAt);
            $holderTimestamp = is_string($fetchedAt) ? strtotime($fetchedAt.' UTC') : false;
            $holderSnapshotStale = $holderTimestamp === false || (time() - $holderTimestamp) > 5400;
            foreach ($representations as &$rep) {
                $h = $byContract[$rep['contract_address']] ?? null;
                if (!$h || $h['provider'] !== $rep['provider'] || $h['token_symbol'] !== $rep['symbol']
                    || $h['underlying_ticker'] !== $ticker) continue;
                $rep['holders'] = intOrNull($h['holders']);
                $rep['kyc_holders'] = intOrNull($h['kyc_holders']);
                $rep['holders_top10_pct'] = floatOrNull($h['holders_top10_pct']);
                $rep['holder_rank'] = intOrNull($h['holder_rank']);
                $rep['holder_rank_total'] = intOrNull($h['holder_rank_total']);
            }
            unset($rep);
        }
    } catch (Throwable $holderError) {
        // New optional tables may not yet be deployed; never break existing Asset Detail.
        error_log('[asset-card-api] optional holder snapshot unavailable: ' . $holderError->getMessage());
    }

    usort($representations, function (array $a, array $b): int {
        $cmp = providerRank($a['provider']) <=> providerRank($b['provider']);
        return $cmp !== 0 ? $cmp : strnatcasecmp((string)$a['symbol'], (string)$b['symbol']);
    });

    $preferred = strtolower(trim((string)($rank['selected_provider'] ?? '')));
    $display = null;
    if ($preferred !== '' && isset($byProvider[$preferred])) {
        $display = $byProvider[$preferred];
    }

    if ($display === null || ($display['price'] === null && $display['pnl_24h'] === null)) {
        foreach (CARD_PROVIDERS as $provider) {
            $candidate = $byProvider[$provider] ?? null;
            if ($candidate !== null && ($candidate['price'] !== null || $candidate['pnl_24h'] !== null)) {
                $display = $candidate;
                break;
            }
        }
    }

    if ($display === null) {
        foreach (CARD_PROVIDERS as $provider) {
            if (isset($byProvider[$provider])) {
                $display = $byProvider[$provider];
                break;
            }
        }
    }

    if ($display !== null && $display['logo_url'] !== null) {
        $logo = $display['logo_url'];
    }

    $technical = null;
    try {
        $technicalStmt = $db->prepare(
            "SELECT ticker, provider, symbol, status, close_price,
                    sma20, sma50, sma200, rsi14,
                    source_candle_open_utc, api_fetch_at_utc, signals_json
             FROM rwa_technical_latest
             WHERE ticker = ?
             LIMIT 1"
        );
        $technicalStmt->execute([$ticker]);
        $technicalRow = $technicalStmt->fetch() ?: null;

        if ($technicalRow !== null) {
            $signals = [];
            try {
                $decoded = json_decode((string)($technicalRow['signals_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ($decoded as $signal) {
                        if (is_string($signal) && in_array($signal, CARD_SIGNALS, true) && !in_array($signal, $signals, true)) {
                            $signals[] = $signal;
                        }
                    }
                }
            } catch (Throwable) {
                $signals = [];
            }

            $technical = [
                'status' => (string)($technicalRow['status'] ?? ''),
                'provider' => (string)($technicalRow['provider'] ?? ''),
                'provider_label' => providerLabel((string)($technicalRow['provider'] ?? '')),
                'symbol' => (string)($technicalRow['symbol'] ?? ''),
                'close' => floatOrNull($technicalRow['close_price'] ?? null),
                'sma20' => floatOrNull($technicalRow['sma20'] ?? null),
                'sma50' => floatOrNull($technicalRow['sma50'] ?? null),
                'sma200' => floatOrNull($technicalRow['sma200'] ?? null),
                'rsi14' => floatOrNull($technicalRow['rsi14'] ?? null),
                'signals' => $signals,
                'source_candle_utc' => utcIso($technicalRow['source_candle_open_utc'] ?? null),
                'api_fetch_utc' => utcIso($technicalRow['api_fetch_at_utc'] ?? null),
            ];
        }
    } catch (Throwable $technicalError) {
        error_log('[asset-card-api] technical lookup unavailable for ' . $ticker . ': ' . $technicalError->getMessage());
    }

    respond([
        'ok' => true,
        'data' => [
            'identity' => [
                'ticker' => $ticker,
                'name' => $name,
                'logo_url' => $logo,
            ],
            'market' => [
                'price' => $display['price'] ?? null,
                'pnl_24h' => $display['pnl_24h'] ?? null,
                'price_provider' => $display['provider'] ?? null,
                'price_provider_label' => isset($display['provider']) ? providerLabel((string)$display['provider']) : null,
                'price_symbol' => $display['symbol'] ?? null,
                'price_source_kind' => $display['price_source_kind'] ?? null,
                'market_cap' => floatOrNull($rank['market_cap'] ?? null),
                'market_cap_rank' => intOrNull($rank['market_cap_rank'] ?? null),
                'market_cap_rank_total' => intOrNull($rank['market_cap_rank_total'] ?? null) ?? 0,
                'volume_24h' => floatOrNull($rank['volume_24h'] ?? null),
                'volume_24h_rank' => intOrNull($rank['volume_24h_rank'] ?? null),
                'volume_24h_rank_total' => intOrNull($rank['volume_24h_rank_total'] ?? null) ?? 0,
            ],
            'representations' => $representations,
            'holders_snapshot' => ['fetched_at_utc'=>$holderSnapshotAt, 'stale'=>$holderSnapshotStale],
            'technical' => $technical,
            'freshness' => [
                'price_source_utc' => $display['price_source_time'] ?? null,
                'price_fetch_utc' => $display['price_fetch_time'] ?? null,
                'pnl_source_utc' => $display['pnl_source_time'] ?? null,
                'pnl_fetch_utc' => $display['pnl_fetch_time'] ?? null,
                'underlying_metrics_fetch_utc' => utcIso($rank['source_updated_at'] ?? null),
                'rank_snapshot_utc' => utcIso($rank['generated_at'] ?? null),
                'technical_candle_utc' => $technical['source_candle_utc'] ?? null,
                'technical_fetch_utc' => $technical['api_fetch_utc'] ?? null,
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[asset-card-api] ' . $ticker . ' unavailable: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'asset_card_unavailable'], 500);
}
