<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/notes_cache_helpers.php';
function expectNotesCache(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$code = str_repeat('a', 16);
$server = ['REQUEST_URI' => '/surls/' . $code . '/speaker-notes.pdf', 'REQUEST_METHOD' => 'GET'];
putenv('DNR_NOTES_EDGE_CACHE_TTL');
expectNotesCache(notesEdgeCacheTtl($code, $server) === 0, 'Caching is opt-in.');
putenv('DNR_NOTES_EDGE_CACHE_TTL=300');
expectNotesCache(notesEdgeCacheTtl($code, $server) === 300, 'Canonical public PDFs may be cached.');
foreach (['?a=1', '?code=ffffffffffffffff', '/extra'] as $suffix) {
    expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_URI' => $server['REQUEST_URI'] . $suffix])) === 0, 'Variants cannot escape single-URL purge.');
}
foreach (['/presentation_asset.php?id=1&type=notes', '/short_link.php?code=' . $code . '&download=1', '/surls/' . $code] as $uri) {
    expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_URI' => $uri])) === 0, 'Private and redirect endpoints never enter this cache.');
}
expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_METHOD' => 'POST'])) === 0, 'POST cannot be cached.');
expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_METHOD' => 'HEAD'])) === 300, 'HEAD has GET representation headers.');
putenv('DNR_NOTES_EDGE_CACHE_TTL=86400');
expectNotesCache(notesEdgeCacheTtl($code, $server) === 300, 'Revocation fallback is capped at five minutes.');
putenv('DNR_NOTES_EDGE_CACHE_TTL=-1');
expectNotesCache(notesEdgeCacheTtl($code, $server) === 0, 'Negative TTL disables caching.');
expectNotesCache(notesCachePurgeUrl('https://moed.example.com', $code) === 'https://moed.example.com' . $server['REQUEST_URI'], 'Purge uses canonical host and bearer path.');
foreach (['http://moed.example.com', 'https://moed.example.com/extra', 'https://user:pass@moed.example.com', "https://moed.example.com\r\nInjected: yes"] as $origin) {
    try { notesCachePurgeUrl($origin, $code); throw new RuntimeException('Unsafe origin accepted.'); }
    catch (InvalidArgumentException $expected) {}
}
putenv('DNR_NOTES_EDGE_CACHE_TTL');
echo "Notes cache policy tests passed.\n";
$base = 'https://moed.example.com/a/shalom-in-messiah';
putenv('DNR_ACCOUNT_MODE=primary');
expectNotesCache(notesCachePurgeUrls($base, $code) === [$base.$server['REQUEST_URI'], 'https://moed.example.com'.$server['REQUEST_URI']], 'Primary invalidates both canonical and legacy URLs.');
putenv('DNR_ACCOUNT_MODE=member');
expectNotesCache(notesCachePurgeUrls('https://moed.example.com/a/test-account', $code) === ['https://moed.example.com/a/test-account'.$server['REQUEST_URI']], 'Member cannot purge the primary legacy URL.');
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.com/a/test-account');
putenv('DNR_NOTES_EDGE_CACHE_TTL=300');
expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_URI'=>'/a/test-account'.$server['REQUEST_URI']])) === 300, 'Canonical Account PDF can be cached.');
expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_URI'=>'/a/other-account'.$server['REQUEST_URI']])) === 0, 'Other Account path cannot enter this cache.');
expectNotesCache(notesEdgeCacheTtl($code, array_replace($server, ['REQUEST_URI'=>'/a/test-account'.$server['REQUEST_URI'].'?download=1'])) === 0, 'Account query variants are not cached.');
foreach (['https://moed.example.com/a/test-account/extra', 'https://moed.example.com/a/../test-account', 'https://moed.example.com/a/test-account?x=1'] as $url) {
    try { notesCachePurgeUrl($url, $code); throw new RuntimeException('Unsafe Account URL accepted'); }
    catch (InvalidArgumentException $expected) {}
}
putenv('DNR_ACCOUNT_MODE'); putenv('DNR_PUBLIC_BASE_URL'); putenv('DNR_NOTES_EDGE_CACHE_TTL');
echo "Account and legacy cache URL tests passed.\n";
