<?php if($aiMode==='analytics'){include __DIR__.'/analytics-ai.php';}else{ ?>
<div class="analytics-ai-mode-switch"><?php include __DIR__.'/ai-mode-tabs.php'; ?></div>
<section class="ai-assistant analytics-ai-chat writing-ai-workspace" data-ai-root aria-label="Write with AI">
    <aside class="analytics-ai-history writing-ai-history" id="writing-ai-history" data-ai-history-panel aria-label="Request history" aria-describedby="writing-history-help">
        <button class="analytics-ai-new" type="button" data-ai-new><?php audienceNavIcon('rename'); ?>New request</button>
        <div class="ai-history-heading"><h2>Recent</h2><button type="button" data-ai-history-reset hidden disabled title="Reset this browser’s list; saved requests are retained for review">Reset list</button></div>
        <p id="writing-history-help" class="sr-only">Visible to you in your current role. Existing MOED AI review and retention policies apply.</p><ul data-ai-history></ul><p data-ai-history-empty>No recent requests</p>
    </aside>
    <div class="analytics-ai-main">
        <div class="analytics-ai-toolbar"><button type="button" data-ai-history-toggle aria-controls="writing-ai-history" aria-expanded="true"><?php audienceNavIcon('text'); ?><span>Hide request history</span></button></div>
        <div class="writing-ai-content"><div class="writing-ai-editor">
            <h2>Make your next message your own</h2><p>Draft, rewrite, adjust tone, or explore subject lines. Review facts and wording before using a suggestion.</p>
            <?php if($aiSourceName!==''): ?><p class="ai-scope">Working from a copy of <strong><?= $h($aiSourceName) ?></strong></p><?php endif; ?>
            <?php $aiFullPage=true;include __DIR__.'/ai-panel.php'; ?>
        </div></div>
    </div>
</section>
<?php } ?>
<dialog data-ai-history-confirm aria-labelledby="ai-history-confirm-title" aria-describedby="ai-history-confirm-message">
<form method="dialog"><h2 id="ai-history-confirm-title">Are you sure?</h2><p id="ai-history-confirm-message" data-ai-history-confirm-message></p><div class="dialog-actions"><button value="cancel" autofocus>Cancel</button><button value="confirm" class="primary" data-ai-history-confirm-submit>Delete permanently</button></div></form>
</dialog>
