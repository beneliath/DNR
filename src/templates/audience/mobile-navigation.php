<?php
// Mobile navigation uses the same routes and permission checks as the workspace.
$mobileView = isset($designerConfig) ? 'designer' : ($view ?? 'inbox');
$mobileCanManage = $canManage ?? false;
$mobileCampaignViews = ['campaigns','campaign','campaign-manager','campaign-plan','campaign-review','campaign-send','campaign-delivery','templates','designer'];
$mobileAudienceViews = ['contacts','contact','tags','segments','groups','fields','import','settings'];
$mobileIcon = static function(string $name): void {
    $paths = [
        'home'=>'<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3Z"/>',
        'campaigns'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'contacts'=>'<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4M16 4a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v3"/>',
        'inbox'=>'<path d="M3 4h18v16H3ZM3 13h5l2 3h4l2-3h5"/>',
        'analytics'=>'<path d="M4 4v16h17M8 15v-4m5 4V7m5 8V3"/>',
        'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    ];
    echo '<svg viewBox="0 0 24 24" aria-hidden="true">'.$paths[$name].'</svg>';
};
$mobileTabs = ['home'=>'Home','campaigns'=>'Campaigns','contacts'=>'Audience',($mobileCanManage?'inbox':'analytics')=>($mobileCanManage?'Inbox':'Analytics')];
$mobileActive = in_array($mobileView,$mobileCampaignViews,true)?'campaigns':(in_array($mobileView,$mobileAudienceViews,true)?'contacts':$mobileView);
?>
<nav class="mm-mobile-only mm-mobile-dock" aria-label="MessageMonkey quick navigation">
    <?php foreach($mobileTabs as $destination=>$label): ?><a href="audience.php?view=<?= $destination ?>"<?= $mobileActive===$destination?' aria-current="page"':'' ?>><?php $mobileIcon($destination); ?><span><?= $label ?></span></a><?php endforeach; ?>
    <button type="button" data-mm-menu-open aria-haspopup="dialog" aria-controls="mm-mobile-menu"<?= !isset($mobileTabs[$mobileActive])?' class="is-current"':'' ?>><?php $mobileIcon('more'); ?><span>More</span></button>
</nav>
<dialog id="mm-mobile-menu" class="mm-mobile-menu" aria-labelledby="mm-mobile-menu-title">
    <header><div><span class="mm-mobile-eyebrow">MessageMonkey</span><h2 id="mm-mobile-menu-title">All tools</h2></div><button type="button" data-mm-menu-close aria-label="Close all tools">×</button></header>
    <nav aria-label="All MessageMonkey tools">
        <?php if($mobileCanManage): ?><a class="mm-mobile-create" href="audience.php?view=campaigns&amp;create=1">＋ Create campaign</a><?php endif; ?>
        <?php
        $mobileSections = [
            'Create and send'=>['campaigns'=>'Campaigns','templates'=>'Email templates','campaign-manager'=>'Campaign Manager','automations'=>'Automations','forms'=>'Forms','landing-pages'=>'Landing pages','surveys'=>'Surveys'],
            'Your audience'=>['contacts'=>'Connections','tags'=>'Tags','segments'=>'Segments','groups'=>'Groups','fields'=>'Audience fields','preferences'=>'Subscriber preferences'],
            'Insights and content'=>['analytics'=>'Analytics','assistant&mode=analytics'=>'Ask Analytics AI','content'=>'My files','brand'=>'Brand Kit'],
            'Help and settings'=>['manual'=>'User Manual','settings'=>'Audience settings'],
        ];
        if($mobileCanManage){$mobileSections['Your audience']['inbox']='Inbox';$mobileSections['Your audience']['import']='Import connections';$mobileSections['Insights and content']['assistant&mode=content']='Write with AI';}
        if($canDelete??false)$mobileSections['Help and settings']['delivery']='Email delivery';
        foreach($mobileSections as $heading=>$links): ?>
        <section><h3><?= $h($heading) ?></h3><div><?php foreach($links as $destination=>$label): ?><a href="<?= $h('audience.php?view='.$destination) ?>"<?= $mobileView===$destination?' aria-current="page"':'' ?>><?= $h($label) ?><span aria-hidden="true">›</span></a><?php endforeach; ?></div></section>
        <?php endforeach; ?>
        <a class="mm-mobile-return" href="dashboard.php">← Return to MOED</a>
    </nav>
</dialog>
