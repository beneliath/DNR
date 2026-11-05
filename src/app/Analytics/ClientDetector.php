<?php

declare(strict_types=1);

namespace Dnr\Analytics;

use DeviceDetector\DeviceDetector;

/** Shared detection rules for speaker visits and MessageMonkey observations. */
final class ClientDetector
{
    public static function parse(string $userAgent): DeviceDetector
    {
        $detector = new DeviceDetector(substr($userAgent, 0, 2048));
        $detector->setYamlParser(new CompiledDeviceDetectorYaml());
        $detector->discardBotInformation();
        $detector->parse();
        return $detector;
    }
}
