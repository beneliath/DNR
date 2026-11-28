<?php
$definitionStatus = ($_GET['definition_status']??'active')==='archived'?'archived':'active';
$search = audienceText($_GET,'list_search');
$segmentRows = array_values(array_filter($segments,fn($s)=>($definitionStatus==='archived'?!empty($s['archived_at']):empty($s['archived_at']))&&($search===''||mb_stripos($s['name'],$search)!==false)));
$segmentOrders = [
    'name'=>['name','asc','Name (A–Z)'],
    'name_desc'=>['name','desc','Name (Z–A)'],
    'connections_asc'=>['connections','asc','Connections (Low to High)'],
    'connections_desc'=>['connections','desc','Connections (High to Low)'],
    'newest'=>['created_at','desc','Date Created (Newest)'],
    'oldest'=>['created_at','asc','Date Created (Oldest)'],
    'modified_newest'=>['updated_at','desc','Date Modified (Newest)'],
    'modified_oldest'=>['updated_at','asc','Date Modified (Oldest)'],
];
$segmentOrder = $_GET['segment_order']??null;
if (!is_string($segmentOrder)||!isset($segmentOrders[$segmentOrder])) {
    $descending = ($_GET['segment_direction']??'asc')==='desc';
    $segmentOrder = ($_GET['segment_sort']??'name')==='created_at'
        ? ($descending?'newest':'oldest')
        : ($descending?'name_desc':'name');
}
[$segmentSort,$segmentDirection] = $segmentOrders[$segmentOrder];
$sortMultiplier = $segmentDirection==='desc'?-1:1;
if ($segmentSort==='connections') {
    if (!isset($segmentCounts)) {
        $segmentCounts = [];
        foreach (array_chunk($segmentRows,100) as $batch) {
            $segmentCounts += $store->segmentConnectionCounts($batch,$catalog,$tags);
        }
    }
    usort($segmentRows,static fn($a,$b)=>$sortMultiplier*(($segmentCounts[$a['id']]??0)<=>($segmentCounts[$b['id']]??0))?:strnatcasecmp($a['name'],$b['name']));
} else {
    usort($segmentRows,static fn($a,$b)=>$sortMultiplier*strnatcasecmp($a[$segmentSort]??'',$b[$segmentSort]??''));
}
$segmentSize = audiencePageSize($_GET,'segment_size',100);
$segmentPage = max(1,min((int)($_GET['segment_page']??1),(int)ceil(count($segmentRows)/$segmentSize)));
$visibleSegments = array_slice($segmentRows,($segmentPage-1)*$segmentSize,$segmentSize);
$segmentCounts ??= $store->segmentConnectionCounts($visibleSegments,$catalog,$tags);
$segmentHeaderParams = ['view'=>'segments','definition_status'=>$definitionStatus,'list_search'=>$search,'segment_size'=>$segmentSize];
?>
<section class="audience-segments-card" aria-label="Saved Segments">
<?php if($definitionArchiveAvailable): ?><nav class="audience-tabs audience-definition-tabs" aria-label="Segment Status"><?php foreach(['active'=>'Active','archived'=>'Archived']as$status=>$label): ?><a href="<?= $h($url(['view'=>'segments','definition_status'=>$status])) ?>"<?= $definitionStatus===$status?' aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?></nav><?php endif; ?>
<div class="audience-segment-summary"><h2>Saved by You <span><?= count($segmentRows) ?> <?= count($segmentRows)===1?'Segment':'Segments' ?></span></h2><button class="text-button segment-about" type="button" popovertarget="segments-help">ⓘ About Segments</button></div>
<div id="segments-help" class="audience-filter-panel" role="dialog" aria-label="About Segments" popover="auto"><h3>About Segments</h3><p>Segments find connections who meet your conditions. Combine tags, groups, personal details, and activity with AND or OR. Saved segments update automatically as your connections change.</p></div>
<div class="audience-definition-controls mm-search-row">
    <form method="get" class="audience-list-search audience-definition-search"><?php audienceInputs($baseForm+['view'=>'segments','definition_status'=>$definitionStatus,'segment_order'=>$segmentOrder,'segment_size'=>$segmentSize]); ?><label class="search-with-icon"><?php audienceNavIcon('search'); ?><input id="segment-list-search" type="search" autocomplete="off" name="list_search" list="segment-search-options" aria-label="Search Segments" placeholder="Search Segments" value="<?= $h($search) ?>"></label><button type="submit" data-mm-search-text="segment-list-search">Search</button></form>
    <?php if($canDelete): ?><div class="audience-definition-toolbar"><?php include __DIR__.'/definition-selection.php'; ?></div><?php endif; ?>
    <form method="get" class="audience-definition-sort"><?php audienceInputs($baseForm+$segmentHeaderParams); ?><label for="segment-sort">Sort By</label><select id="segment-sort" name="segment_order" data-auto-submit><?php foreach($segmentOrders as $value=>[,,$label]): ?><option value="<?= $value ?>"<?= $segmentOrder===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></form><a class="mm-clear-all" href="<?= $h($url(array_replace($segmentHeaderParams,['list_search'=>'']))) ?>">Clear All</a>
</div>
<table class="data-table audience-table audience-segment-table<?= !$canManage?' is-readonly':'' ?>" data-mm-paginated-table data-mm-sort-control="segment-sort" aria-label="Saved Segments on This Page">
<thead><tr>
    <?php if($canDelete): ?><th scope="col" class="definition-check"><span class="sr-only">Select Segment</span></th><?php endif; ?>
    <?php foreach (['name'=>['segment-name','Segment Name','name_desc'],'connections_asc'=>['segment-count','Connections','connections_desc'],'newest'=>['segment-created','Created','oldest'],'modified_newest'=>['segment-modified','Modified','modified_oldest']] as $firstOrder=>[$columnClass,$label,$otherOrder]):
        $columnDirection = in_array($segmentOrder,[$firstOrder,$otherOrder],true)?$segmentDirection:'';
        $nextOrder = $segmentOrder===$firstOrder?$otherOrder:$firstOrder;
    ?>
        <th scope="col" class="<?= $columnClass ?>" aria-sort="<?= ['asc'=>'ascending','desc'=>'descending'][$columnDirection]??'none' ?>"><a class="audience-definition-sort-link" href="<?= $h($url($segmentHeaderParams+['segment_order'=>$nextOrder])) ?>"><?= $label ?> <span aria-hidden="true"><?= ['asc'=>'↑','desc'=>'↓'][$columnDirection]??'↕' ?></span></a></th>
    <?php endforeach; ?>
    <th scope="col" class="segment-actions">Actions</th>
</tr></thead>
<tbody>
<?php foreach($visibleSegments as$s): $model=['id'=>(int)$s['id'],'version'=>(int)$s['version'],'name'=>$s['name'],'archived_at'=>$s['archived_at']??null,'rules'=>audienceLegacyRules(audienceJson($s['filters_json']),$tags)]; ?>
    <tr data-definition-row>
        <?php if($canDelete): ?><td class="definition-check"><input form="definition-selection" type="checkbox" name="selected_ids[]" value="<?= (int)$s['id'] ?>" data-definition-item aria-label="Select <?= $h($s['name']) ?>"><input form="definition-selection" type="hidden" name="versions[<?= (int)$s['id'] ?>]" value="<?= (int)$s['version'] ?>"></td><?php endif; ?>
        <td class="segment-name"><a href="<?= $h($url(['segment'=>$s['id']])) ?>"><?= $h($s['name']) ?></a><?= !empty($s['archived_at'])?' <span class="audience-help">Archived</span>':'' ?></td>
        <td class="segment-count" data-label="Connections"><?= number_format((int)($segmentCounts[(int)$s['id']]??0)) ?></td>
        <?php foreach(['created_at'=>['Created','segment-created'],'updated_at'=>['Modified','segment-modified']]as$dateKey=>[$dateLabel,$dateClass]): ?><td class="<?= $dateClass ?>" data-label="<?= $dateLabel ?>"><?php if(!empty($s[$dateKey])): ?><time datetime="<?= $h(str_replace(' ','T',substr($s[$dateKey],0,19)).'Z') ?>"><?= $h(formatUserTimestamp($s[$dateKey],'M j, Y, g:i A')) ?></time><?php else: ?>—<?php endif; ?></td><?php endforeach; ?>
        <td class="segment-actions"><?php if($canManage): $menuKind='segment';$menuModel=$model;include __DIR__.'/row-menu.php';else: ?><div class="audience-segment-actions"><a class="action-icon-button" href="<?= $h($url(['segment'=>$s['id']])) ?>" aria-label="View Segment <?= $h($s['name']) ?>" data-tooltip="View Connections"><?= actionIconSvg('view') ?></a></div><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if(!$segmentRows): ?><tr><td colspan="<?= $canDelete?6:5 ?>" class="audience-empty"><?= $definitionStatus==='archived'?'No archived segments':'No saved segments' ?><?= $search!==''?' match your search':' yet' ?>.</td></tr><?php endif; ?>
</tbody></table>
<?php $pageParams=$baseForm+$segmentHeaderParams+['segment_order'=>$segmentOrder]; ?>
<?php audiencePagination(['total'=>count($segmentRows),'page'=>$segmentPage,'size'=>$segmentSize,'page_key'=>'segment_page','size_key'=>'segment_size','label'=>'Segment Pages','params'=>$pageParams,'url'=>$url]); ?>
</section>
<section class="audience-segment-suggestions" aria-labelledby="suggested-segments-heading">
<h2 id="suggested-segments-heading" class="suggested-heading">Suggested Segments</h2><p>Start with a common audience, then adjust its filters.</p><div class="audience-suggestions">
<?php foreach(['Subscribed Connections'=>['field'=>'email_status','operator'=>'is','value'=>'subscribed'],'Recently Added'=>['field'=>'created_at','operator'=>'last_days','value'=>'30'],'Unsubscribed connections'=>['field'=>'email_status','operator'=>'is','value'=>'unsubscribed']]as$name=>$condition): $suggested=['id'=>0,'version'=>0,'name'=>$name,'rules'=>['match'=>'all','rules'=>[$condition]]]; ?><article class="audience-panel"><div class="audience-suggestion-copy"><h3><?= $name ?></h3><p><?= $condition['field']==='created_at'?'Connections added in the last 30 days.':'Connections with this email subscription status.' ?></p></div><?php if($canManage): ?><button type="button" data-segment-edit="<?= $h(json_encode($suggested,JSON_THROW_ON_ERROR)) ?>" aria-label="View Segment Details: <?= $h($name) ?>">View Details</button><?php endif; ?></article><?php endforeach; ?>
</div></section>

<datalist id="segment-search-options"><?= audienceSearchOptions(array_column($segments,'name')) ?></datalist>
