<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' https://onchainos.bnbstatic.com https://bin.bnbstatic.com https://public.bnbstatic.com; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BNB RWA Hub · Assets</title>
<link rel="stylesheet" href="css/rwa-chat.css?v=20261008-cooldown">
<link rel="stylesheet" href="css/legal.css?v=20261008-legal">
<link rel="stylesheet" href="css/site-nav-assets.css?v=20261009-sharednav1">
<link rel="stylesheet" href="css/assets.css?v=20261009-filters-v2">
<script src="js/assets.js?v=20261009-filters-v1" defer></script>
<script src="js/site-nav.js?v=20261007-nav" defer></script>
<link rel="stylesheet" href="css/about-context-links.css?v=20261009-links-v2"><link rel="stylesheet" href="css/site-layout.css?v=20261010-layout2"></head>
<body>
<?php require __DIR__ . '/includes/site-header.php'; ?>
<main class="shell assets-shell">
<section class="intro assets-intro"><h1>Assets</h1><p>Browse supported underlying assets across bStocks, Ondo and xStocks. Market cap and 24h volume refer to the underlying traditional asset.</p></section>

<section class="assets-workspace" aria-label="Underlying assets">
<div class="assets-toolbar">
<label class="assets-search"><span>Search assets</span><input id="asset-search" type="search" autocomplete="off" spellcheck="false" placeholder="Search ticker or company" maxlength="80"></label>
<label class="assets-type"><span>Asset Type</span><select id="asset-type"><option value="all">All types</option><option value="stock">Stock</option><option value="etf">ETF</option></select></label>
<label class="assets-provider"><span>Provider</span><select id="asset-provider"><option value="all">All providers</option><option value="bstock">bStocks</option><option value="ondo">Ondo</option><option value="xstocks">xStocks</option></select></label>
<label class="assets-signal"><span>Technical Signal</span><select id="asset-signal"><option value="all">All signals</option><option value="trend_alignment">Trend Alignment</option><option value="ma_pullback_recovery">MA Pullback Recovery</option><option value="support_recovery">Support Recovery</option><option value="possible_range_breakout">Possible Range Breakout</option><option value="possible_breakout_retest">Possible Breakout Retest</option></select></label>
</div>

<div class="assets-summary"><span id="assets-count" role="status" aria-live="polite">Loading assets…</span><div class="assets-summary-actions"><a class="assets-reset" href="assets.php" title="Clear search and filters; restore default sort and page">Reset filters</a><span id="assets-refresh" class="assets-refresh" aria-live="polite">Refreshing in 2m</span><span class="assets-sort-mobile"><label for="asset-mobile-sort">Sort</label><select id="asset-mobile-sort"><option value="market_cap">Market cap</option><option value="volume">24h volume</option><option value="ticker">Ticker</option><option value="pnl">24h PnL</option></select><button id="asset-mobile-dir" type="button" aria-label="Reverse sort direction" title="Reverse sort direction">↓</button></span></div></div>

<div id="assets-error" class="assets-error" hidden></div>
<div id="assets-loading" class="assets-loading">Loading saved asset data…</div>
<div id="assets-empty" class="assets-empty" hidden>No assets match the selected filters.</div>

<div id="assets-table-wrap" class="assets-table-wrap" hidden>
<table class="assets-table">
<thead><tr>
<th class="rank-col" scope="col">#</th>
<th class="ticker-col sortable" scope="col"><button type="button" data-sort="ticker">Ticker <span class="sort-mark" aria-hidden="true"></span></button></th>
<th class="company-col" scope="col">Company</th>
<th class="numeric price-col" scope="col">Price</th>
<th class="numeric sortable volume-col" scope="col"><button type="button" data-sort="volume">24h underlying volume <span class="sort-mark" aria-hidden="true"></span></button></th>
<th class="numeric sortable market-col" scope="col"><button type="button" data-sort="market_cap">Underlying market cap <span class="sort-mark" aria-hidden="true"></span></button></th>
<th class="providers-col" scope="col">Providers</th>
<th class="numeric sortable pnl-col" scope="col"><button type="button" data-sort="pnl">24h PnL <span class="sort-mark" aria-hidden="true"></span></button></th>
</tr></thead>
<tbody id="assets-table-body"></tbody>
</table>
</div>

<div id="assets-mobile-list" class="assets-mobile-list" hidden></div>
<nav id="assets-pagination" class="assets-pagination" aria-label="Asset pages" hidden></nav>

<p class="assets-note">The list is underlying-based, so an asset appears once even when multiple token providers support it. Representative price and 24h PnL use the first available provider in the bStocks → Ondo → xStocks preference order. Provider values are not averaged. Technical Signal filters use saved daily token-price evidence from the selected primary technical provider, independent of the Provider coverage filter. They are not trade recommendations.</p>
</section>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
</main>

<?php require __DIR__ . '/includes/site-swap-chooser.php'; ?>
</body>
</html>
