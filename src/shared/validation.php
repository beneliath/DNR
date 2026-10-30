<?php

declare(strict_types=1);

function validIsoDate($date) {
    if (!is_string($date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function requireValidDateRange($start_date, $end_date) {
    if (!validIsoDate($start_date) || !validIsoDate($end_date) || $end_date < $start_date) {
        throw new InvalidArgumentException('Enter a valid event start and end date. The end date cannot precede the start date.');
    }
}

function nullableNonNegativeAmount($value, $label) {
    if (!is_scalar($value) && $value !== null) {
        throw new InvalidArgumentException("Enter a valid {$label} amount.");
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $value)) {
        throw new InvalidArgumentException("Enter a non-negative {$label} amount with no more than two decimal places.");
    }
    return \Dnr\Domain\Money::amount($value, ucfirst($label), '99999999.99');
}

function fulltextSearchQuery($search, $maximum_terms = 8) {
    $search = trim(substr((string) $search, 0, 256));
    if ($search === '' || preg_match('//u', $search) !== 1) {
        return '';
    }
    $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $boolean_terms = [];
    foreach (array_slice($terms, 0, max(1, (int) $maximum_terms)) as $term) {
        $term = preg_replace('/[+\-><()~*"@]+/u', '', $term) ?? '';
        if (strlen($term) >= 3) {
            $boolean_terms[] = '+' . substr($term, 0, 64) . '*';
        }
    }
    return implode(' ', array_values(array_unique($boolean_terms)));
}

function nullableAmountsEqual($left, $right) {
    if ($left === null || $left === '') {
        return $right === null || $right === '';
    }
    if ($right === null || $right === '') {
        return false;
    }
    return \Dnr\Domain\Money::amount($left, 'Amount') === \Dnr\Domain\Money::amount($right, 'Amount');
}

function normalizedHttpUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function normalizeEventType($event_type, $event_type_other) {
    $event_type = trim((string) $event_type);
    $event_type_other = trim((string) $event_type_other);
    $valid_types = \Dnr\Domain\ReferenceData::eventTypes();
    if (!in_array($event_type, $valid_types, true)) {
        throw new InvalidArgumentException('Select a valid event type.');
    }
    if ($event_type === 'other') {
        if ($event_type_other === '' || mb_strlen($event_type_other, 'UTF-8') > 50) {
            throw new InvalidArgumentException('Describe the other event type using 50 characters or fewer.');
        }
        return ['other', $event_type_other];
    }
    return [$event_type, null];
}
