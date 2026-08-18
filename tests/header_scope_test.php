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
    str_contains($header_markup, 'MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
    'The MOED application brand should render with isolated right-to-left Hebrew text.'
);
expectHeaderScope(
    str_contains($header_markup, 'class="mobile-brand-name">MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
    'The complete MOED brand should render in the mobile application bar.'
);
expectHeaderScope(!str_contains($header_markup, 'app-brand-mark'), 'The shared application brand should not render a separate logo mark.');

foreach (['login.php', 'recover_password.php', 'verify_2fa.php'] as $authentication_page) {
    $authentication_markup = file_get_contents(__DIR__ . '/../src/' . $authentication_page);
    expectHeaderScope(
        str_contains($authentication_markup, 'MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
        $authentication_page . ' should render the MOED brand.'
    );
    expectHeaderScope(!str_contains($authentication_markup, 'app-brand-mark'), $authentication_page . ' should not render a separate logo mark.');
}

$configuration_source = file_get_contents(__DIR__ . '/../src/config.php');
$footer_source = file_get_contents(__DIR__ . '/../src/templates/footer.php');
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
expectHeaderScope(
    str_contains($configuration_source, "define('APP_VERSION', '1.3.5');"),
    'The application version should be 1.3.5.'
);
expectHeaderScope(
    str_contains($footer_source, "defined('APP_VERSION') ? APP_VERSION : '1.3.5'"),
    'The footer should render the configured application version.'
);
expectHeaderScope(
    !str_contains($configuration_source, 'APP_VERSION_COMMIT')
        && !str_contains($configuration_source, 'APP_VERSION_PUSHED_AT')
        && str_contains($footer_source, 'githubPushMetadata()')
        && str_contains($footer_source, '<time datetime=')
        && !str_contains($footer_source, '> pushed <time datetime=')
        && str_contains($footer_source, "'/commit/' . \$footer_push['commit']"),
    'The footer should show automatically refreshed GitHub push metadata without a stale tracked fallback.'
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
    !str_contains($modern_styles, '.app-brand-mark')
        && preg_match('/\.app-brand-copy strong\s*\{[^}]*font-size:\s*2\.175rem;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand-copy strong\s*\{[^}]*font-size:\s*2\.53125rem;/s', $modern_styles) === 1
        && preg_match('/\.mobile-brand-name\s*\{[^}]*font-size:\s*1\.875rem;/s', $modern_styles) === 1,
    'MOED and its Hebrew text should render at the enlarged sizes without a separate logo mark.'
);
expectHeaderScope(
    str_contains($modern_styles, 'url("../fonts/rubik-latin-700.woff2")')
        && str_contains($modern_styles, 'url("../fonts/rubik-hebrew-700.woff2")')
        && preg_match('/\.app-brand-copy strong,\s*\.auth-brand-copy strong,\s*\.mobile-brand-name\s*\{[^}]*font-family:\s*"Rubik"[^;}]*;[^}]*font-weight:\s*700;/s', $modern_styles) === 1,
    'The Latin and Hebrew MOED branding should use the self-hosted Rubik font at weight 700.'
);

echo "Header scope tests passed.\n";
