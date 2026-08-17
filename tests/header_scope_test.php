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

foreach (['login.php', 'recover_password.php', 'verify_2fa.php'] as $authentication_page) {
    $authentication_markup = file_get_contents(__DIR__ . '/../src/' . $authentication_page);
    expectHeaderScope(
        str_contains($authentication_markup, 'MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
        $authentication_page . ' should render the MOED brand.'
    );
}

$configuration_source = file_get_contents(__DIR__ . '/../src/config/config.php');
$footer_source = file_get_contents(__DIR__ . '/../src/templates/footer.php');
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
expectHeaderScope(
    str_contains($configuration_source, "define('APP_VERSION', '0.1.7');"),
    'The application version should be 0.1.7.'
);
expectHeaderScope(
    str_contains($footer_source, "defined('APP_VERSION') ? APP_VERSION : '0.1.7'"),
    'The footer should render the configured application version.'
);
expectHeaderScope(
    preg_match('/@media \(max-width: 860px\).*?\.mobile-app-bar\s*\{[^}]*display:\s*flex\s*!important;/s', $modern_styles) === 1,
    'The responsive mobile application bar should override the desktop hidden state.'
);
expectHeaderScope(
    preg_match('/\.app-brand-mark\s*\{[^}]*font-size:\s*1\.16rem\s*!important;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand \.app-brand-mark\s*\{[^}]*font-size:\s*1\.35rem\s*!important;/s', $modern_styles) === 1
        && preg_match('/\.mobile-brand \.app-brand-mark\s*\{[^}]*font-size:\s*1rem\s*!important;/s', $modern_styles) === 1,
    'DNR logo text should match the adjacent MOED brand size in each layout.'
);
expectHeaderScope(
    preg_match('/\.app-brand-mark\s*\{[^}]*width:\s*60px;[^}]*height:\s*52px;[^}]*padding-inline:\s*6px;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand \.app-brand-mark\s*\{[^}]*width:\s*68px;[^}]*height:\s*60px;/s', $modern_styles) === 1
        && preg_match('/\.mobile-brand \.app-brand-mark\s*\{[^}]*width:\s*50px;[^}]*height:\s*44px;/s', $modern_styles) === 1,
    'The DNR mark should provide internal space around the enlarged lettering.'
);

echo "Header scope tests passed.\n";
