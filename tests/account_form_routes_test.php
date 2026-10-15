<?php
declare(strict_types=1);

// Render the real opening tags without loading a database or authenticated page.
// PHP_SELF is the backend path on s1, where the proxy strips /a/<account>.
function renderedAccountFormAction(string $page, string $formId, string $backendPath): string
{
    $source = file_get_contents(__DIR__ . '/../src/' . $page);
    $opening = null;
    foreach (explode("\n", $source) as $line) {
        if (str_contains($line, '<form ') && str_contains($line, 'id="' . $formId . '"')) {
            $opening = $line;
            break;
        }
    }
    if ($opening === null) throw new RuntimeException('Missing form: ' . $formId);
    $_SERVER['PHP_SELF'] = $backendPath;
    $engagement_id = 9;
    $org_id = 17;
    ob_start();
    eval('?>' . $opening);
    $html = ob_get_clean();
    $document = new DOMDocument();
    $document->loadHTML($html . '</form>');
    return $document->getElementsByTagName('form')->item(0)->getAttribute('action');
}

foreach ([
    ['edit_engagement.php', 'engagement-edit-form', 9],
    ['edit_organization.php', 'organization-edit-form', 17],
] as [$page, $formId, $id]) {
    foreach (['/' . $page, '/dnr/' . $page, '/a/test-account/' . $page] as $backendPath) {
        $action = renderedAccountFormAction($page, $formId, $backendPath);
        if ($action !== $page . '?id=' . $id) {
            throw new RuntimeException($page . ' must submit relative to the browser account directory; got ' . $action);
        }
    }
}
echo "Engagement and organization form destinations preserve the browser account prefix.\n";
