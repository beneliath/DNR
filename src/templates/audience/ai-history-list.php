<ul data-ai-history>
<?php foreach($historyRows as$item): ?>
    <li><button type="button" class="ai-history-open" aria-label="<?= $h($item['question']) ?>"><span><?= $h($item['question']) ?></span><small><?= $h(preg_replace('/\.\d+$/','',$item['created_at']).' UTC · '.$item['outcome']) ?></small></button>
        <?php if($aiConfig['history']['can_remove_history']): ?><button type="button" class="ai-history-remove" aria-label="Delete response: <?= $h($item['question']) ?>" title="Delete permanently"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg></button><?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
<p data-ai-history-empty<?= $historyRows?' hidden':'' ?>><?= $aiMode==='analytics'?'Start a conversation to see your recent chats.':'No recent requests' ?></p>
