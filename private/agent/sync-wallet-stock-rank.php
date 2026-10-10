<?php
declare(strict_types=1);
/** Binance Wallet Skill-documented public BAPI workflow, NOT an installed agent skill.
 * CLI only. 30-minute systemd schedule; no signing, approvals, DB source mutation, or user inputs.
 * Request weight is unknown unless Binance sends a documented used-weight header.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('BNB_WALLET_STOCK_FIXTURE_TEST')) {
    require '/home/bhub.tm.biz.tr/private/agent/chat-store.php';
    require __DIR__ . '/wallet-skills-rank.php';
}

const STOCK_PAGE_SIZE = 200;
const STOCK_MAX_TOTAL = 2400;

function stockInt(mixed $v): ?int {
    if (is_int($v)) return $v >= 0 ? $v : null;
    if (!is_string($v) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $v)) return null;
    $n = filter_var($v, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    return $n === false ? null : $n;
}
function stockPct(mixed $v): ?float {
    if (!is_numeric($v) || is_bool($v)) return null;
    $n = (float)$v;
    return is_finite($n) && $n >= 0 && $n <= 100 ? $n : null;
}
function stockFetchPage(int $page): array {
    $request = json_encode(['rankType'=>40,'chainId'=>'56','period'=>50,
        'sortBy'=>30,'orderAsc'=>false,'page'=>$page,'size'=>STOCK_PAGE_SIZE], JSON_THROW_ON_ERROR);
    $c = curl_init(WSR_ENDPOINT);
    if ($c === false) throw new RuntimeException('curl_init_failed');
    $weight = null;
    curl_setopt_array($c, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$request, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>18, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Accept-Encoding: identity'],
        CURLOPT_USERAGENT=>'binance-web3/3.0 (Skill)',
        CURLOPT_HEADERFUNCTION=>static function ($c, string $header) use (&$weight): int {
            if (preg_match('/\AX-OC-USED-WEIGHT:\s*(\d+)\s*\r?\n\z/i', $header, $m)) $weight = (int)$m[1];
            return strlen($header);
        },
    ]);
    $started = microtime(true);
    $raw = curl_exec($c);
    $ms = round((microtime(true) - $started) * 1000, 1);
    $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_errno($c);
    curl_close($c);
    $v = is_string($raw) && strlen($raw) <= 2500000 ? json_decode($raw, true, 64) : null;
    $code = is_array($v) ? (string)($v['code'] ?? '') : '';
    printf("Page %d: HTTP=%d, code=%s, time=%.1fms, weight=%s\n", $page, $status, $code,
        $ms, $weight === null ? 'unknown' : (string)$weight);
    if ($err !== 0 || $status !== 200 || !is_array($v) || ($v['success'] ?? null) !== true
        || $code !== '000000' || !is_array($v['data'] ?? null)
        || !is_array($v['data']['tokens'] ?? null)) throw new RuntimeException('binance_page_unavailable_' . $page);
    return ['tokens'=>$v['data']['tokens'],'total'=>stockInt($v['data']['total'] ?? null),
        'ms'=>$ms,'weight'=>$weight];
}
function stockLoadCatalog(PDO $db): array {
    $q = $db->query("SELECT provider, symbol, underlying_ticker, contract_address FROM rwa_tokens
                     WHERE chain_id='56' AND is_active=1
                       AND provider IN ('bstock','ondo','xstocks')");
    $map=[];
    foreach ($q as $r) {
        $c=wsrContract($r['contract_address'] ?? null);
        if ($c === null || isset($map[$c])) throw new RuntimeException('catalog_duplicate_or_invalid_contract');
        if (!in_array($r['provider'], ['bstock','ondo','xstocks'], true)
            || wsrTicker($r['underlying_ticker'] ?? null) === null
            || wsrSymbol($r['symbol'] ?? null) === null) throw new RuntimeException('catalog_identity_invalid');
        $map[$c]=$r;
    }
    if (!$map) throw new RuntimeException('empty_active_catalog');
    return $map;
}
function stockParse(array $token, array $catalog, int $position): array {
    if ((string)($token['chainId'] ?? '') !== '56') throw new RuntimeException('wrong_chain');
    $contract=wsrContract($token['contractAddress'] ?? null);
    $symbol=wsrSymbol($token['symbol'] ?? null);
    if ($contract === null || $symbol === null || !isset($catalog[$contract])) throw new RuntimeException('invalid_or_unmapped_contract');
    $matched=$catalog[$contract];
    if ($matched['symbol'] !== $symbol) throw new RuntimeException('contract_symbol_mismatch');
    $holders=stockInt($token['holders'] ?? null);
    $kyc=stockInt($token['kycHolders'] ?? null);
    $top=stockPct($token['holdersTop10Percent'] ?? null);
    if ($holders === null || $top === null || ($kyc !== null && $kyc > $holders)) {
        throw new RuntimeException('invalid_holder_metrics');
    }
    return ['contract'=>$contract,'position'=>$position,'symbol'=>$symbol,'provider'=>(string)$matched['provider'],
        'ticker'=>strtoupper((string)$matched['underlying_ticker']), 'holders'=>$holders,
        'kyc'=>$kyc,'top'=>$top];
}
function stockMain(): void {
    $lock=fopen('/tmp/bhub-wallet-stock-rank.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('collector_already_running');
    try {
        $db=chatDb();
        // Protect the public BAPI from accidental repeated manual collector starts.
        // The optional --force flag is reserved for deliberate operator testing.
        $force = in_array('--force', $_SERVER['argv'] ?? [], true);
        $last = $db->query('SELECT fetched_at_utc FROM rwa_wallet_stock_rank_meta WHERE id=1')
            ->fetchColumn();
        $lastAt = is_string($last) ? strtotime($last.' UTC') : false;
        if (!$force && $lastAt !== false && time()-$lastAt >= 0 && time()-$lastAt < 1200) {
            echo "Recent snapshot under 20 minutes old; skipping Binance requests.\n";
            return;
        }
        $catalog=stockLoadCatalog($db);
        $all=[];$seen=[];$total=null;$duration=0.;$count=0;$priorHolders=null;
        for ($page=1; $page<=12; $page++) {
            if ($page > 1) usleep(1500000);
            $r=stockFetchPage($page);$count++;$duration+=$r['ms'];
            // An optional used-weight response header may be cumulative; do not sum it
            // or represent it as the per-request cost of this public BAPI call.
            if ($r['total'] === null || $r['total'] === 0 || $r['total'] > STOCK_MAX_TOTAL
                || ($total !== null && $r['total'] !== $total)) throw new RuntimeException('unstable_or_invalid_total');
            $total=$r['total'];
            if (count($r['tokens']) < 1 || count($r['tokens']) > STOCK_PAGE_SIZE) throw new RuntimeException('invalid_page_size');
            foreach ($r['tokens'] as $token) {
                if (!is_array($token)) throw new RuntimeException('invalid_token');
                $item=stockParse($token,$catalog,count($all)+1);
                if (isset($seen[$item['contract']])) throw new RuntimeException('duplicate_contract_across_pages');
                if ($priorHolders !== null && $item['holders'] > $priorHolders) throw new RuntimeException('holder_rank_inversion');
                $seen[$item['contract']]=true;$priorHolders=$item['holders'];$all[]=$item;
            }
            if (count($all)>$total) throw new RuntimeException('more_rows_than_reported_total');
            if (count($all)===$total) break;
            if (count($r['tokens']) !== STOCK_PAGE_SIZE) throw new RuntimeException('premature_short_page');
        }
        if (count($all)!==$total) throw new RuntimeException('incomplete_snapshot');
        // Abort if the catalogue changed while network pages were being fetched.
        $check=stockLoadCatalog($db);
        if (count($catalog) !== count($check)) throw new RuntimeException('catalog_changed_during_collection');
        foreach ($catalog as $contract=>$original) {
            $current=$check[$contract] ?? null;
            if ($current === null || $original['provider'] !== $current['provider']
                || $original['symbol'] !== $current['symbol']
                || strtoupper((string)$original['underlying_ticker']) !== strtoupper((string)$current['underlying_ticker'])) {
                throw new RuntimeException('catalog_changed_during_collection');
            }
        }
        $id=bin2hex(random_bytes(16));$at=gmdate('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $insert=$db->prepare('INSERT INTO rwa_wallet_stock_rank_rows
                (snapshot_id,contract_address,position,token_symbol,provider,underlying_ticker,holders,kyc_holders,holders_top10_pct)
                VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($all as $v) $insert->execute([$id,$v['contract'],$v['position'],$v['symbol'],$v['provider'],
                $v['ticker'],$v['holders'],$v['kyc'],$v['top']]);
            $meta=$db->prepare('INSERT INTO rwa_wallet_stock_rank_meta
                (id,snapshot_id,source,chain_id,rank_type,sort_by,period_code,expected_total,received_total,
                 fetched_at_utc,http_requests,http_duration_ms,request_weight)
                VALUES (1,?,\'binance_wallet_skills\',56,40,30,50,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE snapshot_id=VALUES(snapshot_id),
                expected_total=VALUES(expected_total),received_total=VALUES(received_total),
                fetched_at_utc=VALUES(fetched_at_utc),http_requests=VALUES(http_requests),
                http_duration_ms=VALUES(http_duration_ms),request_weight=VALUES(request_weight)');
            $meta->execute([$id,$total,count($all),$at,$count,round($duration,1),null]);
            $db->prepare('DELETE FROM rwa_wallet_stock_rank_rows WHERE snapshot_id <> ?')->execute([$id]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        printf("Published: %d/%d contracts; calls=%d; summed_ms=%.1f; weight=%s; UTC=%s\n",
            count($all),$total,$count,$duration,'unknown',gmdate('c'));
    } finally {flock($lock, LOCK_UN);fclose($lock);}
}
if (!defined('BNB_WALLET_STOCK_FIXTURE_TEST')) {
    try { stockMain(); }
    catch (Throwable $e) {fwrite(STDERR, 'Stock snapshot unavailable: ' . $e->getMessage() . "\n"); exit(1);}
}
