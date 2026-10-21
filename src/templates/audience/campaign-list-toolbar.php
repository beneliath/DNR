<div class="campaign-list-toolbar">
<form method="get" class="campaign-filters" id="campaign-filters">
<?php audienceInputs(['view'=>'campaigns','layout'=>$campaignFilters['layout'],'month'=>$campaignFilters['month'],'direction'=>$campaignFilters['direction'],'date_field'=>$campaignFilters['date_field'],'start'=>$campaignFilters['start'],'end'=>$campaignFilters['end'],'date_preset'=>$campaignFilters['date_preset']??'','holidays'=>$campaignFilters['holidays']??'0','sort'=>$campaignFilters['sort']]); ?>
<label class="campaign-search"><?php audienceNavIcon('search'); ?><span class="sr-only">Search campaigns</span><input type="search" autocomplete="off" list="campaign-search-options" name="search" value="<?= $h($campaignFilters['search']) ?>" placeholder="Search..."></label>
<div class="campaign-filter-row">
<?php foreach(['type'=>['Type',[''=>'All']+$campaignTypes],'status'=>['Status',[''=>'All','draft'=>'Draft','ongoing'=>'Ongoing','scheduled'=>'Scheduled','completed'=>'Completed']]]as$key=>[$label,$options]): ?>
<input type="hidden" name="<?= $key ?>" value="<?= $h($campaignFilters[$key]) ?>"><button type="button" class="campaign-filter" popovertarget="campaign-filter-<?= $key ?>"><?= $label ?>: <strong><?= $h($options[$campaignFilters[$key]]) ?></strong><?php audienceNavIcon('chevron'); ?></button>
<div popover class="audience-action-menu campaign-filter-options" id="campaign-filter-<?= $key ?>" role="menu" aria-label="<?= $label ?>"><?php foreach($options as$value=>$text): ?><button type="button" role="menuitemradio" aria-checked="<?= $campaignFilters[$key]===(string)$value?'true':'false' ?>" data-campaign-filter="<?= $key ?>" data-value="<?= $h($value) ?>"><?= $h($text) ?></button><?php endforeach; ?></div>
<?php endforeach; ?>
<input type="hidden" name="folder" value="<?= $h($campaignFilters['folder']) ?>">
<button type="button" class="campaign-filter" popovertarget="campaign-folders">Folder: <strong><?= $h($campaignFilters['folder']==='unfiled'?'Unfiled items':(array_column($folders,'name','id')[$campaignFilters['folder']]??'All')) ?></strong><?php audienceNavIcon('chevron'); ?></button>
<div popover id="campaign-folders" class="audience-action-menu campaign-folder-menu" role="dialog" aria-label="Folders">
<label class="campaign-search"><span class="sr-only">Folder searchbar</span><input type="search" autocomplete="off" placeholder="Name" data-campaign-folder-search list="folder-search-options"></label>
<button type="button" data-campaign-filter="folder" data-value="">All</button><button type="button" data-campaign-filter="folder" data-value="unfiled">Unfiled items</button>
<?php foreach($folders as$folder): ?><div class="campaign-folder-row" data-folder-name="<?= $h(mb_strtolower($folder['name'])) ?>"><button type="button" data-campaign-filter="folder" data-value="<?= $folder['id'] ?>"><?= $h($folder['name']) ?> <small><?= $folder['campaign_count'] ?></small></button><?php if($canManage): ?><button type="button" aria-label="Edit folder <?= $h($folder['name']) ?>" data-campaign-folder='<?= $campaignJson($folder) ?>'><?php audienceNavIcon('rename'); ?></button><button type="button" aria-label="Delete folder <?= $h($folder['name']) ?>" data-campaign-folder-delete='<?= $campaignJson($folder) ?>'><?php audienceNavIcon('trash'); ?></button><?php endif; ?></div><?php endforeach; ?>
<?php if($canManage): ?><button type="button" class="campaign-menu-footer" data-campaign-folder='{"id":0,"version":0,"name":""}'>+ Create folder</button><?php endif; ?>
</div>
<button type="button" class="campaign-filter" data-open-dialog="campaign-date">Date: <strong><?= $h(($campaignFilters['date_preset']??'')!==''?(audienceCampaignDatePresets()[$campaignFilters['date_preset']]??'Custom'):($campaignFilters['start']||$campaignFilters['end']?'Custom':'All')) ?></strong><?php audienceNavIcon('chevron'); ?></button>
<a href="<?= $h($url(['view'=>'campaigns','layout'=>$campaignFilters['layout']])) ?>">Clear all</a>

</div><button class="sr-only" type="submit">Search campaigns</button>
</form>

<div class="campaign-toolbar-actions">
    <span class="campaign-bot-filter"><i aria-hidden="true"></i>Known bots excluded</span>
    <div class="campaign-analytics-links"><a class="audience-button" href="<?= $h($url(['view'=>'analytics'])) ?>">View analytics</a><button type="button" popovertarget="campaign-analytics-menu" aria-label="More analytics views"><?php audienceNavIcon('chevron'); ?></button></div>
    <div popover id="campaign-analytics-menu" class="audience-action-menu" role="menu" aria-label="Analytics views"><a role="menuitem" href="<?= $h($url(['view'=>'analytics'])) ?>">Email dashboard</a><a role="menuitem" href="<?= $h($url(['view'=>'analytics','section'=>'audience'])) ?>">Audience analytics</a><a role="menuitem" href="<?= $h($url(['view'=>'analytics','section'=>'activity'])) ?>">Recipient activity</a></div>
    <?php if($campaignFilters['layout']==='list'): ?><button type="button" class="campaign-customize-trigger" popovertarget="campaign-columns"><?php audienceNavIcon('filters'); ?>Customize</button><?php endif; ?>
</div>
<?php if($campaignFilters['layout']==='list'): ?>
<div popover id="campaign-columns" class="audience-action-menu campaign-columns" data-campaign-columns role="dialog" aria-labelledby="campaign-columns-title">
    <h2 id="campaign-columns-title">Customize columns</h2><p>Choose what appears in your campaign list.</p>
    <?php foreach(['name'=>'Name','date'=>'Date edited','status'=>'Status','analytics'=>'Analytics','recipients'=>'Send to','folder'=>'Folder','editor'=>'Last edited by','actions'=>'Actions']as$key=>$label): ?><label><input type="checkbox" data-campaign-column-toggle="<?= $key ?>" checked<?= in_array($key,['name','actions'],true)?' disabled':'' ?>><?= $label ?></label><?php endforeach; ?>
    <div><button type="button" data-campaign-columns-reset>Reset to default</button><button type="button" popovertarget="campaign-columns" popovertargetaction="hide">Done</button></div>
</div>
<?php endif; ?>
</div>
