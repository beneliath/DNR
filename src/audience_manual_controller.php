<?php
declare(strict_types=1);

/** Render the authenticated guide without loading connection or campaign records. */
function renderAudienceManual(array $audience, bool $canManage, bool $canDelete, string $displayName, int $actor, ?AudienceStore $store = null): void
{
    $view = 'manual';
    $pageTitle = 'User Manual';
    $audienceId = (int) $audience['id'];
    $canExport = $canManage;
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $url = static fn(array $params = []): string => 'audience.php'.($params ? '?'.http_build_query($params) : '');
    $themeKey = currentAccountKey().':'.$actor.':'.$audienceId;
    $message = $error = '';
    $catalog = $tags = $groups = $fields = [];
    $rules = ['match'=>'all', 'rules'=>[]];
    $filters = audienceListFilters([]);
    $segment = $job = null;
    $csrf = csrfInput();
    $manualChapters = require __DIR__.'/templates/audience/manual-content.php';
    $manualRole = $canDelete ? 'Administrator' : ($canManage ? 'Editor' : 'Reviewer');
    $manualRoleSummary = $canDelete
        ? 'Manage content and audience records, configure delivery, and use protected actions with Admin Unlock.'
        : ($canManage ? 'Create and edit content, manage connections, and use imports, exports, Inbox, and writing tools.'
            : 'Browse audience records, campaigns, forms, automations, and reports. Editing, Inbox, imports, and exports require an editor or administrator.');
    releaseApplicationSessionLock();
    ?><!doctype html><html lang="en"><?php
    renderPageHead(applicationPageTitle($pageTitle), [
        'styles'=>['assets/css/pages/audience.min.css', 'assets/css/pages/audience-manual.min.css'],
        'scripts'=>['assets/js/audience.min.js', 'assets/js/user-manual.min.js'],
    ]);
    include __DIR__.'/templates/audience/workspace.php';
    ?></html><?php
}
