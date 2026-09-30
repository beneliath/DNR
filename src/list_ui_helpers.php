<?php

declare(strict_types=1);

function renderListFilterSummary(array $filters, string $resetUrl): void
{
    echo '<div class="list-filter-summary" aria-label="Current filters"><strong>Showing</strong>';
    foreach ($filters as $label => $value) {
        if ($value === null || $value === '') continue;
        echo '<span class="filter-chip">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . ': '
            . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '<a class="clear-filters" href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Clear Filters</a></div>';
}
