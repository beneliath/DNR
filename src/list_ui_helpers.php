<?php

declare(strict_types=1);

/**
 * Apply an allowlisted selection or the list's fixed default order.
 * @param array<string, array{string, string}> $columns Column => [query key, default direction].
 * @return array{column: string, direction: string}
 */
function listSortSelection(array $input, array $columns, string $defaultColumn): array
{
    $default = ['column' => $defaultColumn, 'direction' => $columns[$defaultColumn][1]];
    $valid = static fn(array $value): bool => is_string($value['column'] ?? null)
        && isset($columns[$value['column']]) && in_array($value['direction'] ?? null, ['asc', 'desc'], true);
    // Legacy links can specify the default column's direction without sort_by.
    $column = array_key_exists('sort_by', $input) ? $input['sort_by']
        : (array_key_exists($columns[$defaultColumn][0], $input) ? $defaultColumn : null);
    if (!is_string($column) || !isset($columns[$column])) return $default;
    [$directionKey, $defaultDirection] = $columns[$column];
    $direction = array_key_exists($directionKey, $input) ? $input[$directionKey] : $defaultDirection;
    $requested = ['column' => $column, 'direction' => is_string($direction) ? strtolower($direction) : $direction];
    return $valid($requested) ? $requested : $default;
}

function listSortState(bool $active, string $direction): string
{
    return $active ? ($direction === 'desc' ? 'descending' : 'ascending') : 'none';
}

function listSortArrow(bool $active, string $direction): string
{
    return $active ? ($direction === 'desc' ? '↓' : '↑') : '↕';
}

/** @param array<string, array{string, string}> $tabs */
function renderListContextTabs(array $tabs, string $current, string $ariaLabel): void
{
    echo '<nav class="list-context-tabs" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($tabs as $key => [$label, $url]) {
        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
            . ($key === $current ? ' aria-current="page"' : '')
            . ' data-width-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><span>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
    echo '</nav>';
}

function renderListFilterSummary(array $filters, string $resetUrl): void
{
    echo '<div class="list-filter-summary" aria-label="Current Filters"><strong>Showing</strong>';
    foreach ($filters as $label => $value) {
        if ($value === null || $value === '') continue;
        $archivedVisibility = $label === 'Visibility' && $value === 'Archived';
        $visibilityWidth = $label === 'Visibility' && in_array($value, ['Active', 'Archived'], true);
        echo '<span class="filter-chip' . ($archivedVisibility ? ' is-archived-visibility' : '') . '"'
            . ($visibilityWidth ? ' data-width-label="Visibility: Archived"' : '') . '><span>'
            . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . ': '
            . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</span></span>';
    }
    echo '<a class="clear-filters" href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Clear Filters</a></div>';
}
