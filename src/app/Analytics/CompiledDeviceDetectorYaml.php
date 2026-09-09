<?php

declare(strict_types=1);

namespace Dnr\Analytics;

/** Immutable build artifacts let OPcache share parsed detection rules across requests. */
final class CompiledDeviceDetectorYaml implements \DeviceDetector\Yaml\ParserInterface
{
    public function __construct(private readonly string $directory = '/opt/dnr/device-detector-yaml') {}

    public function parseFile(string $file): mixed
    {
        // Content addressing also keeps development bind mounts safe when the
        // installed detector rules differ from those in the built image.
        $digest = hash_file('sha256', $file);
        $compiled = $this->directory . '/' . $digest . '.php';
        if ($digest !== false && is_file($compiled)) return require $compiled;
        return \Symfony\Component\Yaml\Yaml::parseFile($file);
    }
}
