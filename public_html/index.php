<?php
declare(strict_types=1);
ini_set('display_errors','0');
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
<title>BNB RWA Hub · Dashboard</title>
<link rel="stylesheet" href="css/rwa-chat.css?v=20261008-cooldown">
<link rel="stylesheet" href="css/legal.css?v=20261008-legal">
<link rel="stylesheet" href="css/site-nav-assets.css?v=20261009-sharednav1">
<link rel="stylesheet" href="css/dashboard.css?v=20261010-mostheldlogo1">
<script src="js/dashboard.js?v=20261010-mostheldlogo1" defer></script>
<script src="js/site-nav.js?v=20261007-nav" defer></script>
<link rel="stylesheet" href="css/about-context-links.css?v=20261009-links-v2"><link rel="stylesheet" href="css/site-layout.css?v=20261010-layout2"></head>
<body>
<?php require __DIR__ . '/includes/site-header.php'; ?>
<main class="shell dashboard-shell">
<section class="intro dashboard-intro"><div><h1>Dashboard</h1><p>BNB Smart Chain tokenized-asset coverage, market snapshots and technical evidence from saved data.</p></div></section>

<section class="dashboard-workspace" aria-label="RWA dashboard">
<div class="dashboard-tabs" role="tablist" aria-label="Dashboard sections">
<button id="tab-overview" class="dashboard-tab active" type="button" role="tab" aria-selected="true" aria-controls="panel-overview" data-dashboard-tab="overview"><svg class="dashboard-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect></svg><span>Overview</span></button>
<button id="tab-pulse" class="dashboard-tab" type="button" role="tab" aria-selected="false" aria-controls="panel-pulse" data-dashboard-tab="pulse"><svg class="dashboard-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h4l2.2-6 4.1 11 2.2-6H21"></path></svg><span>Market Pulse</span></button>
<button id="tab-opportunities" class="dashboard-tab" type="button" role="tab" aria-selected="false" aria-controls="panel-opportunities" data-dashboard-tab="opportunities"><svg class="dashboard-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><circle cx="12" cy="12" r="3"></circle><path d="M17.5 6.5 21 3m0 0v4m0-4h-4"></path></svg><span>Opportunities</span></button>
<button id="tab-providers" class="dashboard-tab" type="button" role="tab" aria-selected="false" aria-controls="panel-providers" data-dashboard-tab="providers"><svg class="dashboard-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Z"></path><path d="m3 12 9 5 9-5M3 16l9 5 9-5"></path></svg><span>Providers</span></button>
<button id="tab-health" class="dashboard-tab" type="button" role="tab" aria-selected="false" aria-controls="panel-health" data-dashboard-tab="health"><svg class="dashboard-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l2-5 4 10 2-5h6"></path><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path></svg><span>Data Health</span></button>
</div>
<div class="dashboard-refresh-line"><span id="dashboard-refresh-status" role="status" aria-live="polite">Loading dashboard…</span></div>

<section id="panel-overview" class="dashboard-panel" role="tabpanel" aria-labelledby="tab-overview" data-dashboard-panel="overview"><div class="dashboard-loading">Loading saved dashboard data…</div></section>
<section id="panel-pulse" class="dashboard-panel" role="tabpanel" aria-labelledby="tab-pulse" data-dashboard-panel="pulse" hidden><div class="dashboard-loading">Market Pulse loads when opened.</div></section>
<section id="panel-opportunities" class="dashboard-panel" role="tabpanel" aria-labelledby="tab-opportunities" data-dashboard-panel="opportunities" hidden><div class="dashboard-loading">Opportunity data loads when opened.</div></section>
<section id="panel-providers" class="dashboard-panel" role="tabpanel" aria-labelledby="tab-providers" data-dashboard-panel="providers" hidden><div class="dashboard-loading">Provider coverage loads when opened.</div></section>
<section id="panel-health" class="dashboard-panel" role="tabpanel" aria-labelledby="tab-health" data-dashboard-panel="health" hidden><div class="dashboard-loading">Data Health loads when opened.</div></section>
<noscript><p class="dashboard-error">JavaScript is required for the tabbed dashboard. The data APIs remain read-only and use saved database snapshots.</p></noscript>
</section>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
</main>
<?php require __DIR__ . '/includes/site-swap-chooser.php'; ?>
</body>
</html>
