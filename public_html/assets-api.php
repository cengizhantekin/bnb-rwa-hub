<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

const ASSET_PAGE_SIZE = 50;
const ASSET_PROVIDERS = ['bstock', 'ondo', 'xstocks'];
const ASSET_SORTS = ['ticker', 'volume', 'market_cap', 'pnl'];
const ASSET_TYPES = ['stock', 'etf'];
const ASSET_TECHNICAL_SIGNALS = [
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

function compareNullable(?float $a, ?float $b, string $dir): int
{
    if ($a === null && $b === null) {
        return 0;
    }
    if ($a === null) {
        return 1;
    }
    if ($b === null) {
        return -1;
    }

    $cmp = $a <=> $b;
    return $dir === 'desc' ? -$cmp : $cmp;
}

$pageRaw = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = is_int($pageRaw) ? $pageRaw : 1;

$sort = $_GET['sort'] ?? 'market_cap';
if (!is_string($sort) || !in_array($sort, ASSET_SORTS, true)) {
    $sort = 'market_cap';
}

$dir = $_GET['dir'] ?? ($sort === 'ticker' ? 'asc' : 'desc');
if (!is_string($dir) || !in_array($dir, ['asc', 'desc'], true)) {
    $dir = $sort === 'ticker' ? 'asc' : 'desc';
}

$query = $_GET['q'] ?? '';
$query = is_string($query) ? trim($query) : '';
if (strlen($query) > 80) {
    $query = substr($query, 0, 80);
}

$providerFilter = $_GET['provider'] ?? 'all';
if (!is_string($providerFilter) || ($providerFilter !== 'all' && !in_array($providerFilter, ASSET_PROVIDERS, true))) {
    $providerFilter = 'all';
}

$typeFilter = $_GET['asset_type'] ?? 'all';
if (!is_string($typeFilter) || ($typeFilter !== 'all' && !in_array($typeFilter, ASSET_TYPES, true))) {
    $typeFilter = 'all';
}

$signalFilter = $_GET['signal'] ?? 'all';
if (!is_string($signalFilter) || ($signalFilter !== 'all' && !in_array($signalFilter, ASSET_TECHNICAL_SIGNALS, true))) {
    $signalFilter = 'all';
}

try {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    $db = chatDb();

    $rankRows = [];
    $rankStmt = $db->query(
        "SELECT underlying_ticker, underlying_name, selected_provider, token_symbol,
                market_cap, market_cap_rank, market_cap_rank_total,
                volume_24h, volume_24h_rank, volume_24h_rank_total,
                asset_total, source_updated_at, generated_at
         FROM rwa_underlying_rank_latest"
    );

    foreach ($rankStmt as $row) {
        $ticker = strtoupper(trim((string)($row['underlying_ticker'] ?? '')));
        if ($ticker === '') {
            continue;
        }
        $rankRows[$ticker] = $row;
    }

    // Only consult saved technical evidence when a signal filter is requested.
    // Technical rows are keyed by underlying ticker; the saved primary-provider
    // daily token series does not change with the provider coverage filter.
    $signalTickers = [];
    if ($signalFilter !== 'all') {
        $signalStmt = $db->query(
            "SELECT ticker, signals_json FROM rwa_technical_latest
             WHERE status = 'technical_candidate'"
        );
        foreach ($signalStmt as $signalRow) {
            $technicalTicker = strtoupper(trim((string)($signalRow['ticker'] ?? '')));
            if ($technicalTicker === '') {
                continue;
            }
            try {
                $codes = json_decode((string)($signalRow['signals_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($codes) && in_array($signalFilter, $codes, true)) {
                $signalTickers[$technicalTicker] = true;
            }
        }
    }

    $tokenStmt = $db->query(
        "SELECT t.id, t.underlying_ticker, t.underlying_name, t.token_name,
                t.provider, t.symbol, t.asset_type, LEFT(t.metadata_json, 65536) metadata_json,
                m.price, m.reference_price, m.rwa_token_price,
                m.price_change_24h, m.price_time, m.general_api_updated_at
         FROM rwa_tokens t
         LEFT JOIN rwa_market_latest m ON m.token_id = t.id
         WHERE t.is_active = 1
           AND t.chain_id = '56'
           AND t.provider IN ('bstock','ondo','xstocks')
           AND t.underlying_ticker IS NOT NULL
           AND t.underlying_ticker <> ''"
    );

    $groups = [];

    foreach ($tokenStmt as $row) {
        $ticker = strtoupper(trim((string)$row['underlying_ticker']));
        $provider = strtolower(trim((string)$row['provider']));
        if ($ticker === '' || !in_array($provider, ASSET_PROVIDERS, true)) {
            continue;
        }

        $name = trim((string)($row['underlying_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['token_name'] ?? ''));
        }

        if (!isset($groups[$ticker])) {
            $groups[$ticker] = [
                'ticker' => $ticker,
                'name' => $name,
                'providers' => [],
                'provider_rows' => [],
                'asset_type_codes' => [],
            ];
        } elseif ($groups[$ticker]['name'] === '' && $name !== '') {
            $groups[$ticker]['name'] = $name;
        }

        $groups[$ticker]['providers'][$provider] = true;
        $groups[$ticker]['asset_type_codes'][] = intOrNull($row['asset_type'] ?? null);

        $price = floatOrNull($row['reference_price'] ?? null)
            ?? floatOrNull($row['price'] ?? null)
            ?? floatOrNull($row['rwa_token_price'] ?? null);

        $candidate = [
            'provider' => $provider,
            'symbol' => trim((string)($row['symbol'] ?? '')),
            'price' => $price,
            'pnl_24h' => floatOrNull($row['price_change_24h'] ?? null),
            'logo_url' => safeLogo($row['metadata_json'] ?? null),
            'price_time' => $row['price_time'] ?? null,
            'fetch_time' => $row['general_api_updated_at'] ?? null,
        ];

        if (!isset($groups[$ticker]['provider_rows'][$provider])) {
            $groups[$ticker]['provider_rows'][$provider] = $candidate;
        } else {
            $existing = $groups[$ticker]['provider_rows'][$provider];
            $existingScore = ($existing['price'] !== null ? 2 : 0) + ($existing['pnl_24h'] !== null ? 1 : 0);
            $candidateScore = ($candidate['price'] !== null ? 2 : 0) + ($candidate['pnl_24h'] !== null ? 1 : 0);
            if ($candidateScore > $existingScore) {
                $groups[$ticker]['provider_rows'][$provider] = $candidate;
            }
        }
    }

    $items = [];

    foreach ($groups as $ticker => $group) {
        $rank = $rankRows[$ticker] ?? null;
        $rankName = trim((string)($rank['underlying_name'] ?? ''));
        $name = $rankName !== '' ? $rankName : (string)$group['name'];

        $providers = array_keys($group['providers']);
        usort($providers, fn(string $a, string $b): int => providerRank($a) <=> providerRank($b));

        // Match the dedicated Asset page: classify only when every active
        // representation agrees (1 = Stock, 3 = ETF). Unknown stays unknown.
        $codes = $group['asset_type_codes'];
        $assetType = null;
        if ($codes && count(array_filter($codes, static fn(?int $code): bool => $code === 1)) === count($codes)) {
            $assetType = 'stock';
        } elseif ($codes && count(array_filter($codes, static fn(?int $code): bool => $code === 3)) === count($codes)) {
            $assetType = 'etf';
        }

        if ($typeFilter !== 'all' && $assetType !== $typeFilter) {
            continue;
        }
        if ($signalFilter !== 'all' && !isset($signalTickers[$ticker])) {
            continue;
        }

        if ($providerFilter !== 'all' && !isset($group['providers'][$providerFilter])) {
            continue;
        }

        if ($query !== '') {
            if (stripos($ticker, $query) === false && stripos($name, $query) === false) {
                continue;
            }
        }

        $preferredProvider = strtolower(trim((string)($rank['selected_provider'] ?? '')));
        $displayRow = null;

        if ($preferredProvider !== '' && isset($group['provider_rows'][$preferredProvider])) {
            $displayRow = $group['provider_rows'][$preferredProvider];
        }

        if ($displayRow === null || ($displayRow['price'] === null && $displayRow['pnl_24h'] === null)) {
            foreach ($providers as $provider) {
                $candidate = $group['provider_rows'][$provider] ?? null;
                if ($candidate !== null && ($candidate['price'] !== null || $candidate['pnl_24h'] !== null)) {
                    $displayRow = $candidate;
                    break;
                }
            }
        }

        if ($displayRow === null && $providers) {
            $displayRow = $group['provider_rows'][$providers[0]] ?? null;
        }

        $logo = $displayRow['logo_url'] ?? null;
        if ($logo === null) {
            foreach ($providers as $provider) {
                $candidateLogo = $group['provider_rows'][$provider]['logo_url'] ?? null;
                if ($candidateLogo !== null) {
                    $logo = $candidateLogo;
                    break;
                }
            }
        }

        $items[] = [
            'ticker' => $ticker,
            'name' => $name,
            'asset_type' => $assetType,
            'market_cap_rank' => intOrNull($rank['market_cap_rank'] ?? null),
            'market_cap_rank_total' => intOrNull($rank['market_cap_rank_total'] ?? null) ?? 0,
            'market_cap' => floatOrNull($rank['market_cap'] ?? null),
            'volume_24h' => floatOrNull($rank['volume_24h'] ?? null),
            'volume_24h_rank' => intOrNull($rank['volume_24h_rank'] ?? null),
            'volume_24h_rank_total' => intOrNull($rank['volume_24h_rank_total'] ?? null) ?? 0,
            'price' => $displayRow['price'] ?? null,
            'pnl_24h' => $displayRow['pnl_24h'] ?? null,
            'logo_url' => $logo,
            'providers' => array_map(
                fn(string $provider): array => [
                    'id' => $provider,
                    'label' => providerLabel($provider),
                    'symbol' => (string)($group['provider_rows'][$provider]['symbol'] ?? ''),
                ],
                $providers
            ),
            'price_provider' => $displayRow['provider'] ?? null,
        ];
    }

    usort($items, function (array $a, array $b) use ($sort, $dir): int {
        if ($sort === 'ticker') {
            $cmp = strnatcasecmp($a['ticker'], $b['ticker']);
            return $dir === 'desc' ? -$cmp : $cmp;
        }

        $field = match ($sort) {
            'volume' => 'volume_24h',
            'pnl' => 'pnl_24h',
            default => 'market_cap',
        };

        $cmp = compareNullable($a[$field], $b[$field], $dir);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strnatcasecmp($a['ticker'], $b['ticker']);
    });

    $total = count($items);
    $pages = max(1, (int)ceil($total / ASSET_PAGE_SIZE));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * ASSET_PAGE_SIZE;
    $pageItems = array_slice($items, $offset, ASSET_PAGE_SIZE);
    foreach ($pageItems as $index => &$pageItem) {
        $pageItem['display_rank'] = $offset + $index + 1;
    }
    unset($pageItem);

    respond([
        'ok' => true,
        'data' => [
            'items' => $pageItems,
            'pagination' => [
                'page' => $page,
                'per_page' => ASSET_PAGE_SIZE,
                'total_items' => $total,
                'total_pages' => $pages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + ASSET_PAGE_SIZE, $total),
            ],
            'state' => [
                'sort' => $sort,
                'dir' => $dir,
                'q' => $query,
                'provider' => $providerFilter,
                'asset_type' => $typeFilter,
                'signal' => $signalFilter,
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[assets-api] ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'assets_unavailable'], 500);
}
