<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' https://onchainos.bnbstatic.com https://bin.bnbstatic.com https://public.bnbstatic.com; base-uri 'none'; frame-ancestors 'none'");

$tickerRaw = $_GET['ticker'] ?? '';
$ticker = is_string($tickerRaw) ? strtoupper(trim($tickerRaw)) : '';
$validTicker = preg_match('/^[A-Z0-9][A-Z0-9._-]{0,15}$/', $ticker) === 1;
$pageTicker = $validTicker ? $ticker : 'Asset';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTicker, ENT_QUOTES, 'UTF-8') ?> · BNB RWA Hub</title>
<link rel="stylesheet" href="css/rwa-chat.css?v=20261008-cooldown">
<link rel="stylesheet" href="css/legal.css?v=20261008-legal">
<link rel="stylesheet" href="css/site-nav-assets.css?v=20261009-sharednav1">
<link rel="stylesheet" href="css/asset.css?v=20261009-assettype2">
<link rel="stylesheet" href="css/rwa-buy.css">
<link rel="stylesheet" href="css/hubble-trading.css?v=20261008-fixes">
<script src="js/hubble-buy.js?v=20261008-fixes" defer></script>
<script src="js/asset-buy.js?v=20261008-assetbuy1" defer></script>
<script src="js/asset.js?v=20261009-heroactions1" defer></script>
<script src="js/site-nav.js?v=20261007-nav" defer></script>
<link rel="stylesheet" href="css/about-context-links.css?v=20261009-links-v2"><link rel="stylesheet" href="css/site-layout.css?v=20261010-layout2"></head>
<body data-asset-ticker="<?= htmlspecialchars($validTicker ? $ticker : '', ENT_QUOTES, 'UTF-8') ?>">
<?php require __DIR__ . '/includes/site-header.php'; ?>

<main class="shell asset-page-shell">
<div class="asset-page-toolbar">
  <div class="asset-page-back"><a href="assets.php">← Back to Assets</a></div>
  <div id="asset-page-refresh" class="asset-page-refresh" aria-live="polite">Refreshing in 2m</div>
</div>
<div id="asset-page-loading" class="asset-page-state">Loading saved asset data…</div>
<div id="asset-page-error" class="asset-page-state error" hidden>Saved asset details could not be loaded.</div>
<div id="asset-page-content" hidden></div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
</main>

<dialog id="hubble-trade-dialog" aria-labelledby="hubble-trade-heading">
<header class="trade-dialog-header"><h2 id="hubble-trade-heading">Buy RWA token</h2><div class="trade-header-actions"><button id="hubble-trade-restart" class="secondary" type="button">Start over</button><button id="hubble-trade-close" type="button" aria-label="Close trade window">Close</button></div></header>
<p id="hubble-trade-notice" role="status"></p>
<div id="hubble-buy-root">
<section class="intro"><h1 id="hb-title" data-trade-id="title">Purchase quotes</h1></section>
<section class="buy-panel" data-trade-id="setup"><label for="hb-wallet-select">Wallet</label><select id="hb-wallet-select" data-trade-id="wallet-select"></select><button id="hb-connect" data-trade-id="connect" type="button">Connect wallet</button><button id="hb-disconnect" data-trade-id="disconnect" type="button" hidden>Disconnect</button><p id="hb-wallet-state" data-trade-id="wallet-state">No wallet connected.</p><button id="hb-switch" data-trade-id="switch" class="secondary" type="button" hidden>Switch to BNB Smart Chain</button>
<div class="token-picker" data-trade-id="asset-picker"><label for="hb-token-search">RWA token</label><div class="token-combobox"><input id="hb-token-search" data-trade-id="token-search" type="text" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="hb-token-list" placeholder="Select or search token"><div id="hb-token-list" data-trade-id="token-list" class="token-listbox" role="listbox" hidden></div></div><p class="meta" data-trade-id="token-help">Click the field to browse available assets, or type to filter the list.</p></div>
<form id="hb-quote-form" data-trade-id="quote-form"><label for="hb-amount">Amount to spend, USDT</label><input id="hb-amount" data-trade-id="amount" type="text" inputmode="decimal" value="6" maxlength="24" autocomplete="off" required><button id="hb-quote" data-trade-id="quote" type="submit" disabled>Continue</button></form></section>
<div id="hb-quote-status" data-trade-id="quote-status" role="status" aria-live="polite">Loading…</div>
<div class="purchase-guidance" data-trade-id="setup-guidance"><p class="meta">Purchase amounts are limited to 6–10 USDT for this hackathon demonstration. API trading limits also apply.</p><p class="meta">Quotes and reviews do not move funds. Approval is a separate on-chain transaction, requested only after you confirm the amount and spender. A purchase requires a separate confirmation.</p></div>
<section id="hb-quote-results" data-trade-id="quote-results" aria-label="Provider quotes"></section>
<section id="hb-trade-review" data-trade-id="trade-review" aria-label="Selected trade review" hidden></section>
<section id="hb-approval-status" data-trade-id="approval-status" class="approval-panel" aria-label="Approval transaction status" hidden></section>
<section id="hb-purchase-status" data-trade-id="purchase-status" class="approval-panel" aria-label="Purchase status" hidden></section>
</div>
</dialog>
<?php require __DIR__ . '/includes/site-swap-chooser.php'; ?>
</body>
</html>
