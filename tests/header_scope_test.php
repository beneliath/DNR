<?php

function expectHeaderScope($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Header scope test failed: {$message}\n");
        exit(1);
    }
}

function csrfInput()
{
    return '';
}

function renderScript($path, $defer = true)
{
    echo '<script src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"'
        . ($defer ? ' defer' : '') . '></script>';
}

function assetUrl($path)
{
    return $path . (str_contains($path, '?') ? '&' : '?') . 'v=test';
}

$_SERVER['PHP_SELF'] = '/contacts.php';
$_SESSION = [
    'username' => 'Test User',
    'role' => 'admin',
];
$current_page = 2;

ob_start();
include __DIR__ . '/../src/templates/header.php';
$header_markup = ob_get_clean();

expectHeaderScope($current_page === 2, 'The shared header must not overwrite a page\'s pagination state.');
expectHeaderScope($shell_current_page === 'contacts.php', 'The header should retain its own active-page state.');
expectHeaderScope(
    substr_count($header_markup, 'class="app-brand-logo"') === 1
        && str_contains($header_markup, 'src="assets/dnr-logo.svg?rev=sidebar-crop-1&amp;v=test"')
        && str_contains($header_markup, 'data-theme-logo')
        && str_contains($header_markup, 'data-dark-src="assets/dnr-logo-dark.svg?rev=sidebar-dark-1&amp;v=test"')
        && str_contains($header_markup, 'width="228" height="39"'),
    'The desktop sidebar should render one logo element with versioned light- and dark-theme sources.'
);
expectHeaderScope(
    str_contains($header_markup, 'class="mobile-brand-name">MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
    'The complete MOED brand should render in the mobile application bar.'
);
expectHeaderScope(!str_contains($header_markup, 'app-brand-copy'), 'The desktop sidebar should not retain the previous text-only brand.');
expectHeaderScope(
    !str_contains($header_markup, 'operations.php')
        && !str_contains($header_markup, '<span>Operations</span>'),
    'The primary navigation should not expose the internal Operations dashboard.'
);

foreach (['recover_password.php'] as $authentication_page) {
    $authentication_markup = file_get_contents(__DIR__ . '/../src/' . $authentication_page);
    expectHeaderScope(
        str_contains($authentication_markup, 'MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
        $authentication_page . ' should render the MOED brand.'
    );
    expectHeaderScope(!str_contains($authentication_markup, 'app-brand-mark'), $authentication_page . ' should not render a separate logo mark.');
}

$login_markup = file_get_contents(__DIR__ . '/../src/login.php');
expectHeaderScope(
    substr_count($login_markup, 'class="auth-brand-logo"') === 1
        && str_contains($login_markup, 'data-theme-logo')
        && str_contains($login_markup, "assetUrl('assets/dnr-logo.svg?rev=sidebar-crop-1')")
        && str_contains($login_markup, "assetUrl('assets/dnr-logo-dark.svg?rev=sidebar-dark-1')")
        && str_contains($login_markup, 'width="320" height="55"'),
    'The login page should render one responsive DNR logo with light- and dark-theme sources.'
);

$verification_markup = file_get_contents(__DIR__ . '/../src/verify_2fa.php');
expectHeaderScope(
    substr_count($verification_markup, 'class="auth-brand-logo"') === 1
        && str_contains($verification_markup, 'data-theme-logo')
        && str_contains($verification_markup, "assetUrl('assets/dnr-logo.svg?rev=sidebar-crop-1')")
        && str_contains($verification_markup, "assetUrl('assets/dnr-logo-dark.svg?rev=sidebar-dark-1')")
        && str_contains($verification_markup, 'width="320" height="55"'),
    'The verification page should render one responsive DNR logo with light- and dark-theme sources.'
);

$configuration_source = file_get_contents(__DIR__ . '/../src/config.php');
$footer_source = file_get_contents(__DIR__ . '/../src/templates/footer.php');
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$theme_source = file_get_contents(__DIR__ . '/../src/assets/js/theme.js');
expectHeaderScope(
    str_contains($configuration_source, "define('APP_VERSION', '1.4.6');"),
    'The application version should be 1.4.6.'
);
expectHeaderScope(
    str_contains($footer_source, "defined('APP_VERSION') ? APP_VERSION : '1.4.6'"),
    'The footer should render the configured application version.'
);
expectHeaderScope(
    !str_contains($configuration_source, 'APP_VERSION_COMMIT')
        && !str_contains($configuration_source, 'APP_VERSION_PUSHED_AT')
        && str_contains($footer_source, 'githubPushMetadata()')
        && str_contains($footer_source, '<time datetime=')
        && !str_contains($footer_source, '> pushed <time datetime=')
        && str_contains($footer_source, "'/commit/' . \$footer_push['commit']"),
    'The footer should show build-injected provenance without a tracked fallback.'
);
expectHeaderScope(
    str_contains($footer_source, 'githubRepositoryUrl()')
        && str_contains($footer_source, 'target="_blank"')
        && str_contains($footer_source, 'rel="noopener noreferrer"'),
    'The footer project name and version should safely open the GitHub project page in a new tab.'
);
expectHeaderScope(
    str_contains($footer_source, "'https://github.com/' . rawurlencode(\$footer_repository_owner)"),
    'The footer author should safely open the GitHub profile in a new tab.'
);
expectHeaderScope(
    preg_match('/\.footer-link\s*\{[^}]*text-decoration:\s*none;/s', $modern_styles) === 1,
    'Footer links should not be underlined.'
);
expectHeaderScope(
    preg_match('/@media \(max-width: 860px\).*?\.mobile-app-bar\s*\{[^}]*display:\s*flex\s*!important;/s', $modern_styles) === 1,
    'The responsive mobile application bar should override the desktop hidden state.'
);
expectHeaderScope(
    preg_match('/html\.dark-mode body div[^\{]*:not\(\.app-sidebar\)\s*\{[^}]*background-color:\s*transparent\s*!important;/s', $modern_styles) === 1,
    'The dark-theme transparency reset should preserve the mobile sidebar surface.'
);
expectHeaderScope(
    preg_match('/\.app-brand-logo\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*100%;[^}]*height:\s*auto;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand-logo\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*320px;[^}]*height:\s*auto;/s', $modern_styles) === 1
        && preg_match('/html\.dark-mode \.app-sidebar nav\s*\{[^}]*background:\s*transparent\s*!important;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand-copy strong\s*\{[^}]*font-size:\s*2\.53125rem;/s', $modern_styles) === 1
        && preg_match('/\.mobile-brand-name\s*\{[^}]*font-size:\s*1\.875rem;/s', $modern_styles) === 1,
    'The sidebar logo should scale to its container and adapt cleanly to dark mode.'
);
expectHeaderScope(
    str_contains($theme_source, "document.querySelectorAll('[data-theme-logo]')")
        && str_contains($theme_source, 'isDark ? logo.dataset.darkSrc : logo.dataset.lightSrc'),
    'Theme changes should swap the single brand image between its light and dark sources.'
);
expectHeaderScope(
    str_contains($modern_styles, 'url("../fonts/rubik-latin-700.woff2")')
        && str_contains($modern_styles, 'url("../fonts/rubik-hebrew-700.woff2")')
        && preg_match('/\.auth-brand-copy strong,\s*\.mobile-brand-name\s*\{[^}]*font-family:\s*"Rubik"[^;}]*;[^}]*font-weight:\s*700;/s', $modern_styles) === 1,
    'The Latin and Hebrew text branding should use the self-hosted Rubik font at weight 700.'
);

echo "Header scope tests passed.\n";
