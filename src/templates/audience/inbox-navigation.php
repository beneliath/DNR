<aside class="inbox-sidebar" id="inbox-sidebar" aria-label="Inbox navigation">
    <a class="inbox-compose" href="<?= $h($url(['panel'=>'compose'])) ?>"><?php $icon('compose'); ?><span>Compose message</span></a>
    <nav class="inbox-folders" aria-label="Conversation status">
        <?php foreach($statusNames as$key=>$name): ?>
        <a href="<?= $h($filterUrl(['status'=>$key])) ?>"<?= $list['status']===$key&&!$panel?' aria-current="page"':'' ?>>
            <?php $icon($key); ?><span><?= $h($name) ?></span><small><?= (int)($list['counts'][$key]??0) ?></small>
        </a>
        <?php endforeach; ?>
        <a href="<?= $h($url(['panel'=>'drafts'])) ?>"<?= $draftWorkspace?' aria-current="page"':'' ?>><?php $icon('draft'); ?><span>Drafts</span><small><?= count($drafts) ?></small></a>
    </nav>
    <div class="inbox-nav-group">
        <div class="inbox-nav-heading"><h2>Sources</h2><a href="<?= $h($url(['panel'=>'sources'])) ?>" aria-label="Manage sources"><?php $icon('settings'); ?></a></div>
        <nav aria-label="Sources">
            <a href="<?= $h($filterUrl(['source'=>''])) ?>"<?= empty($list['source'])&&!$panel?' aria-current="page"':'' ?>><?php $icon('sources'); ?><span>All sources</span><small><?= array_sum($list['sources']??[]) ?></small></a>
            <?php foreach(['email'=>'Email replies','contact'=>'Contact forms','survey'=>'Survey responses','direct'=>'Direct messages']as$key=>$name): ?>
            <a href="<?= $h($filterUrl(['source'=>$key])) ?>"<?= ($list['source']??'')===$key&&!$panel?' aria-current="page"':'' ?>><?php $icon($key); ?><span><?= $h($name) ?></span><small><?= (int)($list['sources'][$key]??0) ?></small></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="inbox-nav-group">
        <div class="inbox-nav-heading"><h2>Labels</h2><a href="<?= $h($url(['panel'=>'labels'])) ?>" aria-label="Manage labels">+</a></div>
        <nav aria-label="Labels">
            <?php if(!$labels): ?><p class="inbox-muted">Keep related conversations together.<br><a href="<?= $h($url(['panel'=>'labels','new'=>1])) ?>">Create a label</a></p><?php endif; ?>
            <?php foreach($labels as$l): ?>
            <a href="<?= $h($filterUrl(['label'=>(int)($list['label']??0)===(int)$l['id']?0:(int)$l['id']])) ?>"<?= (int)($list['label']??0)===(int)$l['id']&&!$panel?' aria-current="page"':'' ?>><?php $icon('label'); ?><span><?= $h($l['name']) ?></span><small><?= (int)$l['message_count'] ?></small></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <nav class="inbox-bottom-nav" aria-label="Inbox tools">
        <a href="<?= $h($url(['panel'=>'templates'])) ?>"<?= $panel==='templates'?' aria-current="page"':'' ?>><?php $icon('note'); ?><span>Message templates</span></a>
        <a href="<?= $h($url(['panel'=>'notifications'])) ?>"<?= $panel==='notifications'?' aria-current="page"':'' ?>><?php $icon('settings'); ?><span>Notifications</span></a>
        <a href="<?= $h($url(['panel'=>'sources'])) ?>"<?= $panel==='sources'?' aria-current="page"':'' ?>><?php $icon('all'); ?><span>Manage sources</span></a>
    </nav>
</aside>
<?php if($draftWorkspace): ?>
<?php include __DIR__.'/inbox-drafts.php'; ?>
<?php elseif(!$panel): ?>
<section class="inbox-list" id="inbox-conversations" aria-label="Conversations">
    <header class="inbox-list-heading"><div><span class="inbox-eyebrow">CONVERSATIONS</span><h1><?= $h($statusNames[$list['status']]) ?><span><?= (int)$list['total'] ?></span></h1></div><a class="inbox-icon-button" href="<?= $h($filterUrl()) ?>" aria-label="Refresh messages" title="Refresh"><?php $icon('refresh'); ?></a></header>
    <form class="inbox-search inbox-search-manual" method="get">
        <input type="hidden" name="view" value="inbox">
        <?php foreach($baseFilters as$key=>$value)if(!in_array($key,['q','page'],true)): ?><input type="hidden" name="<?= $h($key) ?>" value="<?= $h($value) ?>"><?php endif; ?>
        <label class="sr-only" for="inbox-search">Search messages</label>
        <span class="inbox-search-field"><?php $icon('search'); ?><input id="inbox-search" type="search" name="q" placeholder="Search messages…" maxlength="100" value="<?= $h($list['q']??'') ?>" autocomplete="off"><kbd aria-hidden="true">/</kbd></span><button type="submit" data-mm-search-text="inbox-search">Search</button><a class="mm-clear-all" href="<?= $h('audience.php?'.http_build_query(['view'=>'inbox','status'=>$list['status']])) ?>">Clear all</a>
    </form>
    <div class="inbox-list-tools"><form method="get" class="inbox-unread-form"><input type="hidden" name="view" value="inbox"><?php foreach($baseFilters as$key=>$value)if($key!=='unread'): ?><input type="hidden" name="<?= $h($key) ?>" value="<?= $h($value) ?>"><?php endif; ?><button class="inbox-unread-filter" name="unread" value="<?= empty($list['unread'])?'1':'0' ?>" aria-pressed="<?= !empty($list['unread'])?'true':'false' ?>"><span class="inbox-switch-track" aria-hidden="true"><span></span></span><span>Unread only</span><strong class="inbox-filter-state"><?= !empty($list['unread'])?'On':'Off' ?></strong></button></form><span>Newest first</span></div>
    <?php if($filtered): ?><div class="inbox-active-filters"><span><?= !empty($list['source'])?$h($sourceNames[$list['source']]).' · ':'' ?><?= !empty($list['label'])?'Label · ':'' ?><?= !empty($list['q'])?'“'.$h($list['q']).'” · ':'' ?><?= (int)$list['total'] ?> result<?= $list['total']===1?'':'s' ?></span><a href="<?= $h('audience.php?'.http_build_query(['view'=>'inbox','status'=>$list['status']])) ?>">Clear filters</a></div><?php endif; ?>
    <?php if($list['status']==='trash'&&$canDelete&&$list['items']): ?><form id="inbox-trash-bulk" class="inbox-trash-bulk" method="post" data-admin-unlock-required data-confirm="Permanently delete the selected conversations and all their messages, drafts, and attachments from Inbox? This cannot be undone. Delivery receipts are kept."><?php $form('inbox_thread_bulk_purge'); ?><label><input type="checkbox" data-inbox-trash-all> Select all shown</label><span data-inbox-trash-count>0 selected</span><button type="submit" class="danger-text" data-admin-unlock-required data-inbox-trash-delete disabled>Delete selected permanently</button></form><?php endif; ?>
    <div class="inbox-thread-list">
        <?php foreach($list['items']as$item): $selected=$threadId===(int)$item['id']; ?>
        <?php if($list['status']==='trash'&&$canDelete): ?><div class="inbox-trash-row"><label class="inbox-trash-select"><input type="checkbox" name="threads[]" value="<?= (int)$item['id'].':'.(int)$item['version'] ?>" form="inbox-trash-bulk" data-inbox-trash-select aria-label="Select <?= $h($item['subject']) ?> for permanent deletion"></label><?php endif; ?>
        <a class="inbox-thread-item<?= $selected?' selected':'' ?><?= $item['unread']?' unread':'' ?>" href="<?= $h($filterUrl(['thread'=>(int)$item['id']])) ?>"<?= $selected?' aria-current="true"':'' ?>>
            <span class="inbox-avatar inbox-avatar-<?= $h($item['source']) ?>" aria-hidden="true"><?= $h($initials($item['sender_name']?:$item['sender_email'])) ?></span>
            <div class="inbox-thread-copy">
                <div class="inbox-thread-meta"><strong><?= $h($item['sender_name']?:$item['sender_email']) ?></strong><?php $time($item['last_activity_at'],true); ?></div>
                <div class="inbox-thread-subject"><?= $h($item['subject']) ?></div>
                <p><?= $item['direction']==='comment'?'Team note: ':($item['direction']==='outbound'?'You: ':'') ?><?= $h(mb_substr($item['preview']??'',0,140)) ?></p>
                <div class="inbox-thread-tags"><span class="inbox-source-tag"><?php $icon($item['source']); ?><?= $h($sourceNames[$item['source']]) ?></span><?php foreach(array_slice($item['labels'],0,1)as$name): ?><span class="inbox-label-tag"><?= $h($name) ?></span><?php endforeach; ?><?php if(count($item['labels'])>1): ?><span class="inbox-more-labels">+<?= count($item['labels'])-1 ?></span><?php endif; ?></div>
            </div>
            <?php if($item['unread']): ?><span class="inbox-unread-dot" role="img" aria-label="Unread"></span><?php endif; ?>
        </a>
        <?php if($list['status']==='trash'&&$canDelete): ?></div><?php endif; ?>
        <?php endforeach; ?>
        <?php if(!$list['items']): ?><div class="inbox-list-empty"><div class="inbox-empty-icon"><?php $icon($filtered?'search':($list['status']==='todo'?'done':'todo')); ?></div><h2><?= $filtered?'No matching conversations':($list['status']==='todo'?'All caught up':'No conversations here') ?></h2><p><?= $filtered?'Try another search or clear your filters.':($list['status']==='todo'?'New messages will appear here when they arrive.':'Conversations in this folder will appear here.') ?></p><?php if($filtered): ?><a href="<?= $h('audience.php?'.http_build_query(['view'=>'inbox','status'=>$list['status']])) ?>">Clear filters</a><?php endif; ?></div><?php endif; ?>
    </div>
    <div class="inbox-pane-resizer" role="separator" aria-orientation="vertical" aria-label="Resize conversations pane" aria-controls="inbox-conversations inbox-main" aria-valuemin="280" aria-valuemax="840" aria-valuenow="400" tabindex="0" title="Drag to resize · Arrow keys to adjust · Double-click to reset" data-inbox-divider></div>
</section>
<?php endif; ?>
