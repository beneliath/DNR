<?php
// Inbox and the designer use this menu when the workspace sidebar is unavailable.
$mobileView = isset($designerConfig) ? 'designer' : ($view ?? 'inbox');
$mobileCanManage = $canManage ?? false;
?>
<dialog id="mm-mobile-menu" class="mm-mobile-menu" aria-labelledby="mm-mobile-menu-title">
    <header><div><span class="mm-mobile-eyebrow">MessageMonkey</span><h2 id="mm-mobile-menu-title">All tools</h2></div><button type="button" data-mm-menu-close aria-label="Close all tools">×</button></header>
    <nav aria-label="All MessageMonkey tools">
        <?php if($mobileCanManage): ?><a class="mm-mobile-create" href="audience.php?view=campaigns&amp;create=1">＋ Create campaign</a><?php endif; ?>
        <?php
        $mobileSections = [
            'Overview'=>['home'=>'Home'],
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
