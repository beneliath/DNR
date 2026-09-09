<?php

declare(strict_types=1);

/** Build-time only: compile trusted dependency data, never request/user content. */
function compileDeviceDetectorYamlRules(string $source, string $destination): int
{
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
        throw new RuntimeException('Unable to create compiled detector directory.');
    }
    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'yml') continue;
        $yaml = file_get_contents($file->getPathname());
        if ($yaml === false) throw new RuntimeException('Unable to read detector rules.');
        $data = \Symfony\Component\Yaml\Yaml::parse($yaml);
        $path = $destination . '/' . hash('sha256', $yaml) . '.php';
        if (file_put_contents($path . '.tmp', "<?php\nreturn " . var_export($data, true) . ";\n") === false
            || !rename($path . '.tmp', $path)) {
            throw new RuntimeException('Unable to write compiled detector rules.');
        }
        chmod($path, 0444);
        $count++;
    }
    if ($count === 0) throw new RuntimeException('No detector rules were compiled.');
    return $count;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $autoload = is_file(dirname(__DIR__) . '/vendor/autoload.php')
        ? dirname(__DIR__) . '/vendor/autoload.php' : '/opt/dnr/vendor/autoload.php';
    require $autoload;
    $reflection = new ReflectionClass(\DeviceDetector\DeviceDetector::class);
    $source = dirname((string) $reflection->getFileName()) . '/regexes';
    $destination = $argv[1] ?? '/opt/dnr/device-detector-yaml';
    echo 'Compiled ' . compileDeviceDetectorYamlRules($source, $destination) . " detector rule files.\n";
}
