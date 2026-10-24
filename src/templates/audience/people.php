<?php $defaultConnectionColumns=['email','first_name','last_name','tags','email_status','source','created_at','updated_at'];$columns=['email'=>'Email Address','first_name'=>'First Name','last_name'=>'Last Name','tags'=>'Tags','email_status'=>'Status','source'=>'Source','created_at'=>'Date Added','updated_at'=>'Last Changed'];foreach($groups as$group)$columns['group:'.$group['id']]='Groups: '.$group['name'];foreach($fields as$field)$columns['custom:'.$field['id']]=$field['label']; ?>
<?php
$statusHelp=['subscribed'=>'Opted in to receive audience email','unsubscribed'=>'Opted out after subscribing','non-subscribed'=>'Never opted in to receive audience email','cleaned'=>'Email address is invalid or bounced'];
$sourceOptions=[];$sourceLabels=audienceSignupSourceLabels();foreach($sources as$source)$sourceOptions['type:'.$source['source_type']]=$sourceLabels[$source['source_type']];asort($sourceOptions);
foreach($filters['source']as$value)if(!str_starts_with($value,'type:'))$sourceOptions[$value]=$value;
$activeRules=audienceActiveRules($filters,$rules);$hasFilters=$activeRules['rules']!==[]||$segment;
$chips=[];
if($filters['q']!=='')$chips[]=['key'=>'q','value'=>$filters['q'],'label'=>'Search: '.$filters['q']];
foreach($filters['status']as$value)$chips[]=['key'=>'status','value'=>$value,'label'=>ucfirst($value)];
$tagNames=array_column($tags,'name','id');foreach($filters['tags']as$value)$chips[]=['key'=>'tags','value'=>$value,'label'=>'Tag: '.($tagNames[$value]??'Unavailable tag')];
foreach($filters['source']as$value)$chips[]=['key'=>'source','value'=>$value,'label'=>'Source: '.($sourceOptions[$value]??$value)];
if($segment)$chips[]=['key'=>'segment','value'=>'','label'=>$segment['name']];elseif($rules['rules'])$chips[]=['key'=>'rules','value'=>'','label'=>'Advanced filters'];
?>
<form class="audience-filters audience-filter-popovers" method="get"><?php audienceInputs($baseForm+['archived'=>$filters['archived'],'rules'=>json_encode($rules,JSON_THROW_ON_ERROR),'sort'=>$sort,'direction'=>$direction,'per_page'=>$pageSize,'segment'=>$segment['id']??0]); ?>
    <label class="audience-people-search"><span class="sr-only">Search connections</span><?php audienceNavIcon('search'); ?><input id="contact-search" list="connection-search-options" type="search" autocomplete="off" name="q" placeholder="Search connections" value="<?= $h($filters['q']) ?>"></label>
    <button type="button" class="audience-filter-trigger" popovertarget="filter-segments" aria-haspopup="dialog" title="Filter by Segments"><?= $segment?$h($segment['name']):'Segments' ?><?php audienceNavIcon('chevron'); ?></button>
    <div id="filter-segments" class="audience-filter-panel" popover="auto" role="dialog" aria-label="Filter by Segments" data-filter-panel>
        <label class="filter-search"><?php audienceNavIcon('search'); ?><input type="search" autocomplete="off" placeholder="Search" aria-label="Search segments" data-filter-search></label>
        <div class="filter-choice-scroll"><?php foreach($segments as$s): ?><a data-filter-choice href="<?= $h($url(['segment'=>$s['id'],'archived'=>$filters['archived'],'per_page'=>$pageSize,'sort'=>$sort,'direction'=>$direction])) ?>"<?= $segment&&(int)$segment['id']===(int)$s['id']?' aria-current="true"':'' ?>><?= $h($s['name']) ?></a><?php endforeach; ?><?php if(!$segments): ?><p class="filter-empty">No saved segments yet.</p><?php endif; ?><p class="filter-empty" data-filter-empty hidden>No matching segments</p></div>
        <a class="filter-manage-link" href="<?= $h($url(['view'=>'segments'])) ?>">Manage segments</a>
    </div>
    <?php foreach(['status'=>'Subscription status','tags'=>'Tags','source'=>'Signup source']as$key=>$label): $options=$key==='status'?array_combine(array_keys($statusHelp),array_map('ucfirst',array_keys($statusHelp))):($key==='tags'?$tagNames:$sourceOptions);$reopen=($_GET['open_filter']??'')===$key; ?>
    <button type="button" class="audience-filter-trigger<?= count($filters[$key])?' active':'' ?>" popovertarget="filter-<?= $key ?>" aria-haspopup="dialog" title="Filter by <?= $label ?>"><?= $label ?><?= count($filters[$key])?' ('.count($filters[$key]).')':'' ?><?php audienceNavIcon('chevron'); ?></button>
    <div id="filter-<?= $key ?>" class="audience-filter-panel" popover="auto" role="dialog" aria-label="Filter by <?= $label ?>" data-filter-panel<?= $reopen?' data-reopen-filter':'' ?>>
        <?php if($key==='status'): ?><div class="filter-section-label">Email</div><?php else: ?><label class="filter-search"><?php audienceNavIcon('search'); ?><input type="search" autocomplete="off" placeholder="Search" aria-label="Search <?= strtolower($label) ?>" value="<?= $reopen?$h(audienceText($_GET,'filter_query',200)):'' ?>" data-filter-search></label><?php endif; ?>
        <div class="filter-choice-scroll"><?php foreach($options as$value=>$text): ?><label class="filter-option" data-filter-choice><input type="checkbox" name="<?= $key ?>[]" value="<?= $h($value) ?>" data-instant-filter="<?= $key ?>"<?= in_array((string)$value,array_map('strval',$filters[$key]),true)?' checked':'' ?><?= $key==='status'?' aria-describedby="status-help-'.$h($value).'"':'' ?>><span><?= $h($text) ?><?php if($key==='status'): ?><small id="status-help-<?= $h($value) ?>"><?= $h($statusHelp[$value]) ?></small><?php endif; ?></span></label><?php endforeach; ?><?php if(!$options): ?><p class="filter-empty">No <?= strtolower($label) ?> yet.</p><?php endif; ?><p class="filter-empty" data-filter-empty hidden>No matches found</p></div>
        <?php if($key==='tags'): ?><a class="filter-manage-link" href="<?= $h($url(['view'=>'tags'])) ?>">Manage tags</a><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if($canManage): ?><button type="button" class="audience-advanced-filters" data-segment-current><?php audienceNavIcon('filters'); ?>Advanced filters</button><?php endif; ?>
    <button type="submit" class="filter-search-submit sr-only" aria-label="Search connections">Search</button>
</form>
<p class="audience-notice error" data-filter-error role="alert" hidden></p>
<div data-audience-filter-summary>
<?php if($hasFilters): ?>
<div class="audience-active-filters" aria-label="Applied filters">
    <div class="audience-filter-chips"><?php foreach($chips as$chip): $without=$query;
        if(in_array($chip['key'],['status','tags','source'],true))$without[$chip['key']]=array_values(array_filter($filters[$chip['key']],static fn($v)=>(string)$v!==(string)$chip['value']));
        elseif($chip['key']==='segment'){unset($without['segment']);$without['rules']=json_encode(['match'=>'all','rules'=>[]]);}
        elseif($chip['key']==='rules')$without['rules']=json_encode(['match'=>'all','rules'=>[]]);else $without[$chip['key']]='';
    ?><a class="audience-filter-chip" href="<?= $h($url($without)) ?>" aria-label="Remove <?= $h($chip['label']) ?>"><?= $h($chip['label']) ?><span aria-hidden="true">×</span></a><?php endforeach; ?><a class="text-link" href="<?= $h($url(['archived'=>$filters['archived'],'per_page'=>$pageSize,'sort'=>$sort,'direction'=>$direction])) ?>">Clear filters</a></div>
    <?php if($canManage): ?><div class="audience-filter-actions"><button type="button" data-segment-current><?= $segment?'Edit segment':'Edit filters' ?></button><button type="button" data-open-dialog="filter-campaign">Send campaign</button><button type="button" data-open-dialog="save-current-segment">Save as segment</button></div><?php endif; ?>
</div>
<?php else: ?>
<div class="audience-counts"><p><a href="<?= $h($url(['status'=>['subscribed','unsubscribed','non-subscribed']])) ?>" title="Active connections excluding pending and cleaned addresses"><strong><?= number_format((int)$counts['total']) ?></strong></a> total connections. <a href="<?= $h($url(['status'=>['subscribed']])) ?>"><strong><?= number_format((int)$counts['subscribed']) ?></strong></a> email subscribers.<?php if(!empty($counts['cleaned'])): ?> <a class="audience-cleaned-count" href="<?= $h($url(['status'=>['cleaned']])) ?>"><?= number_format((int)$counts['cleaned']) ?> cleaned</a><?php endif; ?></p></div>
<?php endif; ?>
</div>
<section class="audience-table-card" aria-label="Connections" data-audience-results>
    <div class="audience-table-tools"><span><?= number_format($total) ?> matching <?= $filters['archived']==='1'?'archived ':'' ?>connections</span><div><button type="button" class="text-button audience-columns-trigger" popovertarget="columns" aria-haspopup="dialog">Columns <?php audienceNavIcon('chevron'); ?></button>
        <?php if($canExport): ?><form method="post" id="people-export"><?= $csrf ?><?php audienceInputs($baseForm+$query+['action'=>'queue_export','selection_scope'=>'all']); ?><span data-export-selection></span><button class="text-button" type="submit" data-export-label data-export-base-label="<?= $hasFilters?'↓ Export segment':'↓ Export connections' ?>"><?= $hasFilters?'↓ Export segment':'↓ Export connections' ?></button></form><?php endif; ?>
    </div></div>
    <?php if($canManage): ?><div class="audience-bulk-toolbar" hidden data-bulk-toolbar><span data-selection-count>0 connections selected</span><button data-select-matches type="button">Select all <?= number_format($total) ?></button><button type="button" data-open-dialog="bulk-people">Actions ⌄</button><button data-clear-selection type="button">Clear selection</button></div><?php endif; ?>
    <div class="audience-table-scroll"><table data-mm-paginated-table class="data-table audience-table" data-people-table><thead><tr>
        <?php if($canManage): ?><th class="selection-cell"><input type="checkbox" data-select-page aria-label="Select this page"></th><?php endif; ?>
        <?php foreach($columns as$key=>$label): ?><th scope="col" data-column="<?= $h($key) ?>"<?= !in_array($key,$defaultConnectionColumns,true)?' hidden':'' ?><?= in_array($key,$sortColumns,true)?' aria-sort="'.($sort===$key?($direction==='asc'?'ascending':'descending'):'none').'"':'' ?>><?php if(in_array($key,$sortColumns,true)): ?><a href="<?= $h($url(array_replace($query,['sort'=>$key,'direction'=>$sort===$key&&$direction==='asc'?'desc':'asc']))) ?>"><?= $h($label) ?><?= $sort===$key?($direction==='asc'?' ↑':' ↓'):'' ?></a><?php else: ?><?= $h($label) ?><?php endif; ?></th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach($rows as$row): $custom=audienceJson($row['custom_json']); ?><tr>
        <?php if($canManage): ?><td class="selection-cell"><input type="checkbox" data-person-select value="<?= (int)$row['id'] ?>" aria-label="Select <?= $h($row['email']) ?>"></td><?php endif; ?>
        <?php foreach($columns as$key=>$label): ?><td data-column="<?= $h($key) ?>"<?= !in_array($key,$defaultConnectionColumns,true)?' hidden':'' ?>>
            <?php if($key==='email'): ?><a href="<?= $h($url(['view'=>'contact','id'=>$row['id']])) ?>"><?= $h($row['email']) ?></a>
            <?php elseif($key==='tags'): $connectionTags=$rowTags[$row['id']]; foreach(array_slice($connectionTags,0,3)as$tag): ?><a class="audience-tag" href="<?= $h($url(['tags'=>[$tag['id']]])) ?>"><?= $h($tag['name']) ?></a><?php endforeach; ?><?php if(count($connectionTags)>3): ?><a class="audience-more-tags" href="<?= $h($url(['view'=>'contact','id'=>$row['id']])) ?>" aria-label="View <?= count($connectionTags)-3 ?> more tags" title="<?= $h(implode(', ',array_column(array_slice($connectionTags,3),'name'))) ?>">+ <?= count($connectionTags)-3 ?></a><?php endif; ?>
            <?php elseif(str_starts_with($key,'group:')): foreach($rowGroups[$row['id']]as$group): if((string)$group['category_id']!==substr($key,6))continue; ?><span class="audience-tag" title="<?= $h($group['category']) ?>"><?= $h($group['name']) ?></span><?php endforeach; ?>
            <?php elseif($key==='email_status'): ?><span class="audience-status <?= $h($row[$key]) ?>"><?= $h(ucfirst($row[$key])) ?></span>
            <?php elseif(str_starts_with($key,'custom:')): $v=$custom[substr($key,7)]??''; ?><?= $h(is_array($v)?implode(', ',$v):$v) ?>
            <?php else: ?><?= $h(in_array($key,['created_at','updated_at'],true)?substr($row[$key],0,16):$row[$key]) ?><?php endif; ?>
        </td><?php endforeach; ?>
    </tr><?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="<?= count($columns)+($canManage?1:0) ?>" class="audience-empty"><h2><?= (int)($counts['records']??$counts['total'])===0?'Build your audience':'No connections found' ?></h2><p><?= (int)($counts['records']??$counts['total'])===0?'Import your connections or add a connection to get started.':'Try another search or change your filters.' ?></p><?php if($canManage): ?><a class="button primary" href="<?= $h($url(['view'=>'import'])) ?>">Import connections</a><?php endif; ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php audiencePagination(['total'=>$total,'page'=>$page,'size'=>$pageSize,'label'=>'Connection pages','params'=>array_replace($baseForm,$query),'url'=>$url]); ?>
</section>
<div id="columns" class="audience-columns-panel" popover="auto" role="dialog" aria-labelledby="columns-title">
    <div class="dialog-heading"><h2 id="columns-title">Columns</h2><button type="button" popovertarget="columns" popovertargetaction="hide" aria-label="Close columns">×</button></div>
    <p>Customize how columns appear or go to <a href="<?= $h($url(['view'=>'fields'])) ?>">audience fields</a> to add new audience fields.</p>
    <p class="sr-only" id="columns-reorder-help">Drag a handle to reorder a column, or focus its handle and press the Up or Down arrow key. Select Apply to save changes.</p>
    <div data-column-list><?php foreach($columns as$key=>$label): ?><div class="column-choice" data-column-choice="<?= $h($key) ?>"><label class="audience-check"><input type="checkbox" data-column-toggle="<?= $h($key) ?>"<?= in_array($key,$defaultConnectionColumns,true)?' checked':'' ?><?= $key==='email'?' disabled':'' ?>><span><?= $h($label) ?></span></label><?php if($key!=='email'): ?><button type="button" class="column-drag-handle" data-column-drag aria-label="Reorder <?= $h($label) ?>" aria-describedby="columns-reorder-help"><?php audienceNavIcon('grip'); ?></button><?php endif; ?></div><?php endforeach; ?></div>
    <p class="sr-only" role="status" data-column-announcement></p>
    <div class="columns-footer"><button type="button" class="primary" data-apply-columns>Apply</button></div>
</div>

<datalist id="connection-search-options"><?= audienceSearchOptions(array_merge(array_column($rows,'email'),array_map(static fn($row)=>trim($row['first_name'].' '.$row['last_name']),$rows))) ?></datalist>
