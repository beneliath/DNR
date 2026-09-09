<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Notes cache HTTP integration skipped (disposable maintenance server required).\n"; exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://web', '/');
if (!in_array(parse_url($base,PHP_URL_HOST), ['web','localhost','127.0.0.1'],true)) throw new RuntimeException('Disposable internal server required.');
function expectCacheHttp(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
$request = static function(string $path,array $headers=[])use($base):array {
    $c=curl_init($base.'/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'Mozilla/5.0 Chrome/128.0.0.0 Safari/537.36']);
    $raw=curl_exec($c);expectCacheHttp(is_string($raw),'HTTP request succeeded');
    $length=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);
    return ['status'=>(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$length),'body'=>substr($raw,$length)];
};
$speaker=(int)$conn->query('SELECT id FROM speakers LIMIT 1')->fetch_assoc()['id'];
$conn->query("INSERT INTO organizations(organization_name) VALUES ('Cache HTTP test')");$org=(int)$conn->insert_id;
$conn->query("INSERT INTO engagements(organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
    VALUES ($org,'Cache HTTP test','2026-10-01','2026-10-01','conference','under_review')");$event=(int)$conn->insert_id;
$code=bin2hex(random_bytes(8));
try {
    $conn->query("INSERT INTO presentations(engagement_id,speaker_id,topic_title) VALUES ($event,$speaker,'Cache HTTP test')");$pid=(int)$conn->insert_id;
    $pdf="%PDF-1.4\nCache test\n%%EOF\n";
    $stmt=$conn->prepare('INSERT INTO presentation_notes(presentation_id,speaker_id,pdf,filename,size,sha256) VALUES (?,?,?,\'cache.pdf\',?,UNHEX(SHA2(?,256)))');
    $size=strlen($pdf);$stmt->bind_param('iisis',$pid,$speaker,$pdf,$size,$pdf);$stmt->execute();
    $conn->query("INSERT INTO short_links(code,engagement_id,presentation_id,speaker_id,link_type) VALUES ('$code',$event,$pid,$speaker,'notes')");
    $id=(int)$conn->insert_id;$path='surls/'.$code.'/speaker-notes.pdf';
    $r=$request($path);
    expectCacheHttp($r['body']===$pdf&&str_contains($r['headers'],'Cloudflare-CDN-Cache-Control: no-store'),'Pending invalidation prevents an origin cache fill.');
    $conn->query("DELETE FROM notes_cache_purge_queue WHERE code='$code'");
    $r=$request($path);
    $enabled=getenv('DNR_TEST_NOTES_EDGE_CACHE_ENABLED')==='1';
    expectCacheHttp(str_contains($r['headers'],'Cloudflare-CDN-Cache-Control: '.($enabled?'public, max-age=300, must-revalidate':'no-store')),'Only configured deployments emit CDN cache headers.');
    expectCacheHttp(str_contains($r['headers'],'Cache-Control: private, no-store'),'Browser cache remains separate from CDN policy.');
    $count=static fn():int=>(int)$conn->query("SELECT COALESCE(SUM(visits),0) AS n FROM short_link_stats WHERE link_id=$id")->fetch_assoc()['n'];
    expectCacheHttp($count()===0,'Direct PDF views and cache fills do not count.');
    $r=$request('surls/'.$code);
    expectCacheHttp($r['status']===302&&$count()===1&&str_contains($r['headers'],'Cloudflare-CDN-Cache-Control: no-store'),'A QR visit counts before cached delivery.');
    $request($path);$request($path,['Range: bytes=0-5']);
    expectCacheHttp($count()===1,'Full requests and initial ranges cannot double count.');
    $request('surls/'.$code,['Sec-Purpose: prefetch']);$request('surls/'.$code,['User-Agent: Googlebot']);
    expectCacheHttp($count()===1,'Bots and prefetches do not count on the redirect.');
    $r=$request($path,['Range: bytes=0-5','If-Range: "stale"']);
    expectCacheHttp($r['status']===200&&$r['body']===$pdf,'Stale If-Range gets the full current file.');
    $r=$request($path,['Range: bytes=0-5','If-Range: "notes-'.hash('sha256',$pdf).'"']);
    expectCacheHttp($r['status']===206&&$r['body']===substr($pdf,0,6),'Matching If-Range returns exact requested bytes.');
    foreach ([$path.'?variant=1','short_link.php?code='.$code.'&download=1'] as $variant) {
        expectCacheHttp(str_contains($request($variant)['headers'],'Cloudflare-CDN-Cache-Control: no-store'),'Query variants and PHP aliases cannot enter the cache.');
    }
    $conn->query("UPDATE short_links SET is_enabled=0 WHERE code='$code'");
    $r=$request($path);
    expectCacheHttp($r['status']===410&&str_contains($r['headers'],'Cloudflare-CDN-Cache-Control: no-store'),'Disabled notes return an uncached 410.');
    echo "Notes cache HTTP tests passed: headers, statistics, range validation, and revocation.\n";
} finally {
    $conn->query("DELETE FROM engagements WHERE id=$event");$conn->query("DELETE FROM organizations WHERE id=$org");
    $conn->query("DELETE FROM notes_cache_purge_queue WHERE code='$code'");
}
