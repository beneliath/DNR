<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';

$base = 'https://public.example/a/community';
$token = str_repeat('a', 64);
$key = str_repeat('b', 64);
$fund = 'https://giving.example/give?fund_id=israel&ref=letter';
$general = 'https://giving.example/give';
$series = 'https://video.example/series_(part_(2))';
$literal = 'https://news.example/what?!';
$poll = str_repeat('c', 64);
$design = audienceDesign(['schema'=>1, 'rows'=>[['columns'=>[[[
    'type'=>'text',
    'html'=>'<p><a href="'.htmlspecialchars($fund).'">Project</a>. <a href="'.$general.'">Give</a>!</p>'
        .'<p><a href="'.$literal.'">Read</a>, then <a href="'.$series.'">watch</a>. <a href="*|POLL:'.$poll.':1|*">Vote</a>.</p>',
]]]]]]);
$snapshot = [
    'base'=>$base, 'tracking_key'=>$key, 'type'=>'regular', 'collection_links'=>[$poll=>'survey'],
    'identity'=>['LIST:COMPANY'=>'Community', 'LIST:ADDRESS'=>'123 Fixture Street'],
    'draft'=>['subject'=>'Letter', 'from_name'=>'Community', 'from_email'=>'sender@example.test',
        'tracking'=>['clicks'=>true, 'plain_clicks'=>true], 'design'=>$design],
];
$render = static fn(array $s, string $format = 'html'): array => audienceDispatchMessage(
    $s, ['EMAIL'=>'reader@example.test', '__email_format'=>$format], $token, '<fixture@example.test>'
);
// Reverse the actual recipient redirects. Comparing the restored copy catches both
// a changed destination and punctuation moved out of (or into) the signed URL.
$restore = static function (string $body, array $expected) use ($base, $token, $key): string {
    $targets = [];
    $pattern = '~'.preg_quote($base, '~').'/audience_track\.php\?token=([a-f0-9]{64})&url=([a-zA-Z0-9_-]+)&sig=([a-f0-9]{64})~';
    $result = preg_replace_callback($pattern, static function (array $match) use (&$targets, $token, $key): string {
        if ($match[1] !== $token || !hash_equals(hash_hmac('sha256', $match[2], $key), $match[3])) {
            throw new RuntimeException('Invalid recipient or click signature.');
        }
        $url = base64_decode(strtr($match[2], '-_', '+/'), true);
        $targets[] = $url;
        return $url;
    }, $body);
    if ($targets !== $expected) {
        throw new RuntimeException('Wrong signed destinations: '.json_encode($targets, JSON_UNESCAPED_SLASHES));
    }
    return $result;
};
$destinations = [$fund, $general, $literal, $series];
foreach (['html', 'text'] as $format) {
    $tracked = $render($snapshot, $format);
    $off = $snapshot;
    $off['draft']['tracking']['plain_clicks'] = false;
    if ($restore($tracked['text'], $destinations) !== $render($off, $format)['text']) {
        throw new RuntimeException('Tracking changed the generated plain-text content.');
    }
    if (!str_contains($tracked['text'], 'Vote ('.$base.'/audience_collect_public.php?key='.$poll.'&recipient='.$token.'&choice=1).')) {
        throw new RuntimeException('Recipient-specific poll URL was not preserved.');
    }
    if ($format === 'html') {
        $restore(html_entity_decode($tracked['html'], ENT_QUOTES, 'UTF-8'), $destinations);
    }
}
$plainOnly = $snapshot;
$plainOnly['draft']['tracking']['clicks'] = false;
$message = $render($plainOnly);
$restore(html_entity_decode($message['html'], ENT_QUOTES, 'UTF-8'), []);
$restore($message['text'], $destinations);
$restore($render($snapshot + ['test'=>true])['text'], []);

$copy = "Project ($fund). Give at $general, watch $series! Read <$literal>.\n"
    ."Legacy “http://legacy.example/news”, or https://news.example/path%29?q=a%2Eb#more.";
$rawTargets = [$fund, $general, $series, $literal, 'http://legacy.example/news', 'https://news.example/path%29?q=a%2Eb#more'];
foreach (['regular', 'plain-text'] as $type) {
    $custom = $snapshot;
    $custom['type'] = $type;
    $custom['draft']['tracking']['clicks'] = false;
    $custom['draft']['design']['plainText'] = $copy;
    $custom['draft']['text'] = $copy;
    $restored = $restore($render($custom)['text'], $rawTargets);
    if (!str_starts_with($restored, $copy."\n") || !str_contains($restored, 'Unsubscribe: '.$base.'/audience_subscription.php?token='.$token)) {
        throw new RuntimeException('Tracking changed authored text or its unsubscribe link.');
    }
}
echo "Plain-text tracking preserves signed destinations and surrounding copy.\n";
