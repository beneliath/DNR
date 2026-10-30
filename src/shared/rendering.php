<?php

declare(strict_types=1);

function assetUrl($path) {
    $path = ltrim((string) $path, '/');
    $version = defined('APP_VERSION') ? APP_VERSION : 'dev';
    $fragment = '';
    if (str_contains($path, '#')) {
        [$path, $fragment] = explode('#', $path, 2);
        $fragment = '#' . $fragment;
    }
    $identity = 'v=' . rawurlencode($version);

    // Static assets are cached as immutable. Prefer the build manifest so a
    // request does not re-read and hash large bundles; retain a development
    // fallback for newly added files before the next asset build.
    $asset_path = parse_url($path, PHP_URL_PATH);
    if (is_string($asset_path) && str_starts_with($asset_path, 'assets/')) {
        static $manifest = null;
        if ($manifest === null) {
            $manifest_path = dirname(__DIR__) . '/assets/asset-manifest.php';
            $loaded_manifest = is_file($manifest_path) ? require $manifest_path : [];
            $manifest = is_array($loaded_manifest) ? $loaded_manifest : [];
        }
        $fingerprint = $manifest[$asset_path] ?? '';
        if (!is_string($fingerprint) || preg_match('/\A[0-9a-f]{12}\z/', $fingerprint) !== 1) {
            $local_path = dirname(__DIR__) . '/' . $asset_path;
            if (is_file($local_path)) {
                static $fallback_fingerprints = [];
                if (!array_key_exists($local_path, $fallback_fingerprints)) {
                    $hash = hash_file('sha256', $local_path);
                    $fallback_fingerprints[$local_path] = is_string($hash) ? substr($hash, 0, 12) : '';
                }
                $fingerprint = $fallback_fingerprints[$local_path];
            }
        }
        if (is_string($fingerprint) && $fingerprint !== '') {
            $identity = 'h=' . rawurlencode($fingerprint);
        }
    }

    return $path . (str_contains($path, '?') ? '&' : '?') . $identity . $fragment;
}

function renderPageHead($title, array $options = []) {
    $full_title = trim((string) $title);
    if ($full_title === '') {
        $full_title = applicationBrandName();
    }
    $styles = $options['styles'] ?? ['assets/css/style.min.css', 'assets/css/modern.min.css'];
    $scripts = $options['scripts'] ?? [];
    if (aiCoachEnabled() && !empty($_SESSION['user_id'])) {
        $styles[] = 'assets/css/pages/ai-coach.min.css';
        $scripts[] = 'assets/js/ai-coach.min.js';
    }
    // Normalize native dropdowns after both MOED and MessageMonkey page styles.
    $styles[] = 'assets/css/pages/native-selects.min.css';
    if (in_array('assets/css/pages/audience.min.css', $styles, true)) {
        $styles[] = 'assets/css/pages/audience-ui-standards.min.css';
        $styles[] = 'assets/css/pages/audience-mobile.min.css';
    }
    echo '<head>' . PHP_EOL;
    echo '    <meta charset="UTF-8">' . PHP_EOL;
    echo '    <meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL;
    echo '    <meta name="user-timezone" content="'.htmlspecialchars(userDisplayTimezoneName(),ENT_QUOTES,'UTF-8').'">' . PHP_EOL;
    echo '    <title>' . htmlspecialchars($full_title, ENT_QUOTES, 'UTF-8') . '</title>' . PHP_EOL;
    echo '    <link rel="icon" type="image/svg+xml" href="'
        . htmlspecialchars(assetUrl('assets/favicon.svg'), ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    renderScript('assets/js/account-context.min.js', false);
    renderScript('assets/js/theme-init.min.js', false);
    renderScript('assets/js/user-time.min.js', false);
    foreach ($styles as $style) {
        echo '    <link rel="stylesheet" href="'
            . htmlspecialchars(assetUrl((string) $style), ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }
    foreach ($scripts as $script) {
        $path = is_array($script) ? ($script['path'] ?? '') : $script;
        if ($path === '' || $path === 'assets/js/theme-init.min.js') {
            continue;
        }
        $defer = !is_array($script) || !array_key_exists('defer', $script) || $script['defer'];
        renderScript((string) $path, $defer);
    }
    renderScript('assets/js/form-ux.min.js');
    renderScript('assets/js/button-feedback.min.js');
    echo '</head>' . PHP_EOL;
}

/**
 * Render linked errors only when the caller identifies the affected field.
 * @param array<array-key, string>|string $errors
 * @param array<array-key, string> $field_ids
 */
function formErrorSummary(array|string $errors, array $field_ids = []): string {
    $errors = is_array($errors) ? $errors : [$errors];
    $errors = array_filter($errors, static fn(string $message): bool => trim($message) !== '');
    if ($errors === []) return '';
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $html = '<div class="error form-error-summary" data-form-errors role="alert" tabindex="-1">'
        . '<h2>Check the Details Below</h2><ul>';
    foreach ($errors as $key => $message) {
        $field_id = $field_ids[$key] ?? '';
        $html .= '<li' . ($field_id !== '' ? ' data-error-for="' . $escape($field_id) . '"' : '') . '>';
        $html .= $field_id !== ''
            ? '<a href="#' . $escape(rawurlencode($field_id)) . '">' . $escape($message) . '</a>'
            : $escape($message);
        $html .= '</li>';
    }
    return $html . '</ul></div>';
}

function renderScript($path, $defer = true, $module = false) {
    echo '<script src="' . htmlspecialchars(assetUrl((string) $path), ENT_QUOTES, 'UTF-8') . '"'
        . ($module ? ' type="module"' : '')
        . ($defer ? ' defer' : '') . '></script>' . PHP_EOL;
}

function actionIconSvg($action)
{
    static $icons = [
        'view' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>',
        'edit' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>',
        'duplicate' => '<svg class="action-icon action-icon-duplicate" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>',
        'create-email' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M13 19H3V5h18v7M3 5l9 7 9-7M18 15v6M15 18h6"/></svg>',
        'export-csv' => '<svg class="action-icon action-icon-export" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M14 3H4v18h16V9ZM14 3v6h6M8 17h8M12 10v5m-3-3 3 3 3-3"/></svg>',
        'plain-text-email' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M14 3H4v18h16V9ZM14 3v6h6M8 13h8M12 13v5M10 18h4"/></svg>',
        'export-html' => '<svg class="action-icon action-icon-export" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M14 3H5v18h14V8ZM14 3v5h5M10 12l-3 3 3 3M14 12l3 3-3 3"/></svg>',
        'rename' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M4 7V4h10v3M9 4v16M6 20h6M17 4h4M19 4v16M17 20h4"/></svg>',
        'folder' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 20V5h6l2 3h10v12ZM8 14h8M13 11l3 3-3 3"/></svg>',
        'extend' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18M12 7v5l-3 2M18 3v8M14 7h8"/></svg>',
        'lock' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>',
        'start' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4Z"/></svg>',
        'complete' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>',
        'submit' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/></svg>',
        'save' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M4 3h14l3 3v15H3V3h1Z"/><path d="M7 3v6h10V3M7 21v-8h10v8M14 3v4"/></svg>',
        'archive' => '<svg class="action-icon action-icon-archive" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v12h14V8M9 12h6"/></svg>',
        'restore' => '<svg class="action-icon action-icon-restore" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
        'pause' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="5" y="4" width="5" height="16" rx="1"/><rect x="14" y="4" width="5" height="16" rx="1"/></svg>',
        'results' => '<svg class="action-icon action-icon-results" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 21V12h4v9Zm7 0V7h4v14Zm7 0V2h4v19Z"/></svg>',
        'delete' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 10v6M14 10v6"/></svg>',
    ];

    return $icons[$action] ?? '';
}
