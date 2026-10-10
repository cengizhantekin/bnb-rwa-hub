<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/wallet-skills-asset.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function envelope(array $data): array { return ['code'=>'000000','success'=>true,'data'=>$data]; }

// Offline fixtures only. These values are invented test data, not market observations.
$rows = [
    ['provider'=>'bstock','symbol'=>'TESTB','contract_address'=>'0x' . str_repeat('1', 40)],
    ['provider'=>'ondo','symbol'=>'TESTon','contract_address'=>'0x' . str_repeat('2', 40)],
    ['provider'=>'xstocks','symbol'=>'TESTx','contract_address'=>'0x' . str_repeat('3', 40)],
];
$counter = 0;
$requestsSeen = [];
$fixture = function (array $requests) use (&$counter, &$requestsSeen): array {
    $counter++;
    $requestsSeen = $requests;
    return [
        'audit_0' => envelope(['hasResult'=>true,'isSupported'=>true,'riskLevelEnum'=>'LOW','riskLevel'=>1,
            'extraInfo'=>['buyTax'=>'0','sellTax'=>'0','isVerified'=>true],
            'riskItems'=>[['details'=>[['title'=>'Mock flag','description'=>'Mock finding', 'isHit'=>true,'riskType'=>'CAUTION']]]]]),
        'audit_1' => envelope(['hasResult'=>false,'isSupported'=>true,'riskLevelEnum'=>'HIGH','riskLevel'=>5]),
        'audit_2' => envelope(['hasResult'=>true,'isSupported'=>true,'riskLevelEnum'=>'HIGH','riskLevel'=>5,
            'extraInfo'=>['buyTax'=>null,'sellTax'=>'2.5','isVerified'=>false], 'riskItems'=>[]]),
        'market' => envelope(['openState'=>false,'marketStatus'=>'closed','reasonCode'=>'ASSET_PAUSED','reasonMsg'=>'stock_split',
            'nextOpenTime'=>1790000000000]),
        'meta' => envelope(['ticker'=>'TEST','symbol'=>'TESTon',
            'dailyAttestationReports'=>'/images/web3-data/public/token/ondo/pdf/daily-2026.pdf',
            'monthlyAttestationReports'=>'https://evil.invalid/fake.pdf', 'companyInfo'=>['industry'=>'Testing','ceo'=>'Test CEO']]),
        'dynamic'=>envelope(['ticker'=>'TEST','stockInfo'=>['priceToEarnings'=>'27.5','dividendYield'=>'0.35',
            'priceHigh52w'=>'100','priceLow52w'=>'40'],
            'tokenInfo'=>['totalHolders'=>'400','circulatingSupply'=>'100','marketCap'=>'12000']]),
    ];
};
$cache = sys_get_temp_dir() . '/bhub-skills-test-' . bin2hex(random_bytes(6));
try {
    $result = wsLoadAsset($rows, 'TEST', $fixture, $cache);
    check($result['ok']===true && $result['available']===true, 'Valid fixture accepted');
    check($result['http_requests']===6 && $counter===1, 'Bounded to six documented requests');
    check(count($requestsSeen)===6, 'All requests prepared');
    check(str_starts_with($requestsSeen['audit_1']['url'], 'https://web3.binance.com/'), 'Fixed audit host');
    check(str_contains($requestsSeen['market']['url'], 'chainId=56'), 'BSC only');
    check(count($result['audits'])===3, 'One audit per verified token representation');
    check($result['audits'][0]['audit']['risk']==='LOW', 'Valid risk retained');
    check($result['audits'][1]['audit']===['available'=>false], 'Unsupported audit suppresses risk data');
    check($result['audits'][2]['audit']['risk_level']===5, 'High-risk level retained without transaction action');
    check($result['ondo']['status']['open']===false, 'Closed Ondo status is valid');
    check($result['ondo']['status']['reason']==='stock_split', 'Corporate-action code retained');
    check($result['ondo']['fundamentals']['dividend_yield_pct']===0.35, 'Dividend yield kept as percentage');
    check($result['ondo']['reports']['monthly']===null, 'Untrusted report URL discarded');
    $again = wsLoadAsset($rows, 'TEST', static function (): array { throw new RuntimeException('Unexpected re-fetch'); }, $cache);
    check($again['cache_hit']===true && $again['http_requests']===0 && $counter===1, 'Cache hit causes no Binance requests');
    check(wsReportUrl('//evil.invalid/a.pdf')===null, 'Protocol-relative URL rejected');
    check(wsContract('0x0')===null, 'Invalid contract rejected');
    check(wsAudit(envelope(['hasResult'=>true,'isSupported'=>false,'riskLevelEnum'=>'HIGH','riskLevel'=>5]))['available']===false, 'Unsupported audit cannot show score');
    check(wsOndo(['dynamic'=>envelope(['ticker'=>'WRONG','stockInfo'=>['priceToEarnings'=>'9000']])], 'TEST')['fundamentals']===null, 'Cross-ticker data refused');
    check(wsOndo(['meta'=>envelope(['ticker'=>'WRONG','companyInfo'=>['industry'=>'Malicious']])], 'TEST')['company']===null, 'Cross-ticker metadata refused');
} finally {
    foreach (glob($cache.'/*') ?: [] as $file) @unlink($file);
    @rmdir($cache);
}
