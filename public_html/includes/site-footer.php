<?php
// Shared legal/footer links. Page-specific notes are centralized here.
$sitePage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$siteFooterNotes = [
    'index.php' => 'Dashboard values are saved observations and may differ in freshness by source. Source timestamps and collector fetch timestamps are tracked separately.',
    'assets.php' => 'Asset values are saved observations and may differ in freshness by source.',
    'asset.php' => 'Asset values are saved observations and may differ in freshness by source.',
    'about.php' => 'BNB RWA Hub · Research information only. Tokenized assets and blockchain transactions involve risk.',
    'privacy.php' => 'BNB RWA Hub is an experimental RWA research and trading interface. Review the disclosures below before using wallet or trading features.',
    'terms.php' => 'BNB RWA Hub is an experimental RWA research and trading interface. Review the disclosures below before using wallet or trading features.',
    'risk-disclosure.php' => 'BNB RWA Hub is an experimental RWA research and trading interface. Review the disclosures below before using wallet or trading features.',
    'cookies.php' => 'BNB RWA Hub is an experimental RWA research and trading interface. Review the disclosures below before using wallet or trading features.',
];
$siteFooterNote = $siteFooterNotes[$sitePage] ?? '';
$siteLegalLinks = [
    'privacy.php' => 'Privacy Policy',
    'terms.php' => 'Terms and Conditions',
    'risk-disclosure.php' => 'Risk Disclosure',
    'cookies.php' => 'Cookie Policy',
];
?>
<footer class="site-footer<?= $sitePage === 'about.php' ? ' about-footer' : '' ?>">
<?php if ($siteFooterNote !== ''): ?>
<p><?= htmlspecialchars($siteFooterNote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<?php endif; ?>
<nav class="legal-links" aria-label="Legal and information">
<?php foreach ($siteLegalLinks as $href => $label): ?>
<a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $sitePage === $href ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
<?php endforeach; ?>
</nav>
</footer>
