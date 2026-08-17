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

$configuration_source = file_get_contents(__DIR__ . '/../src/config/config.php');
$footer_source = file_get_contents(__DIR__ . '/../src/templates/footer.php');
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
expectHeaderScope(
    str_contains($configuration_source, "define('APP_VERSION', '0.1.8');"),
    'The application version should be 0.1.8.'
);
expectHeaderScope(
    str_contains($footer_source, "defined('APP_VERSION') ? APP_VERSION : '0.1.8'"),
    'The footer should render the configured application version.'
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

echo "Header scope tests passed.\n";
