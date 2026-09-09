<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/application_runtime.php';
require_once __DIR__ . '/../scripts/compile_device_detector_yaml.php';

$directory = sys_get_temp_dir() . '/dnr-detector-' . bin2hex(random_bytes(8));
$source = dirname((string) (new ReflectionClass(\DeviceDetector\DeviceDetector::class))->getFileName()) . '/regexes';
try {
    compileDeviceDetectorYamlRules($source, $directory);
    $compiled = new \Dnr\Analytics\CompiledDeviceDetectorYaml($directory);
    foreach ([
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 Chrome/128.0.0.0 Safari/537.36',
        'Googlebot/2.1 (+http://www.google.com/bot.html)',
        '',
    ] as $ua) {
        $results = [];
        foreach ([new \DeviceDetector\Yaml\Symfony(), $compiled] as $parser) {
            // Avoid the library's within-request static cache hiding parser differences.
            $cache = new \DeviceDetector\Cache\StaticCache();
            $cache->flushAll();
            $detector = new \DeviceDetector\DeviceDetector($ua);
            $detector->setCache($cache);
            $detector->setYamlParser($parser);
            $detector->discardBotInformation();
            $detector->parse();
            $results[] = [$detector->isBot(), $detector->getClient(), $detector->getOs()];
        }
        if ($results[0] !== $results[1]) throw new RuntimeException('Compiled detection changed classifications.');
    }
    $fixture = $directory . '/changed.yml';
    file_put_contents($fixture, "name: updated\n");
    if ($compiled->parseFile($fixture) !== ['name' => 'updated']) {
        throw new RuntimeException('Changed or uncompiled rules must use the original parser.');
    }
    echo "Compiled detector tests passed: browser/OS parity, bot filtering, and missing-artifact fallback.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
