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

foreach (['login.php', 'recover_password.php', 'verify_2fa.php'] as $authentication_page) {
    $authentication_markup = file_get_contents(__DIR__ . '/../src/' . $authentication_page);
    expectHeaderScope(
        str_contains($authentication_markup, 'MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi>'),
        $authentication_page . ' should render the MOED brand.'
    );
}

$configuration_source = file_get_contents(__DIR__ . '/../src/config/config.php');
$footer_source = file_get_contents(__DIR__ . '/../src/templates/footer.php');
expectHeaderScope(
    str_contains($configuration_source, "define('APP_VERSION', '0.1.0');"),
    'The application version should be 0.1.0.'
);
expectHeaderScope(
    str_contains($footer_source, "defined('APP_VERSION') ? APP_VERSION : '0.1.0'"),
    'The footer should render the configured application version.'
);

echo "Header scope tests passed.\n";
