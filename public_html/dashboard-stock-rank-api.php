<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
function rankReply(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}
// Apply the same catalogue-logo allowlist used by Asset Detail and Market Pulse.
function rankLogo(mixed $raw): ?string {
    if (!is_string($raw) || $raw === '') return null;
    $meta = json_decode($raw, true, 16);
    if (!is_array($meta)) return null;
    foreach (['tokenLogoUrl', 'icon'] as $key) {
        $url = $meta[$key] ?? null;
        if (!is_string($url) || $url === '' || strlen($url) > 1024) continue;
        if (str_starts_with($url, '/images/web3-data/public/token/logos/')) {
            $url = 'https://bin.bnbstatic.com' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https') continue;
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) continue;
        if (!in_array($parts['host'] ?? '', ['onchainos.bnbstatic.com', 'bin.bnbstatic.com', 'public.bnbstatic.com'], true)) continue;
        if (!preg_match('~\A/images/web3-data/public/token/logos/[A-Za-z0-9._-]+\.(?:png|jpg|jpeg|webp|svg)\z~i', $parts['path'] ?? '')) continue;
        return $url;
    }
    return null;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') rankReply(['ok'=>false,'error'=>'method_not_allowed'],405);
$base=['ok'=>true,'source'=>'binance_wallet_skills','skill'=>'crypto-market-rank',
    'chain_id'=>'56','rank_type'=>40,'sort_by'=>30,'period'=>'24h','available'=>false,
    'items'=>[],'total'=>null,'fetched_at_utc'=>null,'http_requests'=>0,'duration_ms'=>0.0,
    'request_weight'=>null,'snapshot_age_seconds'=>null,'stale'=>true,'data_source'=>'database_snapshot'];
try {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    $db=chatDb();
    // One read statement joins the currently published snapshot pointer and rows,
    // so a concurrent collector commit cannot produce a mixed/partial ranking.
    $q=$db->query("SELECT r.position, r.contract_address, r.token_symbol, r.provider,
           r.underlying_ticker, r.holders, r.kyc_holders, r.holders_top10_pct,
           t.symbol AS active_symbol, t.underlying_ticker AS active_ticker,
           m.received_total, m.fetched_at_utc, m.http_requests,
           m.http_duration_ms, m.request_weight
        FROM rwa_wallet_stock_rank_meta m
        JOIN rwa_wallet_stock_rank_rows r ON r.snapshot_id=m.snapshot_id
        JOIN rwa_tokens t ON LOWER(t.contract_address)=r.contract_address
             AND t.chain_id='56' AND t.is_active=1 AND t.provider=r.provider
        WHERE m.id=1 ORDER BY r.position ASC LIMIT 10");
    $records=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$records) rankReply($base);
    $meta=$records[0];
    // Asset Detail selects the first valid underlying logo by provider priority.
    // Keep the same logo across multiple ranked token representations of one stock.
    // Logo metadata is optional and is loaded from the local catalogue only.
    $underlyingLogos=[];
    $tickers=array_values(array_unique(array_map(
        static fn(array $row): string => strtoupper(trim((string)$row['underlying_ticker'])),
        $records
    )));
    $tickers=array_values(array_filter($tickers, static fn(string $t): bool => $t!==''));
    if ($tickers) {
        try {
            $markers=implode(',', array_fill(0,count($tickers),'?'));
            $logoStmt=$db->prepare("SELECT UPPER(underlying_ticker) AS ticker,
                    LEFT(metadata_json,65536) AS metadata_json
                FROM rwa_tokens
                WHERE chain_id='56' AND is_active=1
                  AND provider IN ('bstock','ondo','xstocks')
                  AND UPPER(underlying_ticker) IN ($markers)
                ORDER BY CASE provider WHEN 'bstock' THEN 1 WHEN 'ondo' THEN 2
                         WHEN 'xstocks' THEN 3 ELSE 9 END, symbol ASC");
            $logoStmt->execute($tickers);
            foreach ($logoStmt as $candidate) {
                $key=(string)$candidate['ticker'];
                if (isset($underlyingLogos[$key])) continue;
                $url=rankLogo($candidate['metadata_json']??null);
                if ($url!==null) $underlyingLogos[$key]=$url;
            }
        } catch (Throwable $logoError) {
            // An optional image lookup must never hide valid saved holder ranks.
            error_log('[dashboard-stock-rank-api] logo lookup unavailable: '.$logoError->getMessage());
        }
    }
    $items=[];
    foreach ($records as $r) {
        if ($r['active_symbol'] !== $r['token_symbol'] || strtoupper((string)$r['active_ticker']) !== $r['underlying_ticker']) continue;
        $items[]=['position'=>(int)$r['position'],'symbol'=>$r['token_symbol'],
            'contract'=>$r['contract_address'],'matched_ticker'=>$r['underlying_ticker'],
            'matched_symbol'=>$r['active_symbol'],
            'logo_url'=>$underlyingLogos[$r['underlying_ticker']]??null,
            'provider'=>match($r['provider']){'bstock'=>'bStocks','ondo'=>'Ondo','xstocks'=>'xStocks',default=>$r['provider']},
            'holders'=>(int)$r['holders'],'kyc_holders'=>$r['kyc_holders']===null?null:(int)$r['kyc_holders'],
            'top10_pct'=>(float)$r['holders_top10_pct']];
    }
    $ts=strtotime((string)$meta['fetched_at_utc'].' UTC');
    $age=$ts===false?null:max(0,time()-$ts);
    $base['available']=count($items)>0;
    $base['items']=$items;
    $base['total']=(int)$meta['received_total'];
    $base['fetched_at_utc']=$ts===false?null:gmdate('Y-m-d\TH:i:s\Z',$ts);
    $base['snapshot_age_seconds']=$age;
    $base['stale']=$age===null || $age>5400;
    // Historical collector diagnostics: NOT HTTP calls made by this page request.
    $base['last_collector_http_requests']=(int)$meta['http_requests'];
    $base['last_collector_duration_ms']=(float)$meta['http_duration_ms'];
    $base['last_collector_request_weight']=$meta['request_weight']===null?null:(int)$meta['request_weight'];
    rankReply($base);
} catch (Throwable $e) {
    error_log('[dashboard-stock-rank-api] '.$e->getMessage());
    rankReply($base); // No live API fallback and no unverified data.
}
