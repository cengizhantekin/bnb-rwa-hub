<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="A quick guide to exploring, researching and trading tokenized stocks and ETFs with BNB RWA Hub.">
<title>BNB RWA Hub · Help &amp; Guide</title>
<link rel="stylesheet" href="css/rwa-chat.css?v=20261009-research-v2">
<link rel="stylesheet" href="css/legal.css?v=20261008-legal">
<link rel="stylesheet" href="css/site-nav-assets.css?v=20261008-assetpage1">
<link rel="stylesheet" href="css/help.css?v=20261009-help-v1">
<script src="js/help.js?v=20261009-help-v1" defer></script>
<script src="js/site-nav.js?v=20261007-nav" defer></script>
<link rel="stylesheet" href="css/site-layout.css?v=20261010-layout2"></head>
<body>
<header class="site-header"><div class="header-inner top"><a class="brand" href="./"><img src="assets/brand/bnb-rwa-hub-480.webp" srcset="assets/brand/bnb-rwa-hub-960.webp 2x" width="240" height="61" alt="BNB RWA Hub" decoding="async"></a><nav class="site-nav" aria-label="Main navigation"><a class="site-nav-item" href="./"><span class="nav-glyph" aria-hidden="true">▦</span><span>Dashboard</span></a><a class="site-nav-item" href="assets.php"><span class="nav-glyph" aria-hidden="true">▤</span><span>Assets</span></a><a class="site-nav-item" href="rwa-agent.php"><img class="nav-hubble" src="assets/hubble/hubble-48.webp" width="20" height="20" alt="" decoding="async"><span>Ask Hubble</span></a><button id="nav-swap" class="site-nav-item nav-button" type="button"><span class="nav-glyph" aria-hidden="true">⇄</span><span>Swap</span></button><a class="site-nav-item active" href="help.php" aria-current="page"><span class="nav-glyph" aria-hidden="true">?</span><span>Help</span></a></nav></div></header>
<main class="shell guide-shell">
<section class="guide-intro" aria-labelledby="guide-heading">
  <div>
    <h1 id="guide-heading">Help &amp; Guide</h1>
    <p>A quick introduction to exploring tokenized stocks and ETFs, researching opportunities and trading on BNB Smart Chain.</p>
  </div>
  <a class="guide-intro-link" href="assets.php">Browse Assets <span aria-hidden="true">→</span></a>
</section>
<section class="guide-workspace" aria-label="Help topics">
  <div class="guide-tabs" role="tablist" aria-label="Help sections">
    <button class="guide-tab" id="guide-tab-explore" type="button" role="tab" aria-selected="true" aria-controls="guide-panel-explore" tabindex="0" data-guide-tab="explore">Explore</button>
    <button class="guide-tab" id="guide-tab-hubble" type="button" role="tab" aria-selected="false" aria-controls="guide-panel-hubble" tabindex="-1" data-guide-tab="hubble">Ask Hubble</button>
    <button class="guide-tab" id="guide-tab-trading" type="button" role="tab" aria-selected="false" aria-controls="guide-panel-trading" tabindex="-1" data-guide-tab="trading">Trading</button>
    <button class="guide-tab" id="guide-tab-data" type="button" role="tab" aria-selected="false" aria-controls="guide-panel-data" tabindex="-1" data-guide-tab="data">Data &amp; Safety</button>
  </div>
  <div class="guide-panels">
    <section class="guide-panel" id="guide-panel-explore" role="tabpanel" aria-labelledby="guide-tab-explore" tabindex="0" data-guide-panel="explore">
      <div class="guide-panel-head"><h2>Explore RWA assets</h2><p>Discover supported stocks and ETFs across bStocks, Ondo and xStocks.</p></div>
      <div class="guide-cards">
        <article class="guide-card"><div class="guide-number">01</div><h3>Dashboard</h3><p>View market movers, underlying market rankings and technical opportunity highlights.</p><a href="./">Open Dashboard <span aria-hidden="true">→</span></a></article>
        <article class="guide-card"><div class="guide-number">02</div><h3>Assets</h3><p>Search, sort and filter by provider, Stock / ETF type or a saved technical signal.</p><a href="assets.php">Browse Assets <span aria-hidden="true">→</span></a></article>
        <article class="guide-card"><div class="guide-number">03</div><h3>Asset Details</h3><p>Select a ticker to inspect underlying metrics, provider tokens, technical evidence and freshness.</p><a href="assets.php">Choose an asset <span aria-hidden="true">→</span></a></article>
      </div>
      <p class="guide-footnote">An underlying asset appears once in the Assets directory, even if multiple providers support it.</p>
    </section>

    <section class="guide-panel" id="guide-panel-hubble" role="tabpanel" aria-labelledby="guide-tab-hubble" tabindex="0" data-guide-panel="hubble" hidden>
      <div class="guide-panel-head"><h2>Ask Hubble</h2><p>Use natural language to explore saved market data, compare assets and investigate potential opportunities.</p></div>
      <div class="guide-cards">
        <article class="guide-card"><div class="guide-number">01</div><h3>Analyze</h3><p>Ask about an asset's recorded market and technical evidence.</p><div class="guide-example">Analyze TSLA.</div></article>
        <article class="guide-card"><div class="guide-number">02</div><h3>Compare</h3><p>Compare multiple companies or inspect token providers.</p><div class="guide-example">Compare NVDA, AMD and MSFT by market cap.</div></article>
        <article class="guide-card"><div class="guide-number">03</div><h3>Discover</h3><p>Explore rule-based technical candidates and ask for supporting evidence.</p><div class="guide-example">Find 5 RWA opportunities.</div></article>
      </div>
      <div class="guide-panel-actions"><a class="guide-action" href="rwa-agent.php">Ask Hubble <span aria-hidden="true">→</span></a><p>Answers depend on saved data availability and may not reflect live market conditions.</p></div>
    </section>

    <section class="guide-panel" id="guide-panel-trading" role="tabpanel" aria-labelledby="guide-tab-trading" tabindex="0" data-guide-panel="trading" hidden>
      <div class="guide-panel-head"><h2>Buy, sell &amp; swap</h2><p>Use the Hub's wallet-based trading flows to review available token quotes.</p></div>
      <div class="guide-cards">
        <article class="guide-card"><div class="guide-number">01</div><h3>Choose</h3><p>Start with Buy on an Asset page or choose Buy / Sell from the Swap menu.</p></article>
        <article class="guide-card"><div class="guide-number">02</div><h3>Review</h3><p>Connect a supported wallet on BNB Smart Chain and review fresh quotes and transaction details.</p></article>
        <article class="guide-card"><div class="guide-number">03</div><h3>Confirm</h3><p>Any required spending approval and the trade itself need separate, explicit wallet confirmations.</p></article>
      </div>
      <div class="guide-panel-actions"><a class="guide-action" href="assets.php">Find an asset <span aria-hidden="true">→</span></a><p>Quotes may expire or be unavailable. Network gas fees and demo transaction limits apply.</p></div>
    </section>

    <section class="guide-panel" id="guide-panel-data" role="tabpanel" aria-labelledby="guide-tab-data" tabindex="0" data-guide-panel="data" hidden>
      <div class="guide-panel-head"><h2>Data &amp; Safety</h2><p>Know what the numbers mean before using them in a decision.</p></div>
      <div class="guide-cards">
        <article class="guide-card"><div class="guide-number">01</div><h3>Underlying vs. Token</h3><p>Underlying market cap and trading volume are distinct from provider token prices, liquidity and activity. Provider values are not averaged.</p></article>
        <article class="guide-card"><div class="guide-number">02</div><h3>Data freshness</h3><p>API fetch time, source price update time and technical candle time can differ. Check the timestamps shown for each asset.</p></article>
        <article class="guide-card"><div class="guide-number">03</div><h3>Technical signals</h3><p>Trend, pullback, support and breakout rules describe recorded token-price patterns. They are not predictions or buy recommendations.</p></article>
      </div>
      <p class="guide-footnote">Do your own research. All on-chain spending approvals and transactions require your wallet confirmation.</p>
      <div class="guide-legal-actions"><a href="risk-disclosure.php">Read Risk Disclosure <span aria-hidden="true">→</span></a></div>
    </section>
  </div>
</section>
<footer class="site-footer"><p>BNB RWA Hub is a research and trading demonstration. Saved observations may differ in freshness by source.</p><nav class="legal-links" aria-label="Legal and information"><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms and Conditions</a><a href="risk-disclosure.php">Risk Disclosure</a><a href="cookies.php">Cookie Policy</a></nav></footer>
</main>
<dialog id="swap-chooser" class="swap-chooser" aria-labelledby="swap-chooser-title"><div class="swap-chooser-head"><h2 id="swap-chooser-title">Swap RWA tokens</h2><button type="button" class="swap-close" data-swap-close aria-label="Close">×</button></div><p>Choose what you want to do.</p><div class="swap-options"><button type="button" data-swap-mode="buy">Buy RWA Tokens</button><button type="button" data-swap-mode="sale">Sell RWA Tokens</button></div></dialog>
</body>
</html>
