<?php
function audienceInputs(array $values,string $prefix=''):void{
    foreach($values as$key=>$value){$name=$prefix===''?(string)$key:$prefix.'['.$key.']';if(is_array($value))audienceInputs($value,$name);else echo '<input type="hidden" name="'.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">';}
}
function audienceFieldControl(array $field,mixed $value):void{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$name='custom['.$field['id'].']';$fid='person-field-'.$field['id'];$type=$field['field_type'];$options=audienceJson($field['options_json']);
    if($type==='address'){
        if(is_string($value)&&substr_count($value,"\n")>5){
            echo '<label for="'.$fid.'">'.$h($field['label']).'</label><textarea id="'.$fid.'" name="'.$name.'" rows="6"'.($field['required']?' required':'').'>'.$h($value).'</textarea><p class="audience-help">Original imported address formatting is preserved.</p>';return;
        }
        $parts=is_array($value)?$value:array_combine(['address1','address2','city','region','postal_code','country'],array_pad(explode("\n",str_replace("\r",'',(string)$value),6),6,''));
        echo '<fieldset class="address-fields"><legend>'.$h($field['label']).($field['required']?' *':'').'</legend><div class="form-grid">';
        foreach(['address1'=>'Street Address','address2'=>'Address Line 2','city'=>'City','region'=>'State/Prov/Region','postal_code'=>'Postal/Zip','country'=>'Country']as$key=>$label){
            echo '<div'.(in_array($key,['address1','address2'],true)?' class="span-two"':'').'><label for="'.$fid.'-'.$key.'">'.$label.'</label><input id="'.$fid.'-'.$key.'" name="'.$name.'['.$key.']" value="'.$h($parts[$key]??'').'" maxlength="300"'.($key==='address1'&&$field['required']?' required':'').'></div>';
        }
        echo '</div></fieldset>';return;
    }
    if(in_array($type,['checkboxes','radio'],true))echo '<fieldset class="custom-choice-field"><legend>'.$h($field['label']).($field['required']?' *':'').'</legend>';
    else echo '<label for="'.$fid.'">'.$h($field['label']).($field['required']?' <span aria-label="required">*</span>':'').'</label>';
    if($type==='checkboxes'){echo '<input type="hidden" name="'.$name.'" value=""><div class="choice-list">';foreach($options as$index=>$option)echo '<label class="audience-check"><input type="checkbox" name="'.$name.'[]" value="'.$h($option).'"'.(in_array($option,is_array($value)?$value:audienceParseListCell((string)$value),true)?' checked':'').'>'.$h($option).'</label>';echo '</div>';}
    elseif($type==='radio'){echo '<div class="choice-list">';foreach($options as$index=>$option)echo '<label class="audience-check"><input type="radio" id="'.$fid.'-'.$index.'" name="'.$name.'" value="'.$h($option).'"'.($value===$option?' checked':'').($field['required']?' required':'').'>'.$h($option).'</label>';if(!$field['required'])echo '<label class="audience-check"><input type="radio" name="'.$name.'" value=""'.($value===''?' checked':'').'>None</label>';echo '</div>';}
    elseif($type==='dropdown'){echo '<select id="'.$fid.'" name="'.$name.'"'.($field['required']?' required':'').'><option value="">Choose…</option>';foreach($options as$option)echo '<option value="'.$h($option).'"'.($value===$option?' selected':'').'>'.$h($option).'</option>';echo '</select>';}
    else{$htmlType=match($type){'number'=>'number','date'=>'date','url'=>'url','phone'=>'tel',default=>'text'};echo '<input id="'.$fid.'" name="'.$name.'" type="'.$htmlType.'" value="'.$h(is_array($value)?implode(' | ',$value):$value).'"'.($type==='number'?' step="any"':'').($type==='birthday'?' placeholder="MM-DD" pattern="[0-9]{2}-[0-9]{2}"':'').($field['required']?' required':'').'>';}
    if(in_array($type,['checkboxes','radio'],true))echo '</fieldset>';
}
// Reference navigation help, adapted only for Connections wording and omitted channels.
function audienceTooltip(string $label):string {
    $help=[
        'Home'=>'View a snapshot of your recent campaign performance and audience growth.',
        'Campaigns'=>'View and manage your emails and landing pages.',
        'Automations'=>'Map out an email automation flow that delivers a unique experience to each of your connections.',
        'Forms'=>'Build signup forms, landing pages, and popups to collect connections.',
        'Audience'=>'View a list of all the connections in your audience.',
        'Tags'=>'Tag your connections to organize them however you need to.',
        'Segments'=>'Filter connections that meet certain criteria so you can send them targeted messages.',
        'Surveys'=>'Get insights by collecting feedback from your audience.',
        'Subscriber preferences'=>'Create a way for your connections to manage their preferences.',
        'Inbox'=>'Manage 1-1 conversations and responses from your audience.',
        'Analytics'=>'Track the performance of your email campaigns with near real-time analytics.',
        'Ask Analytics AI'=>'Ask questions about campaign performance, audience growth, and delivery using your MessageMonkey data.',
        'Write with AI'=>'Draft and refine email content, adjust tone, and explore subject lines with AI.',
        'Content'=>'Upload and manage creative assets to use in your campaigns.',
    ];
    return ' data-audience-tooltip="'.htmlspecialchars($help[$label]??$label,ENT_QUOTES,'UTF-8').'"';
}
$baseForm=[];
$isFlowView=in_array($view,['automations','flow','flow-templates','flow-report'],true);
$isCollectionView=in_array($view,['forms','form','form-settings','landing-pages','surveys','collection-report','preferences','collection-create'],true);
$collectionKind=$resource['kind']??($view==='collection-create'?($creationKind??''):null);
$isFormsView=($view==='collection-create'&&!in_array($creationKind??'',['survey','landing','preferences'],true))||in_array($view,['forms','form-settings'],true)||($isCollectionView&&isset($resource)&&in_array($resource['kind'],['hosted','embedded','popup'],true));
$isCampaignView=$view==='landing-pages'||($isCollectionView&&$collectionKind==='landing')||in_array($view,['campaigns','campaign','templates','campaign-manager','campaign-plan','campaign-review','campaign-send','campaign-delivery','delivery'],true);$isAiAnalytics=$view==='assistant'&&($aiMode??'content')==='analytics';$isAiWriting=$view==='assistant'&&!$isAiAnalytics;$isContentView=in_array($view,['content','brand'],true)||$isAiWriting;
$analyticsAiQuery=['view'=>'assistant','mode'=>'analytics'];
if(in_array($view,['home','analytics'],true)){
    $analyticsAiQuery+=array_intersect_key($window,array_flip(['start','end','compare','bots','channel','messages_mode','messages','segment']));
    if($view==='analytics')$analyticsAiQuery+=['dataset'=>$definition['dataset']??($section==='audience'?'audience':($section==='conversions'?'forms':'email')),'source'=>$source];
}
?>
<body class="audience-app<?= $view==='form'?' audience-collection-editor-mode'.(($resource['kind']??'')==='embedded'?' audience-embedded-editor-mode':''):'' ?><?= $isFlowView?' audience-flow-workspace':'' ?><?= $isAiAnalytics?' audience-analytics-ai-mode':'' ?><?= $isAiWriting?' audience-writing-ai-mode':'' ?>" data-audience-key="<?= $h($themeKey) ?>">
<?php include __DIR__.'/../deployment-notice.php'; ?>
<?php include __DIR__.'/../account-identity-banner.php'; ?>
<?php include __DIR__.'/../admin-unlock-banner.php'; ?>
<?php include __DIR__.'/../role-preview-banner.php'; ?>
<a class="audience-skip" href="#audience-content">Skip to main content</a>
<header class="audience-topbar">
    <button type="button" class="audience-mobile-menu-button" data-audience-nav-toggle aria-controls="audience-sidebar" aria-expanded="false" aria-label="Open MessageMonkey navigation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <div class="audience-brand"><a class="audience-logo-wrap" href="<?= $h($url(['view'=>'home'])) ?>" data-audience-logo data-dashboard-url="dashboard.php" aria-label="MessageMonkey Home" title="MessageMonkey Home (Ctrl-click for MOED dashboard)"><?php readfile(__DIR__.'/../../assets/audience-logo-transparent.svg'); ?></a><span>MOED MessageMonkey</span></div>
    <button type="button" class="audience-global-search" data-global-search-open aria-label="Search MessageMonkey" aria-haspopup="dialog" aria-controls="audience-search"><?php audienceNavIcon('search'); ?><span>Search...</span></button>
    <?php include __DIR__.'/account-tools.php'; ?>
</header>
<button type="button" class="audience-sidebar-backdrop" data-audience-nav-backdrop aria-label="Close MessageMonkey navigation" tabindex="-1" hidden></button>
<aside id="audience-sidebar" class="audience-sidebar" aria-label="MessageMonkey navigation">
    <button type="button" class="audience-sidebar-close" data-audience-nav-close>Close Navigation</button>
    <?php if($canManage): ?><a aria-label="Create" class="audience-create" href="<?= $h($url(['view'=>'campaigns','create'=>1])) ?>"<?= in_array($view,['campaigns','campaign'],true)?' data-open-dialog="campaign-create"':'' ?>><?php audienceSidebarIcon('create'); ?><span>Create</span></a><?php endif; ?>
    <nav>
        <?php foreach(['Home'=>'home','Campaigns'=>'campaigns','Automations'=>'automations','Forms'=>'forms']as$label=>$icon): ?><?php if($label==='Home'): ?><a aria-label="Home" class="audience-nav-item<?= $view==='home'?' selected':'' ?>" href="<?= $h($url(['view'=>'home'])) ?>"<?= $view==='home'?' aria-current="page"':'' ?><?= audienceTooltip('Home') ?>><?php audienceSidebarIcon('home'); ?><span>Home</span></a><?php continue; endif; ?><?php if($label==='Automations'): ?><a aria-label="Automations" class="audience-nav-item<?= $isFlowView?' selected':'' ?>" href="<?= $h($url(['view'=>'automations'])) ?>"<?= $view==='automations'?' aria-current="page"':'' ?><?= audienceTooltip('Automations') ?>><?php audienceSidebarIcon('automations'); ?><span>Automations</span></a><?php if($isFlowView): ?><div class="audience-subnav"><a href="<?= $h($url(['view'=>'flow-templates'])) ?>"<?= $view==='flow-templates'?' aria-current="page"':'' ?>>Flow templates</a></div><?php endif; ?><?php continue; endif; ?><?php if($label==='Forms'): ?><a aria-label="Forms" class="audience-nav-item<?= $isFormsView?' selected':'' ?>" href="<?= $h($url(['view'=>'forms'])) ?>"<?= $isFormsView?' aria-current="page"':'' ?><?= audienceTooltip('Forms') ?>><?php audienceSidebarIcon('forms'); ?><span>Forms</span></a><?php continue; endif; ?><?php if($label==='Campaigns'): ?><a aria-label="Campaigns" class="audience-nav-item<?= $isCampaignView?' selected':'' ?>" href="<?= $h($url(['view'=>'campaigns'])) ?>"<?= $isCampaignView?' aria-current="page"':'' ?><?= audienceTooltip('Campaigns') ?>><?php audienceSidebarIcon('campaigns'); ?><span>Campaigns</span></a><?php if($isCampaignView): ?><div class="audience-subnav campaign-subnav"><a href="<?= $h($url(['view'=>'landing-pages'])) ?>"<?= $view==='landing-pages'||($isCollectionView&&$collectionKind==='landing')?' aria-current="page"':'' ?>>Landing pages</a><a href="<?= $h($url(['view'=>'campaign-manager'])) ?>"<?= in_array($view,['campaign-manager','campaign-plan'],true)?' aria-current="page"':'' ?>>Campaign Manager</a><a href="<?= $h($url(['view'=>'templates','tab'=>'saved'])) ?>"<?= $view==='templates'?' aria-current="page"':'' ?>>Email templates</a><?php if($canDelete): ?><a href="<?= $h($url(['view'=>'delivery'])) ?>"<?= $view==='delivery'?' aria-current="page"':'' ?>>Email delivery</a><?php endif; ?></div><?php endif; ?><?php continue; endif; ?><span class="audience-nav-item unavailable" aria-label="<?= $h($label) ?>" aria-disabled="true" tabindex="0"<?= audienceTooltip($label) ?>><?php audienceSidebarIcon($icon); ?><span><?= $label ?></span></span><?php endforeach; ?>
        <a aria-label="Audience" class="audience-nav-item<?= !in_array($view,['home','analytics','assistant'],true)&&!$isCampaignView&&!$isContentView&&!$isAiAnalytics&&!$isFormsView&&!$isFlowView?' selected':'' ?>"<?= audienceTooltip('Audience') ?> href="<?= $h($url()) ?>"<?= in_array($view,['contacts','contact'],true)?' aria-current="page"':'' ?>><?php audienceSidebarIcon('audience'); ?><span>Audience</span></a>
        <div class="audience-subnav"<?= in_array($view,['home','analytics'],true)||$isCampaignView||$isContentView||$isAiAnalytics||$isFormsView||$isFlowView?' hidden':'' ?>>
            <?php foreach(['tags'=>'Tags','segments'=>'Segments']as$key=>$label): ?><a href="<?= $h($url(['view'=>$key])) ?>"<?= audienceTooltip($label) ?><?= $view===$key?' aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?>
            <?php foreach(['surveys'=>'Surveys','preferences'=>'Subscriber preferences']as$key=>$label): ?><a href="<?= $h($url(['view'=>$key])) ?>"<?= $view===$key||($isCollectionView&&$collectionKind===($key==='surveys'?'survey':'preferences'))?' aria-current="page"':'' ?><?= audienceTooltip($label) ?>><?= $label ?></a><?php endforeach; ?><?php if($canManage): ?><a href="<?= $h($url(['view'=>'inbox'])) ?>"<?= audienceTooltip('Inbox') ?>>Inbox</a><?php endif; ?>
        </div>
        <a class="audience-nav-item<?= $isContentView&&!$isAiWriting?' selected':'' ?>" href="<?= $h($url(['view'=>'content'])) ?>" aria-label="Content"<?= audienceTooltip('Content') ?>><?php audienceSidebarIcon('content-studio'); ?><span>Content</span></a><?php if(in_array($view,['content','brand'],true)): ?><div class="audience-subnav"><a href="<?= $h($url(['view'=>'content'])) ?>"<?= $view==='content'?' aria-current="page"':'' ?>>My files</a><a href="<?= $h($url(['view'=>'brand'])) ?>"<?= $view==='brand'?' aria-current="page"':'' ?>>Brand Kit</a></div><?php endif; ?><a class="audience-nav-item<?= $view==='analytics'?' selected':'' ?>" href="<?= $h($url(['view'=>'analytics'])) ?>" aria-label="Analytics"<?= $view==='analytics'?' aria-current="page"':'' ?><?= audienceTooltip('Analytics') ?>><?php audienceSidebarIcon('analytics'); ?><span>Analytics</span></a><?php if($view==='analytics'): ?><div class="audience-subnav"><a href="<?= $h($url(['view'=>'analytics'])) ?>"<?= $section==='email'?' aria-current="page"':'' ?>>Email dashboard</a><a href="<?= $h($url(['view'=>'analytics','section'=>'audience'])) ?>"<?= $section==='audience'?' aria-current="page"':'' ?>>Audience</a><a href="<?= $h($url(['view'=>'analytics','section'=>'conversions'])) ?>"<?= $section==='conversions'?' aria-current="page"':'' ?>>Conversion insights</a><?php foreach(['reports'=>'Custom reports','activity'=>'Recipient activity','links'=>'Link performance','devices'=>'Locations and clients','insights'=>'Benchmarks and timing']as$reportSection=>$label): ?><a href="<?= $h($url(['view'=>'analytics','section'=>$reportSection])) ?>"<?= $section===$reportSection||($reportSection==='reports'&&$section==='custom')?' aria-current="page"':'' ?>><?= $h($label) ?></a><?php endforeach; ?></div><?php endif; ?>
        <a class="audience-nav-item<?= $isAiAnalytics?' selected':'' ?>" href="<?= $h($url($analyticsAiQuery)) ?>" aria-label="Ask Analytics AI"<?= $isAiAnalytics?' aria-current="page"':'' ?><?= audienceTooltip('Ask Analytics AI') ?>><?php audienceSidebarIcon('analytics-ai'); ?><span>Ask Analytics AI</span></a>
        <?php if($canManage): ?><a class="audience-nav-item<?= $isAiWriting?' selected':'' ?>" href="<?= $h($url(['view'=>'assistant','mode'=>'content'])) ?>" aria-label="Write with AI"<?= $isAiWriting?' aria-current="page"':'' ?><?= audienceTooltip('Write with AI') ?>><?php audienceSidebarIcon('writing-ai'); ?><span>Write with AI</span></a><?php endif; ?>
    </nav>
    <div class="audience-sidebar-footer"><a class="audience-return" href="dashboard.php" aria-label="Return to MOED dashboard" data-audience-tooltip="Return to MOED dashboard"><span aria-hidden="true">←</span><span class="audience-return-label">Return to MOED dashboard</span></a>
    <button type="button" class="audience-sidebar-toggle" data-sidebar-toggle aria-controls="audience-sidebar" aria-expanded="true" aria-label="Minimize navigation" title="Minimize navigation"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 5h6v14H4z" fill="var(--audience-primary)"/><path d="M10 4v16" stroke="currentColor" stroke-width="2"/></svg></button></div>
</aside>
<main id="audience-content" class="audience-content">
    <div class="audience-page-heading<?= $view==='home'?' analytics-home-heading':($view==='campaigns'?' campaign-list-heading':'') ?>">
        <?php if($view==='flow-templates'&&!empty($flowPreview)): ?><div class="flow-preview-heading"><a href="<?= $h($url(['view'=>'flow-templates'])) ?>" data-flow-catalog-back aria-label="Back to flow templates">←</a><div><nav class="flow-preview-breadcrumbs" aria-label="Breadcrumbs"><a href="<?= $h($url(['view'=>'automations'])) ?>">Automation flows</a><span>/</span><a data-flow-catalog-back href="<?= $h($url(['view'=>'flow-templates'])) ?>">Flow templates</a></nav><h1><?= $h($pageTitle) ?></h1></div></div><?php else: ?><div<?= $view==='form'?' class="collection-editor-heading"':'' ?>>
            <?php if($view==='form'): ?><a class="audience-logo-wrap collection-editor-logo" href="<?= $h($url(['view'=>'home'])) ?>" data-audience-logo data-dashboard-url="dashboard.php" aria-label="MessageMonkey Home" title="MessageMonkey Home (Ctrl-click for MOED dashboard)"><?php readfile(__DIR__.'/../../assets/audience-logo-transparent.svg'); ?></a><?php endif; ?>
            <h1><?= $h($pageTitle) ?><?php if($isAiAnalytics): ?><span class="analytics-ai-beta">Beta</span><?php endif; ?></h1>
        </div><?php endif; ?>
        <?php if($view==='home'): ?><div class="analytics-home-date-tools"><?php include __DIR__.'/analytics-filters.php'; ?></div><?php endif; ?>
        <div class="audience-actions">
        <?php if($isFlowView): ?>
        <?php if($canManage&&$view==='flow-templates'&&$flowPreview): ?><form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'flow_create','template'=>$flowPreview,'name'=>audienceFlowTemplates()[$flowPreview]['name']]); ?><button class="primary">Use this flow</button></form>
        <?php elseif($canManage&&in_array($view,['automations','flow-templates'],true)): ?><button data-open-dialog="flow-create">Build from scratch</button><?php if($view==='automations'): ?><a class="audience-button flow-template-choice" href="<?= $h($url(['view'=>'flow-templates'])) ?>">Choose flow template</a><?php endif; ?>
        <?php elseif($flow&&!empty($flow['deleted_at'])): ?><span class="campaign-status">Archived</span><a class="audience-button" href="<?= $h($url(['view'=>'automations','trash'=>'1'])) ?>">Back to Archived</a><?php if($canManage): ?><form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'flow_operation','id'=>$id,'version'=>$flow['version'],'operation'=>'restore']); ?><button>Restore as draft</button></form><?php endif; ?><?php if($canDelete): ?><button type="button" data-flow-lifecycle="delete" data-flow-record='<?= $h(json_encode(array_intersect_key($flow,array_flip(['id','name','version'])),JSON_THROW_ON_ERROR)) ?>' data-open-dialog="flow-delete" data-admin-unlock-required>Delete permanently</button><?php endif; ?>
        <?php elseif($canManage&&$flow): ?>
        <span class="campaign-status"><?= $flow['state']==='running'?'Active':$h(ucfirst($flow['state'])) ?></span>
        <?php if($flow['state']==='running'): ?><form method="post" data-confirm="Pause this flow? Connections keep their positions while it is paused."><?= $csrf ?><?php audienceInputs(['action'=>'flow_operation','id'=>$id,'version'=>$flow['version'],'operation'=>'pause']); ?><button>Pause &amp; Edit</button></form>
        <?php else: ?><?php if($view==='flow'): ?><button type="button" class="audience-history-action" data-flow-undo aria-label="Undo" title="Undo"><?php audienceNavIcon('undo'); ?></button><button type="button" class="audience-history-action" data-flow-redo aria-label="Redo" title="Redo"><?php audienceNavIcon('redo'); ?></button><button type="button" data-flow-save>Save</button><button type="button" class="primary" data-flow-review><?= $flow['state']==='paused'?'Turn back on':'Continue' ?></button><?php else: ?><a class="audience-button" href="<?= $h($url(['view'=>'flow','id'=>$id])) ?>">Edit flow</a><?php endif; ?><?php endif; ?><button type="button" data-flow-lifecycle="archive" data-flow-record='<?= $h(json_encode(array_intersect_key($flow,array_flip(['id','name','version'])),JSON_THROW_ON_ERROR)) ?>' data-open-dialog="flow-archive">Archive</button>
        <?php endif; ?>
        <?php elseif($isCollectionView): require __DIR__.'/collection-actions.php'; ?>
        <?php elseif($view==='home'&&$canManage): ?><details class="audience-menu"><summary class="audience-home-hover">Quick actions <span aria-hidden="true">⌄</span></summary><div><a href="<?= $h($url(['view'=>'import'])) ?>">Import connections</a><a href="<?= $h($url(['view'=>'contact'])) ?>">Add a single connection</a><a href="<?= $h($url(['view'=>'automations'])) ?>">Create automation</a></div></details><a class="audience-button primary" href="<?= $h($url(['view'=>'campaigns','create'=>1])) ?>">Create email</a><?php elseif($view==='campaigns'): include __DIR__.'/campaign-list-heading.php'; ?>
        <?php elseif($view==='templates'&&$canManage): ?><button type="button" data-open-dialog="template-package-import">Import template</button><button type="button" data-template-legacy>Create a legacy template</button><button type="button" data-template-import>Code your own</button><form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'template_create','starter'=>'blank']); ?><button class="primary">Create from scratch</button></form>
        <?php elseif(in_array($view,['campaign-manager','campaign-plan'],true)&&$canManage): ?><button data-open-dialog="campaign-plan-editor"><?= $view==='campaign-plan'?'Campaign settings':'Create campaign' ?></button><button class="primary" data-touchpoint-new>Add touchpoint</button>
        <?php elseif($view==='campaign'): ?><button type="button" data-open-dialog="campaign-tracking" aria-label="Settings and tracking">Settings &amp; Tracking</button><button type="button" data-open-dialog="campaign-team-share">Share</button><a class="audience-button" href="<?= $h($url(['view'=>'campaigns'])) ?>">Finish later</a><?php if($campaign['status']==='draft'&&($canSend??false)): ?><a class="audience-button primary" href="<?= $h($url(['view'=>'campaign-send','id'=>$id])) ?>"><?= ($campaign['draft']['send_time']['mode']??'now')==='schedule'?'Schedule':'Send' ?></a><?php elseif(!empty($delivery)): ?><a class="audience-button primary" href="<?= $h($url(['view'=>'campaign-delivery','id'=>$id])) ?>">View delivery</a><?php endif; ?>
        <?php elseif($view==='contacts'): ?>
            <details class="audience-menu"><summary aria-label="Manage audience">•••</summary><div>
                <?php foreach(['settings'=>'Audience settings','fields'=>'Fields and merge tags','groups'=>'Groups']as$v=>$label): ?><a href="<?= $h($url(['view'=>$v])) ?>"><?= $label ?></a><?php endforeach; ?>
                <a href="<?= $h($url(['archived'=>'1'])) ?>">Archived connections</a>
                <?php if($canManage): ?><a href="<?= $h($url(['view'=>'jobs','kind'=>'import'])) ?>">Import history</a><a href="<?= $h($url(['view'=>'jobs','kind'=>'export'])) ?>">Export history</a><button type="button" data-open-dialog="unsubscribe-emails">Unsubscribe emails</button><?php endif; ?>
            </div></details>
            <?php if($canManage): ?><details class="audience-menu primary"><summary>Add connections <span aria-hidden="true">⌄</span></summary><div><a href="<?= $h($url(['view'=>'import'])) ?>">Import connections</a><a href="<?= $h($url(['view'=>'contact'])) ?>" data-open-dialog="add-person">Add a single connection</a></div></details><?php endif; ?>
        <?php elseif($view==='tags'&&$canManage): ?><button data-open-dialog="bulk-tag">Bulk tag</button><button class="primary" data-tag-edit='{"id":0,"version":0,"name":""}'>Create new tag</button>
        <?php elseif($view==='groups'&&$canManage): ?><button class="primary" data-group-edit='{"id":0,"version":0,"name":"","display_type":"checkboxes","options":[]}'>Create group</button>
        <?php elseif($view==='fields'&&$canManage): ?><button class="primary" data-field-edit='{"id":0,"version":0,"field_type":"text","is_public":1}'>Create a new field</button>
        <?php elseif($view==='segments'&&$canManage): ?><button class="primary" data-segment-new>Create segment</button>
        <?php elseif($view==='contact'&&$id): ?>
            <details class="audience-menu primary"><summary>Actions ⌄</summary><div>
                <?php if($canExport): ?><a href="<?= $h($url(['view'=>'contact','id'=>$id,'export'=>'person'])) ?>">Export</a><?php endif; ?>
                <?php if($canManage): ?><button data-person-operation="<?= $contact['is_archived']?'restore':'archive' ?>"><?= $contact['is_archived']?'Unarchive':'Archive' ?></button><button data-person-operation="unsubscribe">Unsubscribe (Email)</button><?php if($canDelete): ?><button class="danger-text" data-person-operation="delete">Permanently delete</button><?php endif; ?><?php endif; ?>
            </div></details>
        <?php endif; ?>
        </div>
    </div>
    <div class="audience-page-body">
    <?php if($message!==''): ?><p class="audience-notice success" role="status"><?= $h($message) ?></p><?php endif; ?>
    <?php if($error!==''): ?><p class="audience-notice error" role="alert" tabindex="-1" data-operation-error><?= $h($error) ?></p><?php endif; ?>
    <?php include __DIR__.'/'.match($view){'assistant'=>'ai.php','home'=>'analytics.php','analytics'=>'analytics-dashboard.php','automations','flow','flow-templates','flow-report'=>'flows.php','forms','form','form-settings','landing-pages','surveys','collection-report','preferences','collection-create'=>'collection.php','templates'=>'email-templates.php','campaigns','campaign'=>'campaigns.php','campaign-manager','campaign-plan'=>'campaign-manager.php','campaign-review'=>'campaign-review.php','campaign-send','campaign-delivery'=>'campaign-delivery.php','content','brand'=>'content.php','delivery'=>'delivery-setup.php','contact'=>'person.php','tags','groups','fields','settings'=>'manage.php','segments'=>'segments.php','import','jobs','job'=>'imports.php',default=>'people.php'}; ?>
    </div>
    <?php if (!$isAiAnalytics&&!$isAiWriting) include __DIR__ . '/../app-footer.php'; ?>
</main>
<?php include __DIR__.'/search.php'; ?>
<?php if($canManage&&!in_array($view,['home','analytics','assistant'],true)&&!$isCampaignView&&!$isContentView&&!$isCollectionView&&!$isFlowView)include __DIR__.'/dialogs.php'; ?>
<?php if($canManage&&$view==='contacts'): $personModal=true; ?>
<dialog id="add-person" class="audience-wide-dialog" aria-labelledby="add-person-title"><div class="dialog-heading"><h2 id="add-person-title">Add a connection</h2><button type="button" data-close-dialog aria-label="Close">×</button></div><?php include __DIR__.'/person-form.php'; ?></dialog>
<?php endif; ?>
<script type="application/json" id="audience-config"><?= json_encode(['recovery'=>$editorRecovery??null,'canDelete'=>$canDelete,'audience'=>$audienceId,'url'=>$url(),'catalog'=>$catalog,'operators'=>array_combine(['text','number','date','choice','membership','set','event','engagement'],array_map('audienceRuleOperators',['text','number','date','choice','membership','set','event','engagement'])),'rules'=>$rules,'currentRules'=>audienceActiveRules($filters,$rules),'listContext'=>$view==='contacts'?['archived'=>$filters['archived'],'sort'=>$sort,'direction'=>$direction,'per_page'=>$pageSize]:[],'segment'=>$segment,'csrf'=>generateCsrfToken(),'job'=>$job?array_intersect_key($job,array_flip(['id','state','total','processed','job_type'])):null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<?php include __DIR__.'/../admin-unlock-dialog.php'; ?>
</body>
<?php
/** Sidebar icons use the reference normal/active pairs; action icons stay independent. */
function audienceSidebarIcon(string $name): void
{
    $icons = ['create', 'home', 'campaigns', 'automations', 'forms', 'audience', 'content-studio', 'analytics', 'analytics-ai', 'writing-ai'];
    if (!in_array($name, $icons, true)) {
        throw new InvalidArgumentException('Unknown sidebar icon.');
    }
    echo '<span class="audience-sidebar-icon" data-sidebar-icon="' . $name . '" aria-hidden="true"></span>';
}

function audienceNavIcon(string $name): void
{
    $paths = [
        'list' => '<path d="M8 5h13M8 12h13M8 19h13M3 5h1M3 12h1M3 19h1"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M7 2v6M17 2v6"/>',
        'chevron' => '<path d="m7 10 5 5 5-5"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'undo' => '<path d="M3 10h11a7 7 0 0 1 7 7M3 10l6-6m-6 6 6 6"/>',
        'redo' => '<path d="M21 10H10a7 7 0 0 0-7 7m18-7-6-6m6 6-6 6"/>',
        'external' => '<path d="M14 3h7v7M21 3l-9 9M10 5H4v16h16v-6"/>',
        'plus-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/>',
        'filters' => '<path d="M3 7h10m4 0h4M3 17h4m4 0h10"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
        'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'export' => '<path d="M12 3H4v18h16v-8M10 15l10-10M14 5h6v6"/>',
        'rename' => '<path d="m4 16 12-12 4 4L8 20l-5 1ZM14 6l4 4"/>',
        'copy' => '<rect x="3" y="3" width="14" height="14" rx="2"/><path d="M8 17v4h13V8h-4"/>',
        'trash' => '<path d="M3 6h18M9 6V3h6v3M6 6v15h12V6M10 10v7M14 10v7"/>',
        'text' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 9V7h10v2M12 7v10M9 17h6"/>',
        'testing' => '<path d="M2 3h8v12H2Zm12 0h8v12h-8ZM4 18l4 4M8 18l-4 4M15 20l2 2 5-5"/>',
        'grip' => '<g fill="currentColor" stroke="none"><circle cx="9" cy="5" r="2"/><circle cx="15" cy="5" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="9" cy="19" r="2"/><circle cx="15" cy="19" r="2"/></g>',
        'home' => '<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3Z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 6 9 7 9-7"/>',
        'flow' => '<rect x="2" y="3" width="7" height="5" rx="1"/><rect x="15" y="16" width="7" height="5" rx="1"/><path d="M6 8v10h9M6 12h13V4h-5"/>',
        'sms' => '<path d="M21 11a9 9 0 0 1-9 9H3l2-5a9 9 0 1 1 16-4Z"/><path d="M8 8h8M8 12h6"/>',
        'form' => '<rect x="3" y="5" width="18" height="14"/><path d="M7 8h10M7 12h7M7 16h4"/>',
        'audience' => '<circle cx="8" cy="7" r="3"/><circle cx="17" cy="8" r="2"/><path d="M2 21v-5a6 6 0 0 1 12 0v5Zm12-8a5 5 0 0 1 8 4v4h-5"/>',
        'chart' => '<path d="M3 21V12h4v9Zm7 0V7h4v14Zm7 0V2h4v19Z"/>',
        'web' => '<rect x="3" y="3" width="18" height="18"/><path d="M3 8h18M7 12h10M7 16h7"/>',
        'content' => '<rect x="2" y="6" width="15" height="13"/><path d="M6 6V2h16v14h-5M4 17l4-5 3 3 3-2 3 4"/>',
        'grid' => '<path d="M3 3h7v7H3Zm11 0h7v7h-7ZM3 14h7v7H3Zm11 0h7v7h-7Z"/>',
    ];
    echo '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
}

?>
