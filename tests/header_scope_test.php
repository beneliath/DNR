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
    substr_count($header_markup, 'class="mobile-brand-logo"') === 1
        && str_contains($header_markup, 'src="assets/dnr-logo.svg?rev=mobile-crop-1&amp;v=test"')
        && str_contains($header_markup, 'data-light-src="assets/dnr-logo.svg?rev=mobile-crop-1&amp;v=test"')
        && str_contains($header_markup, 'data-dark-src="assets/dnr-logo-dark.svg?rev=mobile-dark-1&amp;v=test"')
        && str_contains($header_markup, 'width="180" height="31"'),
    'The current theme-aware DNR logo should render in the mobile application bar.'
);
expectHeaderScope(!str_contains($header_markup, 'app-brand-copy'), 'The desktop sidebar should not retain the previous text-only brand.');
expectHeaderScope(
    !str_contains($header_markup, 'operations.php')
        && !str_contains($header_markup, '<span>Operations</span>'),
    'The primary navigation should not expose the internal Operations dashboard.'
);
expectHeaderScope(
    preg_match(
        '/<span>Dashboard<\/span>.*<span>Engagements<\/span>.*<span>Organizations<\/span>.*<span>Contacts<\/span>.*<span>Work Queue<\/span>.*<span>Inbound Mail<\/span>.*<span>Map<\/span>/s',
        $header_markup
    ) === 1,
    'The primary navigation should lead with the dashboard and keep inbound mail immediately above the map.'
);
expectHeaderScope(
    str_contains($header_markup, '<a class="app-brand" href="dashboard.php"')
        && str_contains($header_markup, '<a href="dashboard.php" class="mobile-brand"'),
    'Desktop and mobile brand links should return to the daily dashboard.'
);
expectHeaderScope(
    substr_count($header_markup, 'class="nav-link admin-nav-link') === 2
        && str_contains($header_markup, '<span>Users</span>')
        && str_contains($header_markup, '<span>Database</span>'),
    'Administrator-only navigation links should carry the dedicated visual treatment.'
);
expectHeaderScope(
    str_contains($header_markup, 'href="help.php"')
        && str_contains($header_markup, '<span>User Manual</span>'),
    'The in-app user manual should be available from the shared utility navigation.'
);
expectHeaderScope(
    preg_match(
        '/<span>Calendar<\/span>.*<span>Mattermost<\/span>.*<span>Account Security<\/span>.*<span>User Manual<\/span>.*<span class="theme-label">Dark Theme<\/span>/s',
        $header_markup
    ) === 1,
    'The utility navigation should place Mattermost between Calendar and Account Security, with the user manual before the theme selector.'
);

foreach (['recover_password.php'] as $authentication_page) {
    $authentication_markup = file_get_contents(__DIR__ . '/../src/' . $authentication_page);
    expectHeaderScope(
        str_contains($authentication_markup, 'applicationBrandName()')
            && str_contains($authentication_markup, 'applicationBrandNativeName()'),
        $authentication_page . ' should render the configured deployment brand.'
    );
    expectHeaderScope(!str_contains($authentication_markup, 'app-brand-mark'), $authentication_page . ' should not render a separate logo mark.');
}

$login_markup = file_get_contents(__DIR__ . '/../src/login.php');
expectHeaderScope(
    substr_count($login_markup, 'class="auth-brand-logo"') === 1
        && str_contains($login_markup, 'data-theme-logo')
        && str_contains($login_markup, "assetUrl(applicationBrandLogo('light') . '?rev=sidebar-crop-1')")
        && str_contains($login_markup, "assetUrl(applicationBrandLogo('dark') . '?rev=sidebar-dark-1')")
        && str_contains($login_markup, 'width="320" height="55"'),
    'The login page should render one responsive DNR logo with light- and dark-theme sources.'
);

$verification_markup = file_get_contents(__DIR__ . '/../src/verify_2fa.php');
expectHeaderScope(
    substr_count($verification_markup, 'class="auth-brand-logo"') === 1
        && str_contains($verification_markup, 'data-theme-logo')
        && str_contains($verification_markup, "assetUrl(applicationBrandLogo('light') . '?rev=sidebar-crop-1')")
        && str_contains($verification_markup, "assetUrl(applicationBrandLogo('dark') . '?rev=sidebar-dark-1')")
        && str_contains($verification_markup, 'width="320" height="55"'),
    'The verification page should render one responsive DNR logo with light- and dark-theme sources.'
);

$configuration_source = file_get_contents(__DIR__ . '/../src/config.php');
$footer_source = file_get_contents(__DIR__ . '/../src/templates/footer.php');
$version = trim((string) file_get_contents(__DIR__ . '/../VERSION'));
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$theme_source = file_get_contents(__DIR__ . '/../src/assets/js/theme.js');
expectHeaderScope(
    preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/', $version) === 1
        && str_contains($configuration_source, "define('APP_VERSION', applicationVersion());"),
    'The application version should be valid and come from the single release metadata file.'
);
expectHeaderScope(
    str_contains($footer_source, "defined('APP_VERSION') ? APP_VERSION : 'dev'"),
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
    preg_match('/<p>&copy;.*<\/p>\n    <p class="footer-moed-definition"><span class="footer-moed-hebrew" lang="he" dir="rtl">מוֹעֵד<\/span>&nbsp;&nbsp;=&nbsp;&nbsp;appointment, appointed time<\/p>/s', $footer_source) === 1
        && preg_match('/\.app-footer \.footer-moed-definition\s*\{(?=[^}]*margin-top:\s*1lh;)(?=[^}]*opacity:\s*0\.5;)[^}]*\}/s', $modern_styles) === 1
        && preg_match('/\.app-footer \.footer-moed-hebrew\s*\{[^}]*font-size:\s*1\.21em;/s', $modern_styles) === 1,
    'The shared footer should show a larger Hebrew name in a half-opacity definition one blank line below the copyright notice.'
);
expectHeaderScope(
    preg_match('/\.footer-link\s*\{[^}]*text-decoration:\s*none;/s', $modern_styles) === 1,
    'Footer links should not be underlined.'
);
expectHeaderScope(
    str_contains($footer_source, 'class="footer-ascii-cat"')
        && str_contains($footer_source, 'aria-label="ASCII art cat"')
        && str_contains($footer_source, "  (il),-''  (li),'  ((!.-'\n\nGenesis 49:9,10 ... Revelation 5:5")
        && str_contains($footer_source, 'Do you see Him?'),
    'The shared footer should leave one blank line between the ASCII cat and the Genesis/Revelation line.'
);
expectHeaderScope(
    preg_match('/\.app-footer \.footer-ascii-cat\s*\{[^}]*margin:\s*2lh 0 0;/s', $modern_styles) === 1
        && preg_match('/\.app-footer \.footer-ascii-cat\s*\{[^}]*font-family:\s*ui-monospace/s', $modern_styles) === 1
        && preg_match('/\.app-footer \.footer-ascii-cat\s*\{[^}]*font-size:\s*clamp\(0\.5rem, 2\.15vw, 0\.58rem\);/s', $modern_styles) === 1
        && preg_match('/\.app-footer \.footer-ascii-cat\s*\{[^}]*opacity:\s*0\.5;/s', $modern_styles) === 1
        && preg_match('/\.app-footer \.footer-ascii-cat\s*\{[^}]*white-space:\s*pre;/s', $modern_styles) === 1,
    'The ASCII cat should sit two lines below the copyright in a small, 50%-opacity, space-preserving monospace font.'
);
expectHeaderScope(
    preg_match('/@media \(max-width: 860px\).*?\.mobile-app-bar\s*\{(?=[^}]*display:\s*flex\s*!important)(?=[^}]*background:\s*var\(--surface\)\s*!important)(?![^}]*backdrop-filter)[^}]*\}/s', $modern_styles) === 1,
    'The responsive mobile application bar should override the desktop hidden state with a solid theme surface.'
);
expectHeaderScope(
    preg_match('/html\.dark-mode body div[^\{]*:not\(\.app-sidebar\):not\(\.mobile-app-bar\):not\(\.qr-code-preview-frame\)\s*\{[^}]*background-color:\s*transparent\s*!important;/s', $modern_styles) === 1,
    'The dark-theme transparency reset should preserve the mobile shell and white QR scan surface.'
);
expectHeaderScope(
    preg_match('/\.app-brand-logo\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*100%;[^}]*height:\s*auto;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand-logo\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*320px;[^}]*height:\s*auto;/s', $modern_styles) === 1
        && preg_match('/\.mobile-brand-logo\s*\{[^}]*width:\s*min\(100%, 180px\);[^}]*height:\s*auto;[^}]*max-height:\s*38px;/s', $modern_styles) === 1
        && preg_match('/html\.dark-mode \.app-sidebar nav\s*\{[^}]*background:\s*transparent\s*!important;/s', $modern_styles) === 1
        && preg_match('/\.auth-brand-copy strong\s*\{[^}]*font-size:\s*2\.53125rem;/s', $modern_styles) === 1,
    'The desktop, mobile, and authentication logos should scale cleanly and adapt to dark mode.'
);
expectHeaderScope(
    str_contains($modern_styles, '--admin-nav: #9a3f00;')
        && str_contains($modern_styles, '--admin-nav: #f4a261;')
        && preg_match('/\.nav-link\.admin-nav-link\s*\{[^}]*color:\s*var\(--admin-nav\)\s*!important;/s', $modern_styles) === 1
        && str_contains($modern_styles, 'html.dark-mode .app-sidebar .nav-link.admin-nav-link.active'),
    'Admin navigation should use accessible burnt-orange colors in light and dark themes.'
);
expectHeaderScope(
    str_contains($theme_source, "document.querySelectorAll('[data-theme-logo]')")
        && str_contains($theme_source, 'isDark ? logo.dataset.darkSrc : logo.dataset.lightSrc'),
    'Theme changes should swap the single brand image between its light and dark sources.'
);
expectHeaderScope(
    str_contains($modern_styles, 'url("../fonts/rubik-latin-700.woff2")')
        && str_contains($modern_styles, 'url("../fonts/rubik-hebrew-700.woff2")')
        && preg_match('/\.auth-brand-copy strong\s*\{[^}]*font-family:\s*"Rubik"[^;}]*;[^}]*font-weight:\s*700;/s', $modern_styles) === 1,
    'Authentication text branding should use the self-hosted Rubik font at weight 700.'
);

echo "Header scope tests passed.\n";
