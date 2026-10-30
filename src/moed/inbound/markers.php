<?php

declare(strict_types=1);

/**
 * @return array{ids: list<int>, invalid: list<string>}
 */
function parseInboundEmailEngagementMarkers(string $text): array
{
    if (accountMailEnabled()) {
        require_once dirname(__DIR__, 2) . '/account_mail_helpers.php';
        $route = localAccountMailRoute(['body_text' => $text]);
        return ['ids' => $route['account_key'] === currentAccountKey()
            ? array_values(array_unique(array_column(array_filter($route['targets'], static fn(array $target) => $target['type'] === 'E'), 'id'))) : [],
            'invalid' => $route['reason'] !== '' && str_contains($text, '[') ? [$route['reason']] : []];
    }
    $matches = [];
    $prefixPattern = inboundEmailMarkerPrefixPattern();
    preg_match_all('/\[(' . $prefixPattern . ')#([^\]\r\n]*)\]/i', $text, $matches, PREG_SET_ORDER);
    $ids = [];
    $invalid = [];
    foreach ($matches as $match) {
        $marker = (string) $match[0];
        $prefix = (string) $match[1];
        $candidate = (string) $match[2];
        if (!preg_match('/^([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{22})$/D', $candidate, $parts)) {
            $invalid[$marker] = true;
            continue;
        }
        $id = (int) $parts[1];
        if ($id < 1 || $id > 2147483647
            || !applicationInboundMarkerIsValid($prefix, $id, (string) $parts[2])
        ) {
            $invalid[$marker] = true;
            continue;
        }
        $ids[$id] = true;
    }
    return [
        'ids' => array_map('intval', array_keys($ids)),
        'invalid' => array_map('strval', array_keys($invalid)),
    ];
}

/**
 * @param array<string, mixed> $message
 * @return array{ids: list<int>, invalid: list<string>}
 */
function inboundEmailMessageEngagementMarkers(array $message): array
{
    return parseInboundEmailEngagementMarkers(
        (string) ($message['subject'] ?? '') . "\n" . (string) ($message['body_text'] ?? '')
    );
}

/** @return array{ids: list<int>, invalid: list<string>} */
function parseInboundEmailInquiryMarkers(string $text): array
{
    if (accountMailEnabled()) {
        require_once dirname(__DIR__, 2) . '/account_mail_helpers.php';
        $route = localAccountMailRoute(['body_text' => $text]);
        return ['ids' => $route['account_key'] === currentAccountKey()
            ? array_values(array_unique(array_column(array_filter($route['targets'], static fn(array $target) => $target['type'] === 'I'), 'id'))) : [],
            'invalid' => $route['reason'] !== '' && str_contains($text, '[') ? [$route['reason']] : []];
    }
    $matches = [];
    $prefixPattern = inboundEmailMarkerPrefixPattern();
    preg_match_all('/\[(' . $prefixPattern . ')-I#([^\]\r\n]*)\]/i', $text, $matches, PREG_SET_ORDER);
    $ids = [];
    $invalid = [];
    foreach ($matches as $match) {
        $marker = (string) $match[0];
        $prefix = (string) $match[1];
        $candidate = (string) $match[2];
        if (!preg_match('/^([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{22})$/D', $candidate, $parts)) {
            $invalid[$marker] = true;
            continue;
        }
        $id = (int) $parts[1];
        if ($id < 1 || $id > 2147483647
            || !applicationInquiryInboundMarkerIsValid($prefix, $id, (string) $parts[2])
        ) {
            $invalid[$marker] = true;
            continue;
        }
        $ids[$id] = true;
    }
    return [
        'ids' => array_map('intval', array_keys($ids)),
        'invalid' => array_map('strval', array_keys($invalid)),
    ];
}

/** @param array<string, mixed> $message */
function inboundEmailMessageInquiryMarkers(array $message): array
{
    return parseInboundEmailInquiryMarkers(
        (string) ($message['subject'] ?? '') . "\n" . (string) ($message['body_text'] ?? '')
    );
}
