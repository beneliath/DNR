<?php
$baseFilters=array_intersect_key($_GET,array_flip(['status','source','label','q','unread']));
$filterUrl=static fn(array $p=[])=>'audience.php?'.http_build_query(['view'=>'inbox']+array_replace($baseFilters,$p));
$form=static function(string $action,array $values=[])use($csrf,$h):void{echo $csrf.'<input type="hidden" name="action" value="'.$h($action).'">';foreach($values as$k=>$v)echo '<input type="hidden" name="'.$h($k).'" value="'.$h($v).'">';};
$rich=static function(string $value,string $label='Message')use($h):void{?><div class="inbox-rich" data-inbox-rich><label><?= $h($label) ?></label><div class="inbox-format" role="toolbar" aria-label="Text formatting"><button type="button" data-format="bold" aria-label="Bold"><b>B</b></button><button type="button" data-format="italic" aria-label="Italic"><i>I</i></button><button type="button" data-format="underline" aria-label="Underline"><u>U</u></button><button type="button" data-format="insertUnorderedList" aria-label="Bulleted list">☷</button><button type="button" data-format="insertOrderedList" aria-label="Numbered list">1.</button><button type="button" data-format="createLink">Link</button></div><div class="inbox-editor" contenteditable="true" role="textbox" aria-label="<?= $h($label) ?>" aria-multiline="true"><?= audienceDesignRichText($value) ?></div><textarea name="body_html" class="inbox-rich-fallback" aria-label="<?= $h($label) ?> HTML" maxlength="30000"><?= $h($value) ?></textarea></div><?php };
$sourceNames=['email'=>'Email reply','contact'=>'Contact form','survey'=>'Survey response','direct'=>'Direct message'];
$statusNames=['todo'=>'To do','done'=>'Done','all'=>'All conversations','trash'=>'Trash'];
$initials=static function(string $name):string{$words=preg_split('/\s+/',trim($name))?:[];return mb_strtoupper(mb_substr($words[0]??'?',0,1).(count($words)>1?mb_substr(end($words),0,1):''));};
$icon=static function(string $name)use($h):void{
    $paths=['sources'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z','todo'=>'M4 5h16v14H4z M4 14h5l2 3h2l2-3h5','done'=>'m5 12 4 4L19 6','all'=>'M4 5h16v14H4z M4 9h16','trash'=>'M4 6h16 M9 6V3h6v3 M6 6l1 15h10l1-15 M10 10v7 M14 10v7','email'=>'M3 5h18v14H3z m0 0 9 7 9-7','contact'=>'M5 3h14v18H5z M9 8h6 M9 12h6 M9 16h3','survey'=>'M5 3h14v18H5z m3 5 1 1 2-2 M13 8h3 m-8 5 1 1 2-2 M13 13h3','direct'=>'M4 4h16v12H9l-5 4z','search'=>'M20 20l-5-5 M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0','compose'=>'m4 15 11-11 5 5L9 20H4z M13 6l5 5','label'=>'M3 3h8l10 10-8 8L3 11z M7 7h.01','settings'=>'M4 7h16 M4 17h16 M9 4v6 M15 14v6','draft'=>'M5 3h10l4 4v14H5z M14 3v5h5 M9 12h6 M9 16h4','reply'=>'m9 5-7 7 7 7 M2 12h12a7 7 0 0 1 7 7','refresh'=>'M20 7v5h-5 M4 17v-5h5 M6 6a8 8 0 0 1 14 6 M4 12a8 8 0 0 0 14 6','note'=>'M4 4h16v16H4z M8 9h8 M8 13h8 M8 17h4','arrow'=>'m9 5 7 7-7 7'];
    ?><svg class="inbox-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $h($paths[$name]??$paths['email']) ?>"/></svg><?php
};
$time=static function(string $value,bool $short=false)use($h):void{$iso=str_replace(' ','T',$value).'Z';?><time datetime="<?= $h($iso) ?>" data-inbox-time="<?= $short?'short':'full' ?>" title="<?= $h(formatUserTimestamp($value,'Y-m-d H:i:s')) ?>"><?= $h(formatUserTimestamp($value,$short?'M j, H:i':'Y-m-d H:i:s')) ?></time><?php };
$filtered=!empty($list['q'])||!empty($list['label'])||!empty($list['source'])||!empty($list['unread']);
?>
<body class="audience-app audience-inbox-app" data-mm-workspace="inbox" data-audience-key="<?= $h($themeKey) ?>">
<?php include __DIR__.'/../deployment-notice.php';include __DIR__.'/../account-identity-banner.php';include __DIR__.'/../role-preview-banner.php';include __DIR__.'/../admin-unlock-banner.php'; ?>
<a class="audience-skip" href="#inbox-main">Skip to Inbox</a>
<header class="inbox-top"><a class="inbox-back" href="audience.php" aria-label="Back to Audience">←</a><a class="audience-logo-wrap" href="audience.php?view=home" data-audience-logo data-dashboard-url="dashboard.php" aria-label="MessageMonkey Home" title="MessageMonkey Home (Ctrl-click for MOED dashboard)"><?php readfile(__DIR__.'/../../assets/audience-logo-transparent.svg'); ?></a><div class="inbox-brand"><strong>Inbox</strong><span>Audience conversations</span></div><button type="button" class="inbox-mobile-nav" aria-controls="inbox-sidebar" aria-expanded="false">Menu</button><span class="inbox-top-spacer"></span><?php include __DIR__.'/account-tools.php'; ?></header>
<div class="inbox-layout<?= ($draftWorkspace?$draft:($thread||$panel))?' inbox-has-detail':'' ?><?= $panel&&!$draftWorkspace?' inbox-has-panel':'' ?><?= $draftWorkspace?' inbox-draft-workspace':'' ?>">
<?php include __DIR__.'/inbox-navigation.php'; ?>
<main id="inbox-main" class="inbox-main<?= (!$panel&&!$thread)||($draftWorkspace&&!$draft)?' inbox-content-empty':'' ?>" tabindex="-1">
<div class="inbox-page-body">
<?php if($message): ?><p class="inbox-notice" role="status"><?= $h($message) ?></p><?php endif; ?><?php if($error): ?><p class="inbox-error" role="alert"><?= $h($error) ?></p><?php endif; ?><p id="inbox-live-status" role="status" class="inbox-notice" hidden></p>
<?php if($draftWorkspace&&$draft): ?><a class="inbox-return" href="<?= $h($draftUrl()) ?>">← Back to drafts</a><?php elseif(!$draftWorkspace&&($thread||$panel)): ?><a class="inbox-return" href="<?= $h($thread&&$panel?$url(['thread'=>$threadId]):$url()) ?>">← <?= $thread&&$panel?'Back to conversation':'Back to messages' ?></a><?php endif; ?>
<?php if(!$available): ?><h1>Inbox</h1><p>Inbox will be available after its database update is qualified.</p>
<?php elseif($panel==='sources'): ?>
<?php include __DIR__.'/inbox-sources.php'; ?>
<?php elseif(in_array($panel,['labels','templates'],true)): $kind=$panel==='labels'?'label':'template';$rows=$kind==='label'?$labels:$templates;$edit=null;foreach($rows as$r)if((int)$r['id']===(int)($_GET['edit']??0))$edit=$r;if($error&&($_POST['action']??'')==='inbox_named_save'&&($_POST['kind']??'')===$kind)$edit=['id'=>max(0,(int)($_POST['id']??0)),'version'=>max(0,(int)($_POST['version']??0)),'name'=>audienceText($_POST,'name',100),'subject'=>audienceText($_POST,'subject',250),'body_html'=>audienceText($_POST,'body_html',30000)]; ?>
<?php if($kind==='template'&&!$edit&&!isset($_GET['new'])): ?>
<?php $templateRows=$templateArchived?$archivedTemplates:$templates; ?>
<section class="inbox-template-library" aria-labelledby="inbox-template-heading">
    <header class="inbox-library-heading"><div><span class="inbox-eyebrow">REUSABLE REPLIES</span><h1 id="inbox-template-heading">Message templates <span><?= count($templateRows) ?></span></h1><p><?= $templateArchived?'Archived replies are kept here until you restore or delete them.':'Start with a saved reply, then make it personal.' ?></p></div><a class="button primary" href="<?= $h($url(['panel'=>'templates','new'=>1])) ?>"><?php $icon('compose'); ?>New template</a></header>
    <nav class="inbox-template-tabs audience-lifecycle-tabs" aria-label="Message template status"><?php foreach(['active'=>count($templates),'archived'=>count($archivedTemplates)]as$state=>$count): ?><a href="<?= $h($url(['panel'=>'templates','template_status'=>$state])) ?>"<?= ($templateArchived===($state==='archived'))?' aria-current="page"':'' ?>><?= $state==='active'?'Active':'Archive' ?> <span><?= $count ?></span></a><?php endforeach; ?></nav>
    <div class="inbox-template-personalization"><?php $icon('note'); ?><span>Personalize your messages with <code>*|FNAME|*</code>, <code>*|LNAME|*</code>, <code>*|EMAIL|*</code>, and <code>*|LIST:COMPANY|*</code>.</span></div>
    <div class="inbox-template-grid">
        <?php foreach($templateRows as$t): ?>
        <article class="inbox-template-card">
            <div class="inbox-template-card-icon"><?php $icon('note'); ?></div>
            <h2><?php if(!$templateArchived): ?><a href="<?= $h($url(['panel'=>'templates','edit'=>(int)$t['id']])) ?>"><?= $h($t['name']) ?></a><?php else: ?><?= $h($t['name']) ?><?php endif; ?></h2>
            <div class="inbox-template-subject"><span>SUBJECT</span><p><?= $h($t['subject']?:'(No subject)') ?></p></div>
            <p class="inbox-template-preview"><?= $h(mb_substr(audienceInboxPlain($t['body_html']),0,320)) ?></p>
            <footer>
                <?php if(!$templateArchived): ?><a class="action-icon-button" href="<?= $h($url(['panel'=>'templates','edit'=>(int)$t['id']])) ?>" aria-label="Edit <?= $h($t['name']) ?>" data-tooltip="Edit"><?= actionIconSvg('edit') ?></a><?php endif; ?>
                <form method="post"><?php $form('inbox_template_operation',['operation'=>$templateArchived?'restore':'archive','id'=>$t['id'],'version'=>$t['version']]); ?><button type="submit" class="action-icon-button" aria-label="<?= $templateArchived?'Restore':'Archive' ?> <?= $h($t['name']) ?>" data-tooltip="<?= $templateArchived?'Restore':'Archive' ?>"><?= actionIconSvg($templateArchived?'restore':'archive') ?></button></form>
                <?php if($templateArchived&&$canDelete): ?><form method="post" data-inbox-template-delete-form data-admin-unlock-required data-confirm="Permanently delete this template? This cannot be undone. Conversation history will be kept."><?php $form('inbox_template_operation',['operation'=>'delete','id'=>$t['id'],'version'=>$t['version']]); ?><button type="submit" class="action-icon-button" data-admin-unlock-required aria-label="Permanently delete <?= $h($t['name']) ?>" data-tooltip="Permanently delete (Admin Unlock required)"><?= actionIconSvg('delete') ?></button></form><?php endif; ?>
            </footer>
        </article>
        <?php endforeach; ?>
    </div>
    <?php if(!$templateRows): ?><div class="inbox-list-empty"><div class="inbox-empty-icon"><?php $icon('note'); ?></div><h2><?= $templateArchived?'No archived templates':'A helpful reply, ready when you need it' ?></h2><p><?= $templateArchived?'Templates you archive will appear here. You can restore them whenever you need them.':'Create your first template for common questions and follow-ups.' ?></p><?php if(!$templateArchived): ?><a class="button primary" href="<?= $h($url(['panel'=>'templates','new'=>1])) ?>">Create a template</a><?php endif; ?></div><?php endif; ?>
</section>
<?php elseif($kind==='label'&&!$edit&&!isset($_GET['new'])): ?>
<?php include __DIR__.'/inbox-labels.php'; ?>
<?php else: ?>
<h1><?= $kind==='label'?'Labels':($edit?'Edit message template':'New message template') ?></h1><p><?= $kind==='label'?'Organize conversations with labels.':'Save reusable replies. Personalize with *|FNAME|*, *|LNAME|*, *|EMAIL|*, and *|LIST:COMPANY|*.' ?></p>
<?php if($edit||isset($_GET['new'])): ?><form method="post" class="inbox-edit-form"><?php $form('inbox_named_save',['kind'=>$kind,'id'=>$edit['id']??0,'version'=>$edit['version']??0]); ?><label>Name<input name="name" maxlength="100" required value="<?= $h($edit['name']??'') ?>"></label><?php if($kind==='template'): ?><label>Subject<input name="subject" maxlength="250" value="<?= $h($edit['subject']??'') ?>"></label><?php $rich($edit['body_html']??''); ?><?php endif; ?><div class="inbox-actions"><a class="button" href="<?= $h($url(['panel'=>$panel])) ?>">Cancel</a><button class="primary">Save</button></div></form>
<?php else: ?><a class="button primary" href="<?= $h($url(['panel'=>$panel,'new'=>1])) ?>">Add label</a><?php if(!$rows): ?><p class="inbox-empty-copy">You have no labels yet.</p><?php endif; ?><ul class="inbox-manage-list"><?php foreach($rows as$r): ?><li><a href="<?= $h($url(['panel'=>$panel,'edit'=>(int)$r['id']])) ?>"><?= $h($r['name']) ?></a><form method="post" data-confirm="Remove this label? Conversation history will be kept."><?php $form('inbox_named_remove',['kind'=>$kind,'id'=>$r['id'],'version'=>$r['version']]); ?><button>Remove</button></form></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>
<?php elseif($panel==='notifications'): ?>
<h1>Notification settings</h1><p>Choose how you receive new conversation and internal comment alerts. These preferences apply only to you.</p><form method="post" class="inbox-settings"><?php $form('inbox_notifications',['version'=>$preferences['version']]); ?><label class="inbox-check"><input type="checkbox" name="browser_alerts" value="1"<?= $preferences['browser_alerts']?' checked':'' ?>>Browser notifications</label><p>Browser alerts appear while Inbox is open. Your browser may ask for permission.</p><p class="inbox-browser-permission" data-inbox-permission-status role="status" hidden></p><label class="inbox-check"><input type="checkbox" name="email_alerts" value="1"<?= $preferences['email_alerts']?' checked':'' ?>>Email notifications</label><p>Email alerts go to your verified profile email address and contain a link to Inbox. Email delivery must be enabled for this account.</p><p class="inbox-notification-recipient"><?php if(!empty($notificationRecipient['email'])): ?>Send to: <strong><?= $h($notificationRecipient['email']) ?></strong><span><?= !empty($notificationRecipient['email_verified_at'])?'Verified':'Email verification required' ?></span><?php else: ?>Add and verify a profile email address to receive email alerts.<?php endif; ?> <a href="profile.php">Manage profile email</a></p><div class="inbox-actions"><button class="primary">Save preferences</button><button type="button" data-inbox-notification-permission>Allow browser alerts</button></div></form>
<?php elseif($draftWorkspace||$panel==='compose'): ?>
<?php include __DIR__.'/inbox-compose.php'; ?>
<?php elseif($thread): ?>
<?php include __DIR__.'/inbox-conversation.php'; ?>
<?php else: ?>
<?php include __DIR__.'/inbox-selection-empty.php'; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../app-footer.php'; ?>
</main></div>
<dialog id="inbox-action-dialog" class="confirmation-dialog" aria-labelledby="inbox-action-title" aria-describedby="inbox-action-message">
<form method="dialog"><h2 id="inbox-action-title">Confirm action</h2><p id="inbox-action-message"></p>
<label id="inbox-link-field" hidden>Link URL<input id="inbox-link-url" class="confirmation-dialog-input" type="text" maxlength="2000" placeholder="https://example.com" aria-describedby="inbox-link-error"></label>
<p id="inbox-link-error" class="dialog-inline-error" role="alert" hidden>Enter an HTTP, HTTPS, or email link without spaces.</p>
<div class="confirmation-dialog-actions"><button type="button" data-inbox-dialog-cancel class="button-secondary">Cancel</button><button class="save-button" value="continue">Continue</button></div></form></dialog>
<?php include __DIR__.'/../admin-unlock-dialog.php'; ?>
<form hidden id="inbox-csrf"><?= $csrf ?></form><script type="application/json" id="inbox-config"><?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script><script type="application/json" id="inbox-templates"><?= json_encode($templates,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script><?php include __DIR__.'/mobile-navigation.php'; ?></body>
