<?php
declare(strict_types=1);
// Render the real workspace with synthetic records; no account or database access.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';

$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$url = static fn(array $params = []) => 'audience.php?'.http_build_query($params);
$view = 'tags';
$themeKey = 'navigation-browser-fixture';
$displayName = 'Example User';
$pageTitle = 'Tags';
$canManage = ($argv[1] ?? 'editor') === 'editor';
$canDelete = false;
$message = $error = $csrf = '';
$audienceId = 1;
$tags = $groups = $fields = $catalog = $query = [];
$rules = ['match'=>'all', 'rules'=>[]];
$filters = ['q'=>'', 'status'=>[], 'source'=>[], 'tags'=>[], 'archived'=>'0'];
$segment = $job = null;

echo '<!doctype html><html>';
renderPageHead('MessageMonkey navigation', [
    'styles'=>['assets/css/pages/audience.min.css'],
    'scripts'=>['assets/js/audience.min.js'],
]);
ob_start();
require __DIR__.'/../../src/templates/audience/workspace.php';
$workspace = ob_get_clean();
echo str_replace('</main>', '<div style="height:1800px">Scrollable fixture content</div></main>', $workspace);
echo '</html>';
