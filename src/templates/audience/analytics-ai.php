<?php
$aiStarters=[
    ['question'=>'Summarize the performance of my campaigns in last 30 days','dataset'=>'email'],
    ['question'=>'What are the top clicked links in my last 3 campaigns?','dataset'=>'email'],
    ['question'=>'How did my last email perform compared to similar emails and to benchmarks?','dataset'=>'email'],
    ['question'=>'How has my contact list changed / trended in the last 90 days?','dataset'=>'audience'],
    ['question'=>'How can I improve my email performance?','dataset'=>'email'],
];
?>
<section class="ai-assistant analytics-ai-chat" data-ai-root data-ai-chat aria-label="Analytics AI">
    <aside class="analytics-ai-history" id="analytics-ai-history" aria-label="Chat history">
        <button class="analytics-ai-new" type="button" data-ai-new><?php audienceNavIcon('rename'); ?>New chat</button>
        <div class="ai-history-heading"><h2>Recent</h2><button type="button" data-ai-history-reset data-admin-unlock-required hidden disabled title="Reset this browser’s list (Admin Unlock required); saved requests are retained for review">Reset list</button></div><ul data-ai-history></ul><p data-ai-history-empty>Start a conversation to see your recent chats.</p>
    </aside>
    <div class="analytics-ai-main">
        <div class="analytics-ai-toolbar"><button type="button" data-ai-history-toggle aria-controls="analytics-ai-history" aria-expanded="true"><?php audienceNavIcon('text'); ?><span>Hide chat history</span></button><details class="analytics-ai-scope"><summary>Report scope</summary><p data-ai-chat-scope><?= $h($aiWindow['start'].' to '.$aiWindow['end'].' UTC · '.$aiWindow['dataset']) ?></p><a href="<?= $h($url(['view'=>'analytics']+$aiWindow)) ?>">Change report filters</a><p>Inbox messages are excluded.</p></details></div>
        <div class="analytics-ai-conversation">
            <div class="analytics-ai-welcome" data-ai-welcome><div><svg class="analytics-ai-mark" viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M24 13v30M9 28h30M13 17l22 22M35 17 13 39" stroke="currentColor" stroke-width="5"/><circle cx="24" cy="7" r="4" fill="currentColor"/></svg><h2>Hi,</h2><p>I can answer data-related questions and help you analyze your data.</p></div></div>
            <div class="analytics-ai-transcript" data-ai-chat-transcript role="log" aria-label="Analytics AI conversation" aria-live="polite" hidden></div>
            <div class="ai-thinking analytics-ai-thinking" data-ai-thinking hidden aria-hidden="true"><span class="ai-thinking-dots"><i></i><i></i><i></i></span><span>Analytics AI is working on your response…</span></div>
            <section data-ai-result hidden><div data-ai-analysis></div><div data-ai-recommendations></div></section><section data-ai-check-result hidden></section>
        </div>
        <div class="analytics-ai-compose-area">
            <div class="analytics-ai-suggestions" data-ai-chat-suggestions aria-label="Suggested questions"><?php foreach($aiStarters as$starter): $starterWindow=['dataset'=>$starter['dataset']]; ?><button type="button" data-ai-starter data-ai-window="<?= $h(json_encode($starterWindow,JSON_THROW_ON_ERROR)) ?>"><?= $h($starter['question']) ?></button><?php endforeach; ?></div>
            <form class="analytics-ai-composer" data-ai-form method="post"><?= csrfInput() ?><label class="sr-only" for="analytics-ai-question">Ask anything about your communication performance</label><textarea id="analytics-ai-question" name="prompt" rows="1" maxlength="1500" required placeholder="Ask anything about your communication performance"<?= $aiPrompt!==''?' autofocus':'' ?>><?= $h($aiPrompt) ?></textarea><button type="submit" data-ai-generate aria-label="Ask Analytics AI" title="Ask Analytics AI"<?= !$aiConfig['enabled']?' disabled':'' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 3 17 9-17 9 3-9-3-9Zm3 9h14"/></svg></button><button type="button" data-ai-stop hidden>Stop</button></form>
            <p data-ai-status role="status" aria-live="polite"></p>
            <?php if(!$aiConfig['enabled']): ?><p class="audience-notice">AI assistance is not enabled for this deployment.</p><?php endif; ?>
            <p class="analytics-ai-disclosure">AI can make mistakes. Review answers against the source calculations.</p>
        </div>
    </div>
    <div class="analytics-ai-footer"><?php include __DIR__ . '/../app-footer.php'; ?></div>
    <script type="application/json" id="audience-ai-config"><?= json_encode($aiConfig,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</section>
