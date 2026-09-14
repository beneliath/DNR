<?php

declare(strict_types=1);

function networkPerformanceAddressFamily(?string $address): ?string
{
    if ($address === null || filter_var(
        $address,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false) {
        return null;
    }
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'IPv4';
    }
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : null;
}

function networkPerformanceMetric(mixed $value, float $maximum = 120000.0): ?float
{
    if (!is_scalar($value) || !is_numeric((string) $value)) {
        return null;
    }
    $metric = (float) $value;
    if (!is_finite($metric) || $metric < 0 || $metric > $maximum) {
        return null;
    }
    return round($metric, 1);
}

/** @return array<string, int|float|string>|null */
function normalizeNetworkPerformanceSample(array $input): ?array
{
    $rawPage = is_scalar($input['page_path'] ?? null) ? (string) $input['page_path'] : '';
    $pagePath = basename((string) (parse_url($rawPage, PHP_URL_PATH) ?? ''));
    if (preg_match('/\A[A-Za-z0-9_-]+\.php\z/', $pagePath) !== 1) {
        return null;
    }

    $metrics = [];
    foreach (['ttfb_ms', 'dom_content_loaded_ms', 'load_ms', 'image_total_ms', 'image_max_ms',
        'contact_image_total_ms', 'contact_image_max_ms'] as $field) {
        $maximum = str_ends_with($field, '_total_ms') ? 1000000.0 : 120000.0;
        $metrics[$field] = networkPerformanceMetric($input[$field] ?? null, $maximum);
        if ($metrics[$field] === null) {
            return null;
        }
    }

    $counts = [];
    foreach (['image_count', 'contact_image_count'] as $field) {
        $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2000],
        ]);
        if ($value === false) {
            return null;
        }
        $counts[$field] = (int) $value;
    }
    if ($counts['contact_image_count'] > $counts['image_count']) {
        return null;
    }

    return array_merge(['page_path' => $pagePath], $metrics, $counts);
}

function networkPerformanceCloudflareColo(array $server): ?string
{
    $ray = trim((string) ($server['HTTP_CF_RAY'] ?? ''));
    return preg_match('/-([A-Z]{3})$/', $ray, $matches) === 1 ? $matches[1] : null;
}

/** @param array<string, int|float|string> $sample */
function storeNetworkPerformanceSample(
    mysqli $conn,
    array $sample,
    string $addressFamily,
    ?string $cloudflareColo
): void {
    $statement = $conn->prepare(
        'INSERT INTO network_performance_samples '
        . '(address_family, page_path, cloudflare_colo, ttfb_ms, dom_content_loaded_ms, load_ms, '
        . 'image_count, image_total_ms, image_max_ms, contact_image_count, contact_image_total_ms, contact_image_max_ms) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param(
        'sssdddiddidd',
        $addressFamily,
        $sample['page_path'],
        $cloudflareColo,
        $sample['ttfb_ms'],
        $sample['dom_content_loaded_ms'],
        $sample['load_ms'],
        $sample['image_count'],
        $sample['image_total_ms'],
        $sample['image_max_ms'],
        $sample['contact_image_count'],
        $sample['contact_image_total_ms'],
        $sample['contact_image_max_ms']
    );
    $statement->execute();
    $statement->close();

    $conn->query(
        'DELETE FROM network_performance_samples '
        . 'WHERE recorded_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY'
    );
}

/** Clear recorded traffic measurements and audit the reset in one transaction. */
function resetNetworkPerformanceStatistics(mysqli $conn, int $actorId): void
{
    if ($actorId < 1) {
        throw new InvalidArgumentException('Select a valid administrator.');
    }
    $conn->begin_transaction();
    try {
        $conn->query('DELETE FROM network_performance_samples');
        $removed = $conn->affected_rows;
        if (!recordAuditEvent($conn, [
            'event_category' => 'database_change',
            'event_type' => 'network_statistics_reset',
            'actor_user_id' => $actorId,
            'entity_type' => 'network_performance_samples',
            'details' => 'Cleared IPv4 and IPv6 traffic statistics; removed ' . $removed . ' samples.',
        ])) {
            throw new RuntimeException('Unable to audit the network statistics reset.');
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @param list<float|int|string> $values */
function networkPerformancePercentile(array $values, float $percentile): ?float
{
    $numbers = array_values(array_map('floatval', $values));
    if ($numbers === []) {
        return null;
    }
    sort($numbers, SORT_NUMERIC);
    $position = (count($numbers) - 1) * max(0.0, min(1.0, $percentile));
    $lower = (int) floor($position);
    $upper = (int) ceil($position);
    $fraction = $position - $lower;
    return round($numbers[$lower] + (($numbers[$upper] - $numbers[$lower]) * $fraction), 1);
}

/** @param list<array<string, mixed>> $rows @return array<string, mixed> */
function summarizeNetworkPerformanceRows(array $rows): array
{
    $families = [];
    foreach (['IPv4', 'IPv6'] as $family) {
        $familyRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['address_family'] ?? '') === $family
        ));
        $metric = static fn(string $field): array => array_column($familyRows, $field);
        $imageRows = array_values(array_filter(
            $familyRows,
            static fn(array $row): bool => (int) ($row['image_count'] ?? 0) > 0
        ));
        $contactRows = array_values(array_filter(
            $familyRows,
            static fn(array $row): bool => (int) ($row['contact_image_count'] ?? 0) > 0
        ));
        $colos = array_count_values(array_filter(array_column($familyRows, 'cloudflare_colo')));
        arsort($colos);
        $families[$family] = [
            'sample_count' => count($familyRows),
            'median_load_ms' => networkPerformancePercentile($metric('load_ms'), 0.5),
            'p75_load_ms' => networkPerformancePercentile($metric('load_ms'), 0.75),
            'median_ttfb_ms' => networkPerformancePercentile($metric('ttfb_ms'), 0.5),
            'median_image_max_ms' => networkPerformancePercentile(array_column($imageRows, 'image_max_ms'), 0.5),
            'median_contact_image_max_ms' => networkPerformancePercentile(
                array_column($contactRows, 'contact_image_max_ms'),
                0.5
            ),
            'contact_sample_count' => count($contactRows),
            'top_colo' => $colos === [] ? null : array_key_first($colos),
        ];
    }

    $pageRows = [];
    foreach ($rows as $row) {
        $page = (string) ($row['page_path'] ?? 'unknown');
        $family = (string) ($row['address_family'] ?? '');
        if (!isset($pageRows[$page][$family])) {
            $pageRows[$page][$family] = [];
        }
        $pageRows[$page][$family][] = $row['load_ms'];
    }
    $pages = [];
    foreach ($pageRows as $page => $byFamily) {
        $pages[] = [
            'page_path' => $page,
            'ipv4_samples' => count($byFamily['IPv4'] ?? []),
            'ipv4_p75_ms' => networkPerformancePercentile($byFamily['IPv4'] ?? [], 0.75),
            'ipv6_samples' => count($byFamily['IPv6'] ?? []),
            'ipv6_p75_ms' => networkPerformancePercentile($byFamily['IPv6'] ?? [], 0.75),
        ];
    }
    usort($pages, static function (array $left, array $right): int {
        $leftSlowest = max((float) ($left['ipv4_p75_ms'] ?? 0), (float) ($left['ipv6_p75_ms'] ?? 0));
        $rightSlowest = max((float) ($right['ipv4_p75_ms'] ?? 0), (float) ($right['ipv6_p75_ms'] ?? 0));
        return $rightSlowest <=> $leftSlowest;
    });

    return [
        'window_hours' => 24,
        'sample_count' => count($rows),
        'generated_at' => gmdate(DATE_ATOM),
        'families' => $families,
        'pages' => array_slice($pages, 0, 8),
    ];
}

/** @return array<string, mixed> */
function fetchNetworkPerformanceSummary(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT address_family, page_path, cloudflare_colo, ttfb_ms, load_ms, image_count, image_max_ms, '
        . 'contact_image_count, contact_image_max_ms '
        . 'FROM network_performance_samples '
        . 'WHERE recorded_at >= UTC_TIMESTAMP(6) - INTERVAL 24 HOUR '
        . 'ORDER BY recorded_at DESC LIMIT 5000'
    );
    return summarizeNetworkPerformanceRows($result->fetch_all(MYSQLI_ASSOC));
}
