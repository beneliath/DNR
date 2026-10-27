<?php
/** @var bool $canManage Permission supplied by renderAudienceManual(). */
/** @var bool $canDelete Permission supplied by renderAudienceManual(). */
?>
<div class="audience-manual" id="manual-top">
    <section class="manual-intro" aria-labelledby="manual-intro-title">
        <div><p class="manual-eyebrow">MESSAGEMONKEY GUIDE</p><h2 id="manual-intro-title">From your first connection to your next campaign.</h2><p>Practical steps for managing your audience, creating messages, and understanding the results. Browse the chapters or search for what you need.</p></div>
        <aside class="manual-role" aria-label="Your current access"><span>Your current role</span><strong><?= $h($manualRole) ?></strong><p><?= $h($manualRoleSummary) ?></p><a href="#manual-roles">Understand roles and access →</a></aside>
    </section>

    <p class="manual-pdf-link"><a href="<?= $h(assetUrl('assets/docs/message-monkey-comprehensive-user-guide.pdf')) ?>" target="_blank" rel="noopener" aria-label="View Comprehensive User Guide (PDF, opens in a new tab)">View Comprehensive User Guide <span aria-hidden="true">↗</span></a><span>PDF · Illustrated walkthroughs and offline reference</span></p>

    <form class="manual-search" role="search" aria-label="Search the user manual" data-manual-search-form>
        <label for="manual-search-input">Search the manual</label>
        <div class="manual-search-row"><div class="manual-search-input"><?php audienceNavIcon('search'); ?><input id="manual-search-input" type="search" placeholder="Try “import”, “schedule”, or “unsubscribe”" autocomplete="off" aria-describedby="manual-search-help manual-search-status" data-manual-search></div><button type="button" data-manual-clear hidden>Clear</button></div>
        <div class="manual-search-meta"><span id="manual-search-status" role="status" aria-live="polite" data-manual-status>Showing all <?= count($manualChapters) ?> chapters.</span><span id="manual-search-help">Press <kbd>/</kbd> to search · <kbd>Enter</kbd> for the first match</span></div>
        <section class="manual-results" data-manual-results hidden aria-labelledby="manual-results-title"><h2 id="manual-results-title">Matching topics</h2><ol data-manual-result-list></ol></section>
    </form>

    <nav class="manual-quick-links" aria-label="Popular manual topics"><?php foreach([['campaign-draft','mail','Create an email','Draft, test, and send'],['import-connections','audience','Import connections','Map, review, and import'],['publish-form','form','Publish a form','Build, preview, and share'],['blocked-send','testing','Fix a sending problem','Readiness and delivery']] as [$quickId,$quickIcon,$quickTitle,$quickDescription]): ?><a href="#manual-<?= $h($quickId) ?>"><span class="manual-quick-icon" aria-hidden="true"><?php audienceNavIcon($quickIcon); ?></span><span><strong><?= $h($quickTitle) ?></strong><small><?= $h($quickDescription) ?></small></span></a><?php endforeach; ?></nav>

    <div class="manual-shell">
        <nav class="manual-toc" aria-label="Manual chapters"><h2>In this guide</h2><ol><?php foreach($manualChapters as $index=>$chapter): ?><li><a href="#manual-<?= $h($chapter['id']) ?>" data-manual-toc><span class="manual-chapter-number" aria-hidden="true"><?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?></span><span><?= $h($chapter['title']) ?></span></a></li><?php endforeach; ?></ol><a class="manual-toc-top" href="#manual-top">Back to search ↑</a></nav>
        <div class="manual-chapters">
            <section class="manual-empty" data-manual-empty hidden><h2>No matching topics</h2><p>Try a shorter phrase, a page name, or one task such as “send” or “tags”.</p><button type="button" data-manual-empty-clear>Show all chapters</button></section>
            <?php foreach($manualChapters as $index=>$chapter): ?>
            <section class="manual-chapter" id="manual-<?= $h($chapter['id']) ?>" data-manual-section aria-labelledby="manual-<?= $h($chapter['id']) ?>-title">
                <header><p class="manual-eyebrow" aria-hidden="true">CHAPTER <?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?></p><h2 id="manual-<?= $h($chapter['id']) ?>-title"><?= $h($chapter['title']) ?></h2><p class="manual-chapter-summary"><?= $h($chapter['summary']) ?></p></header>
                <?php $manualLinks=array_filter($chapter['links'],static fn(array $link):bool=>!isset($link[2])||($link[2]==='manage'&&$canManage)||($link[2]==='admin'&&$canDelete));if($manualLinks): ?>
                <nav class="manual-screen-links" aria-label="<?= $h($chapter['title']) ?> pages"><?php foreach($manualLinks as [$label,$params]): ?><a href="<?= $h($url($params)) ?>"><?= $h($label) ?><span aria-hidden="true"> ↗</span></a><?php endforeach; ?></nav>
                <?php endif; ?>
                <?php include __DIR__.'/manual-visuals.php'; ?>
                <?php foreach($chapter['topics'] as [$topicId,$topicTitle,$topicBody]): ?>
                <div class="manual-topic"><h3 id="manual-<?= $h($topicId) ?>"><?= $h($topicTitle) ?></h3><?= str_replace('<ol>','<ol class="manual-steps" role="list">',$topicBody) ?></div>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
        </div>
    </div>
    <nav class="manual-page-nav" id="manual-end" aria-label="Manual page navigation"><a href="#manual-top">↑ Back to search</a><a href="#manual-troubleshooting">Troubleshooting</a></nav>
</div>
