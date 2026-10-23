<section class="inbox-list" id="inbox-conversations" aria-label="Conversations">
    <header class="inbox-list-heading"><div><span class="inbox-eyebrow">CONVERSATIONS</span><h1>Drafts<span><?= count($draftItems) ?></span></h1></div><a class="inbox-icon-button" href="<?= $h($draftUrl($draftId?['draft'=>$draftId]:[])) ?>" aria-label="Refresh drafts" title="Refresh drafts"><?php $icon('refresh'); ?></a></header>
    <form class="inbox-search" method="get"><input type="hidden" name="view" value="inbox"><input type="hidden" name="panel" value="drafts"><label class="sr-only" for="inbox-search">Search drafts</label><button aria-label="Search drafts"><?php $icon('search'); ?></button><input id="inbox-search" type="search" name="draft_q" placeholder="Search drafts…" maxlength="100" value="<?= $h($draftQuery) ?>" autocomplete="off"><kbd aria-hidden="true">/</kbd></form>
    <div class="inbox-list-tools"><span>Only visible to you</span><span>Last edited first</span></div>
    <?php if($draftQuery!==''): ?><div class="inbox-active-filters"><span><?= count($draftItems) ?> result<?= count($draftItems)===1?'':'s' ?> for “<?= $h($draftQuery) ?>”</span><a href="<?= $h($url(['panel'=>'drafts'])) ?>">Clear search</a></div><?php endif; ?>
    <div class="inbox-thread-list">
        <?php foreach($draftItems as$item): $selected=$draftId===(int)$item['id']; ?>
        <a class="inbox-thread-item inbox-draft-item<?= $selected?' selected':'' ?>" href="<?= $h($draftUrl(['draft'=>(int)$item['id']])) ?>" data-inbox-draft-id="<?= (int)$item['id'] ?>"<?= $selected?' aria-current="true"':'' ?>>
            <span class="inbox-avatar" aria-hidden="true"><?php $icon('draft'); ?></span>
            <div class="inbox-thread-copy"><div class="inbox-thread-meta"><strong><?= $h($item['recipient_name']?:$item['recipient_email']?:'No recipient') ?></strong><?php $time($item['updated_at'],true); ?></div><div class="inbox-thread-subject"><?= $h($item['subject']?:'(No subject)') ?></div><p><?= $h(mb_substr(audienceInboxPlain($item['preview_html']),0,140)) ?></p><div class="inbox-thread-tags"><span class="inbox-label-tag">Draft</span><span class="inbox-source-tag"><?= $h($item['recipient_email']) ?></span></div></div>
        </a>
        <?php endforeach; ?>
        <?php if(!$draftItems): ?><div class="inbox-list-empty"><div class="inbox-empty-icon"><?php $icon($draftQuery!==''?'search':'draft'); ?></div><h2><?= $draftQuery!==''?'No matching drafts':'No drafts yet' ?></h2><p><?= $draftQuery!==''?'Try another search or clear your search.':'Save a message to return to it here.' ?></p><?php if($draftQuery!==''): ?><a href="<?= $h($url(['panel'=>'drafts'])) ?>">Clear search</a><?php endif; ?></div><?php endif; ?>
    </div>
    <div class="inbox-pane-resizer" role="separator" aria-orientation="vertical" aria-label="Resize conversations pane" aria-controls="inbox-conversations inbox-main" aria-valuemin="280" aria-valuemax="840" aria-valuenow="400" tabindex="0" title="Drag to resize · Arrow keys to adjust · Double-click to reset" data-inbox-divider></div>
</section>
