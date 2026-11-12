<?php
$campaignArchived=($campaignFilters['trash']??'0')==='1';
$dateSortLabels=['updated_at'=>'Date Edited','created_at'=>'Date Created','sent_at'=>'Send Date','name'=>'Date Edited'];
$dateSort=$campaignFilters['sort']!=='name';
$dateField=$dateSort?$campaignFilters['sort']:'updated_at';
$dateStamp=static fn($value)=>userDisplayInstant($value);
?>
<form method="post" id="campaign-selection" data-campaign-selection>
<?php $campaignForm('campaign_operation',['operation'=>'move','selection_scope'=>'page','selection_filters'=>json_encode($campaignFilters),'selection_snapshot'=>json_encode((object)$campaignSnapshot)]); ?>
<div class="campaign-bulk" hidden data-campaign-bulk><strong data-campaign-selection-count></strong><button type="button" data-campaign-select-all <?= $campaignTotal>5000?'disabled':'' ?>>Select All <?= number_format($campaignTotal) ?> Matching Campaigns</button><?php if($campaignArchived): ?><button type="button" data-campaign-lifecycle-selected="restore">Restore</button><?php if($canDelete): ?><button type="button" data-campaign-lifecycle-selected="delete" data-admin-unlock-required>Delete Permanently</button><?php endif; ?><?php else: ?><button type="button" data-open-dialog="campaign-move">Move To</button><button type="button" data-campaign-lifecycle-selected="archive">Archive</button><?php endif; ?></div>
<div class="campaign-table-scroll"><table data-mm-paginated-table class="data-table campaign-table campaign-list-table" aria-label="All Campaigns"><thead><tr>
    <th class="campaign-select-column"><?php if($canManage): ?><input type="checkbox" aria-label="Select All on Page" data-campaign-select-page><?php endif; ?></th>
    <th data-campaign-column="name"<?= !$dateSort?' aria-sort="'.($campaignFilters['direction']==='asc'?'ascending':'descending').'"':'' ?>><a class="campaign-name-sort" href="<?= $h($campaignUrl(['sort'=>'name','direction'=>!$dateSort&&$campaignFilters['direction']==='asc'?'desc':'asc','page'=>1])) ?>">Name <span aria-hidden="true">↕</span></a></th>
    <th data-campaign-column="date"<?= $dateSort?' aria-sort="'.($campaignFilters['direction']==='asc'?'ascending':'descending').'"':'' ?>><div class="campaign-sort"><button type="button" class="campaign-filter" popovertarget="campaign-sort"><?= $dateSortLabels[$campaignFilters['sort']] ?><?php audienceNavIcon('chevron'); ?></button><button type="button" data-campaign-direction aria-label="Sort Direction Toggle" title="<?= $campaignFilters['direction']==='desc'?'Descending':'Ascending' ?>"><?= $campaignFilters['direction']==='desc'?'↓':'↑' ?></button></div></th>
    <th data-campaign-column="status">Status</th><th data-campaign-column="analytics">Analytics</th><th data-campaign-column="recipients">Send To</th><th data-campaign-column="folder">Folder</th><th data-campaign-column="editor">Last Edited By</th><th data-campaign-column="actions">Actions</th>
</tr></thead><tbody>
<?php
// Send the campaign heading, filters, and table header before aggregating lifetime events.
if(isset($loadCampaignMetrics)){flush();$campaignMetrics=$loadCampaignMetrics();}
foreach($campaignRows as$row):
    $rowDraft=audienceJson($row['draft_json']);$edited=$dateStamp($row[$dateField]??null);$statusDate=$dateStamp($row['status']==='sent'?($row['sent_at']??null):($row['status']==='scheduled'?($row['delivery_due']??null):null));
    $rowMetrics=$campaignMetrics[(int)$row['id']]??[];
    $showReport=$campaignArchived||$row['status']==='sent';
    $rowAction=$showReport?'View Report':($canManage&&$row['status']==='draft'?'Edit':'View');
    $selectedRecipients=!empty($rowDraft['include'])||!empty($rowDraft['exclude'])||!empty($rowDraft['exclude_tag'])||!empty($rowDraft['recipient_rules']['rules'])||($rowDraft['target']??'all')!=='all';
    $recipientLabel=!empty($rowDraft['recipients_saved'])?($selectedRecipients?'Selected Subscribers':'All Subscribers'):(isset($row['delivery_count'])?'Campaign Recipients':'Not Selected');

?>
<tr>
    <td class="campaign-select-column"><?php if($canManage): ?><input type="checkbox" name="selected[]" value="<?= $row['id'] ?>" aria-label="Select <?= $h($row['name']) ?>" data-campaign-select-item><input type="hidden" name="versions[<?= $row['id'] ?>]" value="<?= $row['version'] ?>"><?php endif; ?></td>
    <td data-campaign-column="name"><div class="campaign-name-cell"><span class="campaign-thumbnail" aria-hidden="true"><?php audienceNavIcon(audienceCampaignType($row)==='plain-text'?'text':'mail'); ?><i></i><i></i><i></i></span><div><a class="campaign-name" href="<?= $h($url(['view'=>$campaignArchived?'campaign-delivery':'campaign','id'=>$row['id']])) ?>"><?php audienceNavIcon('mail'); ?><span><?= $h($row['name']) ?></span></a><p class="campaign-subject" title="<?= $h($rowDraft['subject']??'') ?>"><?= isset($rowDraft['subject'])?'Subject: '.$h($rowDraft['subject']):$h($campaignTypes[audienceCampaignType($row)]??'Email') ?></p></div></div></td>
    <td data-campaign-column="date"><time datetime="<?= $h($edited?->format('c')) ?>"><?= $h($edited?->format('D, M j, Y')??'—') ?><small><?= $h($edited?->format('g:i A T')??'') ?></small></time></td>
    <td data-campaign-column="status">
        <span class="campaign-status" data-status="<?= $h($row['status']) ?>"><?= $h(\Dnr\Domain\ReferenceData::label($row['status'])) ?></span>
        <?php if($statusDate): ?><time class="campaign-status-date" datetime="<?= $h($statusDate->format('c')) ?>"><?= $h($statusDate->format('D, M j, Y')) ?><small><?= $h($statusDate->format('g:i A T')) ?></small></time><?php endif; ?>
        <?php if($row['status']==='scheduled'&&$statusDate): $campaignCountdownAt=$statusDate; include __DIR__.'/campaign-countdown.php'; endif; ?>
    </td>
    <td data-campaign-column="analytics"><?php if(isset($rowMetrics['open_rate'])||isset($rowMetrics['click_rate'])): ?><div class="campaign-analytics"><?php foreach(['open_rate'=>'Opens','click_rate'=>'Clicks']as$metric=>$label): ?><span title="<?= $h(audienceEngagementExplanation()) ?>"><small><?= $label ?></small><?= isset($rowMetrics[$metric])?$h(number_format($rowMetrics[$metric],1)).'%':'—' ?></span><?php endforeach; ?></div><?php else: ?>—<?php endif; ?><?php if(!empty($rowMetrics['awaiting_receipt'])): ?><small><?= (int)$rowMetrics['awaiting_receipt'] ?> pending receipts</small><?php endif; ?></td>
    <td data-campaign-column="recipients"><?= $h($recipientLabel) ?><?php if(isset($row['delivery_count'])): ?><a class="campaign-recipient-count" href="<?= $h($url(['view'=>'campaign-delivery','id'=>$row['id']])) ?>"><?= number_format((int)$row['delivery_count']) ?> <?= $row['status']==='scheduled'?'Estimated Recipients':'Recipients' ?></a><?php endif; ?></td>
    <td data-campaign-column="folder"><?php if($row['folder_name']): ?><a href="<?= $h($campaignUrl(['folder'=>$row['folder_id'],'page'=>1])) ?>"><?= $h($row['folder_name']) ?></a><?php else: ?>—<?php endif; ?></td>
    <td data-campaign-column="editor"><span class="campaign-editor" title="<?= $h($row['actor_label']) ?>"><?= $h($row['actor_label']?:'—') ?></span></td>
    <td data-campaign-column="actions"><div class="campaign-row-actions<?= $canManage?' campaign-row-actions-manage':'' ?>">
        <a class="action-icon-button" data-campaign-action="<?= $showReport?'report':'edit' ?>" href="<?= $h($url(['view'=>$showReport?'campaign-delivery':'campaign','id'=>$row['id']])) ?>" aria-label="<?= $h($rowAction.' '.$row['name']) ?>" data-tooltip="<?= $h($showReport?'Report':$rowAction) ?>"><?= actionIconSvg($showReport?'results':($rowAction==='Edit'?'edit':'view')) ?></a>
        <?php if($canManage): ?><?php $rowOperations=$campaignArchived?['restore'=>['Restore','restore']]+($canDelete?['delete'=>['Delete Permanently (Admin Unlock Required)','delete']]:[]):['rename'=>['Rename','rename'],'move'=>['Move to Folder','folder'],'replicate'=>['Replicate','duplicate'],'archive'=>['Archive','archive']]; ?>
        <?php foreach($rowOperations as$op=>[$label,$icon]): ?>
        <button type="button" class="action-icon-button" data-campaign-action="<?= $op ?>" aria-label="<?= $h($label.' '.$row['name']) ?>" data-tooltip="<?= $op==='replicate'?'Duplicate':$label ?>"<?= $op==='delete'?' data-admin-unlock-required':'' ?> data-campaign-row='<?= $campaignJson(array_intersect_key($row,array_flip(['id','name','version']))) ?>' data-campaign-op="<?= $op ?>"><?= actionIconSvg($icon) ?></button>
        <?php endforeach; ?><?php endif; ?>
    </div></td>
</tr>
<?php endforeach; ?>
<?php if(!$campaignRows): ?><tr><td colspan="9" class="campaign-list-empty">No campaigns match these filters.</td></tr><?php endif; ?>
</tbody></table></div></form>
<div popover id="campaign-sort" class="audience-action-menu" role="menu" aria-label="Sort By"><?php foreach(['updated_at'=>'Date Edited','created_at'=>'Date Created','sent_at'=>'Send Date','name'=>'Alphabetical']as$value=>$text): ?><button type="button" role="menuitemradio" aria-checked="<?= $campaignFilters['sort']===$value?'true':'false' ?>" data-campaign-filter="sort" data-value="<?= $value ?>"><?= $text ?></button><?php endforeach; ?></div>
<?php audiencePagination(['total'=>$campaignTotal,'page'=>$campaignPage,'size'=>$campaignSize??25,'label'=>'Campaign Pages','url'=>$campaignUrl]); ?>
