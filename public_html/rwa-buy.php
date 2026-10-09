<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BNB RWA Hub · Purchase quotes</title><link rel="stylesheet" href="css/rwa-chat.css?v=20261005-purchase-quotes"><link rel="stylesheet" href="css/rwa-buy.css?v=5"><script src="js/rwa-buy.js?v=9" defer></script><link rel="stylesheet" href="css/site-layout.css?v=20261010-layout2"></head>
<body><header class="site-header"><div class="header-inner top"><a class="brand" href="./"><img src="assets/brand/bnb-rwa-hub-480.webp" width="240" height="61" alt="BNB RWA Hub"></a><nav aria-label="Main navigation"><a href="rwa-agent.php">Ask Hubble</a><a href="rwa-agent.php?mode=compare">Compare Tokens</a></nav></div></header>
<main class="shell"><section class="intro"><h1 id="title">Purchase quotes</h1><p>Connect your wallet and compare current provider quotes on BNB Smart Chain.</p></section>
<section class="buy-panel"><label for="wallet-select">Wallet</label><select id="wallet-select"></select><button id="connect" type="button">Connect wallet</button><p id="wallet-state">No wallet connected.</p><button id="switch" class="secondary" type="button" hidden>Switch to BNB Smart Chain</button>
<form id="quote-form"><label for="amount">Amount to spend, USDT</label><input id="amount" type="text" inputmode="decimal" value="6" maxlength="24" autocomplete="off" required><button id="quote" type="submit" disabled>Get provider quotes</button></form>
<p class="meta">Purchase amounts are limited to 6–10 USDT for this hackathon demonstration. API trading limits also apply.</p>
<p class="meta">Quotes and reviews do not move funds. Approval is a separate on-chain transaction, requested only after you confirm the amount and spender. A purchase requires a separate confirmation.</p></section>
<section id="purchase-status" class="approval-panel" aria-label="Purchase status" hidden></section><section id="approval-status" class="approval-panel" aria-label="Approval transaction status" hidden></section><div id="quote-status" role="status" aria-live="polite">Loading…</div><section id="quote-results" aria-label="Provider quotes"></section><section id="trade-review" aria-label="Selected trade review" hidden></section>
<footer>Received token quantities may represent different amounts of the underlying stock. Quotes are temporary estimates; availability and final execution can change.</footer></main></body></html>
