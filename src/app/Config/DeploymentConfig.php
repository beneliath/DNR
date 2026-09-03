<?php

declare(strict_types=1);

namespace Dnr\Config;

use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DeploymentConfig
{
    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'brand' => [
            'display_name' => 'DNR',
            'native_name' => '',
            'mail_name' => 'DNR',
            'totp_issuer' => 'DNR',
            'calendar_name' => 'DNR Events',
            'logo_light' => 'assets/dnr-logo.svg',
            'logo_dark' => 'assets/dnr-logo-dark.svg',
            'logo_email' => 'assets/dnr-logo-email.png',
        ],
        'defaults' => [
            'speaker' => 'Unknown Speaker',
            'country' => 'US',
            'phone_country_code' => '+1',
            'timezone' => 'UTC',
        ],
        'inbound_email' => [
            'emitted_marker_prefix' => 'DNR',
            'accepted_marker_prefixes' => ['DNR'],
        ],
        'map' => [
            'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution_text' => 'OpenStreetMap contributors',
            'attribution_url' => 'https://www.openstreetmap.org/copyright',
            'maximum_zoom' => 19,
        ],
        'workflow' => [
            'dashboard_upcoming_days' => 30,
            'task_upcoming_days' => 7,
            'calendar_past_days' => 365,
            'calendar_future_days' => 1095,
            'map_past_days' => 90,
            'map_future_days' => 730,
            'map_max_events' => 500,
            'pdf_max_chron_entries' => 500,
        ],
        'standard_event_tasks' => [],
    ];

    /** @var array<string, array{names: list<string>, type: string}> */
    private const ENVIRONMENT_OVERRIDES = [
        'brand.display_name' => ['names' => ['DNR_BRAND_DISPLAY_NAME'], 'type' => 'string'],
        'brand.mail_name' => ['names' => ['DNR_MAIL_FROM_NAME'], 'type' => 'string'],
        'brand.totp_issuer' => ['names' => ['DNR_TOTP_ISSUER'], 'type' => 'string'],
        'brand.calendar_name' => ['names' => ['DNR_CALENDAR_NAME'], 'type' => 'string'],
        'defaults.speaker' => ['names' => ['DNR_DEFAULT_SPEAKER', 'DEFAULT_SPEAKER'], 'type' => 'string'],
        'defaults.country' => ['names' => ['DNR_DEFAULT_COUNTRY'], 'type' => 'string'],
        'defaults.phone_country_code' => ['names' => ['DNR_DEFAULT_PHONE_COUNTRY_CODE'], 'type' => 'string'],
        'defaults.timezone' => ['names' => ['DNR_TIMEZONE'], 'type' => 'string'],
        'inbound_email.emitted_marker_prefix' => ['names' => ['DNR_INBOUND_MARKER_PREFIX'], 'type' => 'string'],
        'inbound_email.accepted_marker_prefixes' => ['names' => ['DNR_INBOUND_ACCEPTED_MARKER_PREFIXES'], 'type' => 'csv'],
        'map.tile_url' => ['names' => ['DNR_MAP_TILE_URL'], 'type' => 'string'],
        'map.attribution_text' => ['names' => ['DNR_MAP_ATTRIBUTION_TEXT'], 'type' => 'string'],
        'map.attribution_url' => ['names' => ['DNR_MAP_ATTRIBUTION_URL'], 'type' => 'string'],
        'map.maximum_zoom' => ['names' => ['DNR_MAP_MAXIMUM_ZOOM'], 'type' => 'int'],
        'workflow.dashboard_upcoming_days' => ['names' => ['DNR_DASHBOARD_UPCOMING_DAYS'], 'type' => 'int'],
        'workflow.task_upcoming_days' => ['names' => ['DNR_TASK_UPCOMING_DAYS'], 'type' => 'int'],
        'workflow.calendar_past_days' => ['names' => ['DNR_CALENDAR_PAST_DAYS'], 'type' => 'int'],
        'workflow.calendar_future_days' => ['names' => ['DNR_CALENDAR_FUTURE_DAYS'], 'type' => 'int'],
        'workflow.map_past_days' => ['names' => ['DNR_MAP_PAST_DAYS'], 'type' => 'int'],
        'workflow.map_future_days' => ['names' => ['DNR_MAP_FUTURE_DAYS'], 'type' => 'int'],
        'workflow.map_max_events' => ['names' => ['DNR_MAP_MAX_EVENTS'], 'type' => 'int'],
        'workflow.pdf_max_chron_entries' => ['names' => ['DNR_PDF_MAX_CHRON_ENTRIES'], 'type' => 'int'],
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string, string>|null $environment */
    public static function load(?string $path = null, ?array $environment = null): self
    {
        if ($path === null) {
            $configuredPath = self::environmentRaw('DNR_CONFIG_FILE', $environment);
            $path = $configuredPath !== '' ? $configuredPath : self::defaultProfilePath();
        }

        $configured = [];
        if ($path !== '') {
            if (!is_readable($path)) {
                throw new RuntimeException("Deployment configuration is not readable: {$path}");
            }
            try {
                $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (ParseException $exception) {
                throw new RuntimeException(
                    "Deployment configuration is invalid: {$exception->getMessage()}",
                    0,
                    $exception
                );
            }
            if ($parsed !== null && !is_array($parsed)) {
                throw new InvalidArgumentException('Deployment configuration must contain a YAML mapping.');
            }
            $configured = is_array($parsed) ? $parsed : [];
            self::rejectUnknownKeys($configured, self::DEFAULTS);
        }

        $resolved = self::applyEnvironmentOverrides(
            self::merge(self::DEFAULTS, $configured),
            $environment
        );
        self::validateData($resolved);
        return new self($resolved);
    }

    /** @return mixed */
    public function get(string $path)
    {
        return self::pathValue($this->data, $path);
    }

    public function string(string $path): string
    {
        $value = $this->get($path);
        if (!is_string($value)) {
            throw new InvalidArgumentException("Deployment setting {$path} must be a string.");
        }
        return $value;
    }

    public function integer(string $path): int
    {
        $value = $this->get($path);
        if (!is_int($value)) {
            throw new InvalidArgumentException("Deployment setting {$path} must be an integer.");
        }
        return $value;
    }

    /** @return list<mixed> */
    public function list(string $path): array
    {
        $value = $this->get($path);
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("Deployment setting {$path} must be a list.");
        }
        return $value;
    }

    public function tileCspSource(): string
    {
        $parts = parse_url($this->string('map.tile_url'));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('map.tile_url must be an absolute HTTPS URL.');
        }
        $host = $parts['host'];
        if (str_starts_with($host, '{s}.')) {
            $host = '*.' . substr($host, 4);
        }
        return $parts['scheme'] . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data
     *  @param array<string, string>|null $environment
     *  @return array<string, mixed>
     */
    private static function applyEnvironmentOverrides(array $data, ?array $environment): array
    {
        foreach (self::ENVIRONMENT_OVERRIDES as $path => $override) {
            foreach ($override['names'] as $name) {
                $raw = self::environmentRaw($name, $environment);
                if ($raw === '') {
                    continue;
                }
                $value = match ($override['type']) {
                    'int' => preg_match('/\A-?[0-9]+\z/', $raw) === 1
                        ? (int) $raw
                        : throw new InvalidArgumentException("{$name} must be an integer."),
                    'csv' => array_values(array_filter(array_map(
                        static fn(string $item): string => trim($item),
                        explode(',', $raw)
                    ), static fn(string $item): bool => $item !== '')),
                    default => $raw,
                };
                self::setPathValue($data, $path, $value);
                break;
            }
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function validateData(array $data): void
    {
        foreach ([
            'brand.display_name', 'brand.mail_name',
            'brand.totp_issuer', 'brand.calendar_name', 'defaults.speaker',
            'defaults.country', 'defaults.phone_country_code',
            'defaults.timezone', 'inbound_email.emitted_marker_prefix',
            'map.tile_url', 'map.attribution_text', 'map.attribution_url',
        ] as $path) {
            $value = self::pathValue($data, $path);
            if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > 255) {
                throw new InvalidArgumentException("Deployment setting {$path} must be a non-empty string of at most 255 characters.");
            }
        }
        $nativeName = self::pathValue($data, 'brand.native_name');
        if (!is_string($nativeName) || mb_strlen($nativeName, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Deployment setting brand.native_name must be a string of at most 255 characters.');
        }
        foreach (['brand.logo_light', 'brand.logo_dark', 'brand.logo_email'] as $path) {
            $asset = self::pathValue($data, $path);
            if (!is_string($asset)
                || preg_match('#\Aassets/[A-Za-z0-9][A-Za-z0-9._/-]*\z#D', $asset) !== 1
                || str_contains($asset, '..')
            ) {
                throw new InvalidArgumentException("Deployment setting {$path} must be a safe assets-relative path.");
            }
        }
        $timezone = (string) self::pathValue($data, 'defaults.timezone');
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("Unknown deployment timezone: {$timezone}", 0, $exception);
        }
        $country = (string) self::pathValue($data, 'defaults.country');
        if (preg_match('/\A[A-Z]{2}\z/D', $country) !== 1) {
            throw new InvalidArgumentException('defaults.country must be an ISO 3166-1 alpha-2 code.');
        }
        $phoneCode = (string) self::pathValue($data, 'defaults.phone_country_code');
        if (preg_match('/\A\+[1-9][0-9]{0,3}\z/D', $phoneCode) !== 1) {
            throw new InvalidArgumentException('defaults.phone_country_code must be an international calling code such as +1.');
        }
        self::validateUrl((string) self::pathValue($data, 'map.tile_url'), 'map.tile_url', true);
        self::validateUrl((string) self::pathValue($data, 'map.attribution_url'), 'map.attribution_url', false);

        $prefixes = self::pathValue($data, 'inbound_email.accepted_marker_prefixes');
        if (!is_array($prefixes) || !array_is_list($prefixes) || $prefixes === []) {
            throw new InvalidArgumentException('inbound_email.accepted_marker_prefixes must be a non-empty list.');
        }
        $emittedPrefix = (string) self::pathValue($data, 'inbound_email.emitted_marker_prefix');
        foreach (array_merge([$emittedPrefix], $prefixes) as $prefix) {
            if (!is_string($prefix) || preg_match('/\A[A-Z][A-Z0-9_-]{1,19}\z/D', $prefix) !== 1) {
                throw new InvalidArgumentException('Inbound marker prefixes must be 2-20 uppercase letters, digits, underscores, or hyphens.');
            }
        }
        if (!in_array($emittedPrefix, $prefixes, true)) {
            throw new InvalidArgumentException('The emitted inbound marker prefix must also be accepted.');
        }

        $integerRanges = [
            'map.maximum_zoom' => [1, 24],
            'workflow.dashboard_upcoming_days' => [1, 3650],
            'workflow.task_upcoming_days' => [1, 365],
            'workflow.calendar_past_days' => [0, 3650],
            'workflow.calendar_future_days' => [30, 3650],
            'workflow.map_past_days' => [0, 3650],
            'workflow.map_future_days' => [1, 3650],
            'workflow.map_max_events' => [50, 2000],
            'workflow.pdf_max_chron_entries' => [50, 1000],
        ];
        foreach ($integerRanges as $path => [$minimum, $maximum]) {
            $value = self::pathValue($data, $path);
            if (!is_int($value) || $value < $minimum || $value > $maximum) {
                throw new InvalidArgumentException("Deployment setting {$path} must be an integer from {$minimum} through {$maximum}.");
            }
        }

        $tasks = self::pathValue($data, 'standard_event_tasks');
        if (!is_array($tasks) || !array_is_list($tasks)) {
            throw new InvalidArgumentException('standard_event_tasks must be a list.');
        }
        $taskKeys = [];
        foreach ($tasks as $index => $task) {
            if (!is_array($task)) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}] must be a mapping.");
            }
            $allowed = ['key', 'title', 'details', 'priority', 'due_anchor', 'due_offset_days', 'sort_order', 'required'];
            $unknown = array_diff(array_keys($task), $allowed);
            if ($unknown !== []) {
                throw new InvalidArgumentException("Unknown standard_event_tasks[{$index}] setting: " . implode(', ', $unknown));
            }
            $key = $task['key'] ?? null;
            $title = $task['title'] ?? null;
            if (!is_string($key) || preg_match('/\A(?:standard|deployment)\.[a-z0-9][a-z0-9_.-]{1,98}\z/D', $key) !== 1) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].key is invalid.");
            }
            if (isset($taskKeys[$key])) {
                throw new InvalidArgumentException("Duplicate standard task key: {$key}");
            }
            $taskKeys[$key] = true;
            if (!is_string($title) || trim($title) === '' || mb_strlen($title, 'UTF-8') > 255) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].title is invalid.");
            }
            if (isset($task['details']) && (!is_string($task['details']) || mb_strlen($task['details'], 'UTF-8') > 20000)) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].details is invalid.");
            }
            if (!in_array($task['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], true)) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].priority is invalid.");
            }
            if (!in_array($task['due_anchor'] ?? null, ['event_start', 'event_end'], true)) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].due_anchor is invalid.");
            }
            $offset = $task['due_offset_days'] ?? null;
            $sortOrder = $task['sort_order'] ?? null;
            if (!is_int($offset) || $offset < -3650 || $offset > 3650) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].due_offset_days is invalid.");
            }
            if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 65535) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].sort_order is invalid.");
            }
            if (isset($task['required']) && !is_bool($task['required'])) {
                throw new InvalidArgumentException("standard_event_tasks[{$index}].required must be boolean.");
            }
        }
    }

    private static function validateUrl(string $url, string $path, bool $allowTemplate): void
    {
        $candidate = $allowTemplate
            ? str_replace(['{z}', '{x}', '{y}', '{s}', '{r}'], ['1', '1', '1', 'a', ''], $url)
            : $url;
        $parts = parse_url($candidate);
        if (filter_var($candidate, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException("Deployment setting {$path} must be a public HTTPS URL without credentials.");
        }
    }

    private static function defaultProfilePath(): string
    {
        $repositoryProfile = dirname(__DIR__, 3) . '/deployments/moed/application.yaml';
        if (is_readable($repositoryProfile)) {
            return $repositoryProfile;
        }
        $imageDefault = '/opt/dnr/config/application.yaml';
        return is_readable($imageDefault) ? $imageDefault : '';
    }

    /** @param array<string, string>|null $environment */
    private static function environmentRaw(string $name, ?array $environment): string
    {
        $value = $environment === null ? getenv($name) : ($environment[$name] ?? false);
        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $configured
     *  @param array<string, mixed> $defaults
     */
    private static function rejectUnknownKeys(array $configured, array $defaults, string $prefix = ''): void
    {
        foreach ($configured as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException('Unknown deployment setting: ' . $prefix . $key);
            }
            if ($key === 'standard_event_tasks') {
                continue;
            }
            if (is_array($value) && is_array($defaults[$key]) && !array_is_list($value)) {
                self::rejectUnknownKeys($value, $defaults[$key], $prefix . $key . '.');
            }
        }
    }

    /** @param array<string, mixed> $base
     *  @param array<string, mixed> $override
     *  @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
                && !array_is_list($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** @param array<string, mixed> $data
     *  @return mixed
     */
    private static function pathValue(array $data, string $path)
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                throw new InvalidArgumentException("Unknown deployment setting: {$path}");
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** @param array<string, mixed> $data
     *  @param mixed $value
     */
    private static function setPathValue(array &$data, string $path, $value): void
    {
        $parts = explode('.', $path);
        $cursor = &$data;
        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $cursor[$part] = $value;
                return;
            }
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
    }
}
