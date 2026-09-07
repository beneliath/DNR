<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/short_link_helpers.php';
function expectShortLink(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.com');
expectShortLink(shortLinkUrl('0123456789abcdef') === 'https://moed.example.com/surls/0123456789abcdef', 'Canonical short URL');
foreach (['javascript:alert(1)', "https://example.com/\r\nX:hi", 'https://user:pass@example.com', 'https://moed.example.com/surls/0123456789abcdef'] as $target) {
    try { shortLinkTarget($target); throw new RuntimeException('Unsafe target accepted'); } catch (InvalidArgumentException $expected) {}
}
expectShortLink(shortLinkTarget('https://example.com/books?a=1&b=2#title') === 'https://example.com/books?a=1&b=2#title', 'Target query and fragment preserved');
expectShortLink(shortLinkStatsDates('2026-02-01', '2026-02-28') === ['2026-02-01 00:00:00', '2026-03-01 00:00:00'], 'Inclusive UTC end date');
foreach ([['2026-02-30','2026-03-01'], ['2026-03-02','2026-03-01'], ['2020-01-01','2026-01-01']] as [$from,$to]) {
    try { shortLinkStatsDates($from,$to); throw new RuntimeException('Bad dates accepted'); } catch (InvalidArgumentException $expected) {}
}
$server = ['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'203.0.113.10', 'HTTP_CF_IPCOUNTRY'=>'US',
    'HTTP_CF_RAY'=>'0123456789abcdef-ORD','HTTP_CF_CONNECTING_IP'=>'203.0.113.10',
    'HTTP_REFERER'=>'https://example.com/private?token=secret',
    'HTTP_USER_AGENT'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'];
$d = shortLinkVisitDimensions($server);
expectShortLink($d['browser'] === 'Chrome' && $d['os'] === 'Windows' && $d['referrer'] === 'example.com' && $d['country'] === 'ZZ', 'Dimensions and untrusted country header');
expectShortLink(shortLinkVisitDimensions(array_replace($server, ['REQUEST_METHOD'=>'HEAD'])) === null, 'HEAD excluded');
expectShortLink(shortLinkVisitDimensions(array_replace($server, ['HTTP_PURPOSE'=>'prefetch'])) === null, 'Prefetch excluded');
expectShortLink(shortLinkVisitDimensions(array_replace($server, ['HTTP_USER_AGENT'=>'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])) === null, 'Known bots excluded');
putenv('DNR_TRUSTED_PROXY_IPS=10.0.0.1'); putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS=10.1.0.0/24');
expectShortLink(shortLinkCountry(array_replace($server, ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'203.0.113.10, 10.1.0.2'])) === 'US', 'Trusted Cloudflare country');
expectShortLink(shortLinkCountry(array_replace($server, ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'10.1.0.2, 203.0.113.20'])) === 'ZZ', 'Untrusted intervening proxy cannot inject country');
$url = shortLinkUrl('0123456789abcdef');
$png = shortLinkQr($url, 'png'); $svg = shortLinkQr($url, 'svg');
expectShortLink(str_starts_with($png, "\x89PNG\r\n\x1a\n") && str_contains($svg, '<svg'), 'Both QR download formats');
$emptyStats = ['day'=>[], 'referrer'=>[], 'browser'=>[], 'os'=>[], 'country'=>[], 'total'=>0];
$report = shortLinkReportData($emptyStats, '2024-02-28 00:00:00', '2024-03-02 00:00:00');
expectShortLink(array_column($report['timeline'], 'label') === ['2024-02-28', '2024-02-29', '2024-03-01'], 'Zero-filled days include leap day and exclude the end boundary');
expectShortLink(array_column($report['timeline'], 'total') === [0,0,0], 'Empty reports do not invent visits');
$report = shortLinkReportData(array_replace($emptyStats, ['day'=>[['label'=>'2026-01-20','total'=>'2'],['label'=>'2026-05-01','total'=>'3']], 'total'=>5]), '2026-01-15 00:00:00', '2026-05-02 00:00:00');
expectShortLink($report['period'] === 'month' && array_column($report['timeline'], 'total') === [2,0,0,0,3], 'Long ranges group by month and preserve partial months');
$rows = array_map(static fn(int $i): array => ['label'=>(string)$i,'total'=>'10'], range(1,20));
$report = shortLinkReportData(array_replace($emptyStats, ['referrer'=>$rows,'browser'=>$rows,'os'=>$rows,'country'=>[['label'=>'ZZ','total'=>'13'],['label'=>'US','total'=>'192']],'total'=>205]), '2026-09-07 00:00:00', '2026-09-08 00:00:00');
foreach (['referrer','browser','os','country'] as $dimension) expectShortLink(array_sum(array_column($report[$dimension], 'total')) === 205, 'Category buckets preserve all traffic, including rows beyond the SQL limit');
expectShortLink(end($report['referrer']) === ['label'=>'Other referrers','total'=>145], 'Donut remainder includes every omitted source');
expectShortLink($report['country'][0] === ['label'=>'ZZ','total'=>13], 'Unknown locations stay separate from mapped countries');
echo "Short-link helper tests passed.\n";
