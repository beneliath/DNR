<?php
$templates=audienceFlowTemplates();$topics=array_values(array_unique(array_column($templates,'topic')));sort($topics);
$families=['welcome'=>'Find & welcome new connections','nurture'=>'Nurture relationships','reengage'=>'Re-engage connections','manage'=>'Support and manage connections'];
?>
<section class="flow-catalog-hero" data-flow-catalog-hero>
    <div class="flow-hero-art" aria-hidden="true">
        <svg viewBox="0 0 360 224" focusable="false">
            <circle class="flow-hero-halo" cx="180" cy="110" r="99"/>
            <path class="flow-hero-connector" d="M180 62v28m0 64v26"/>
            <rect class="flow-hero-card" x="102" y="18" width="156" height="44" rx="10"/>
            <svg class="flow-hero-icon" x="114" y="28" width="24" height="24" viewBox="0 0 24 24"><path d="<?= audienceFlowIconPaths()['group'] ?>"/></svg>
            <path class="flow-hero-placeholder" d="M153 34h76"/>
            <path class="flow-hero-placeholder flow-hero-secondary" d="M153 46h49"/>
            <rect class="flow-hero-card" x="48" y="90" width="264" height="64" rx="12"/>
            <rect class="flow-hero-icon-tile" x="62" y="103" width="38" height="38" rx="10"/>
            <svg class="flow-hero-icon" x="69" y="110" width="24" height="24" viewBox="0 0 24 24"><path d="<?= audienceFlowIconPaths()['email'] ?>"/></svg>
            <path class="flow-hero-placeholder" d="M116 115h132"/>
            <path class="flow-hero-placeholder flow-hero-secondary" d="M116 131h92"/>
            <circle class="flow-hero-complete" cx="180" cy="198" r="18"/>
            <path class="flow-hero-check" d="m173 198 5 5 10-11"/>
        </svg>
    </div>
    <div><h2>Turn audience activity into a personal next step</h2><p>Welcome subscribers, follow up on real engagement, and keep your connections organized with ready-to-build flows.</p><div class="audience-actions"><a class="audience-button primary" href="<?= $h($url(['view'=>'forms'])) ?>">Explore signup forms</a><button type="button" data-flow-dismiss-hero>Dismiss</button></div></div>
</section>
<div class="flow-catalog-controls mm-search-row" data-flow-catalog-controls>
    <label class="flow-catalog-search"><span class="sr-only">Search templates</span><input type="search" data-flow-template-search maxlength="100" placeholder="Search templates by name, topic, or integration" autocomplete="off" value="<?= $h($q) ?>"></label>
    <label>Channels: <select data-flow-template-channel><option value="">All</option><option value="email">Email</option><option value="data">Audience actions only</option></select></label>
    <label>Topics: <select data-flow-template-topic><option value="">All</option><?php foreach($topics as$topic): ?><option><?= $h($topic) ?></option><?php endforeach; ?></select></label>
    <label>Apps &amp; Integrations: <select data-flow-template-integration><option value="">All</option><option value="audience">MessageMonkey audience</option><option value="forms">MOED forms</option></select></label>
    <label class="flow-catalog-sort">Sort By: <select data-flow-template-sort><option value="recommended">Recommended</option><option value="name">Name A–Z</option></select></label>
    <a class="mm-clear-all" data-flow-template-clear href="<?= $h($url(['view'=>'flow-templates'])) ?>" hidden>Clear all</a>
</div>
<p class="flow-catalog-count" data-flow-template-count role="status" aria-live="polite"><?= count($templates) ?> flow templates</p>
<section class="flow-template-section" data-flow-template-section="recommended"><h2>Try these recommended flows</h2><p>Native starting points you can customize. Creating a flow saves a draft; it does not activate sending.</p><div class="flow-template-grid flow-featured-grid"><?php foreach($templates as$key=>$template)if($template['recommended'])audienceFlowTemplateCard($key,$template,$url,true); ?></div></section>
<?php foreach($families as$family=>$heading): ?><section class="flow-template-section" data-flow-template-section="<?= $family ?>"><h2><?= $h($heading) ?></h2><div class="flow-template-grid"><?php foreach($templates as$key=>$template)if($template['family']===$family)audienceFlowTemplateCard($key,$template,$url); ?></div></section><?php endforeach; ?>
<div class="flow-catalog-empty" data-flow-template-empty hidden><h2>No flow templates match these filters</h2><p>Try another topic or clear your filters to see all native templates.</p><a class="mm-clear-all" data-flow-template-clear href="<?= $h($url(['view'=>'flow-templates'])) ?>">Clear all</a></div>
<details class="flow-native-scope"><summary>About available channels and integrations</summary><p>These templates use MessageMonkey audience events, email, and MOED forms. Commerce, SMS, third-party app connections, and A/B testing are not implied by this catalog.</p></details>
