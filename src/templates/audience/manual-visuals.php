<?php
// Short, accessible visual summaries complement the detailed procedures below.
$manualVisuals = [
    'getting-started'=>['Your communication cycle',[
        ['audience','Organize','Keep connections and preferences accurate.'],
        ['rename','Create','Prepare a campaign, form, or flow.'],
        ['mail','Connect','Review, then publish or send.'],
        ['chart','Learn','Use results to plan the next message.'],
    ]],
    'roles'=>['Choose the tools your role provides',[
        ['search','Reviewer','Explore records and understand results.'],
        ['rename','Editor','Create content and manage audience work.'],
        ['testing','Administrator','Configure delivery and protected actions.'],
    ]],
    'organization'=>['Three ways to organize your audience',[
        ['text','Tags','Internal labels you apply.'],
        ['audience','Groups','Choices within an interest category.'],
        ['filters','Segments','Rules that find matching connections.'],
    ]],
    'campaigns'=>['From draft to results',[
        ['rename','Draft','Choose recipients and prepare the content.'],
        ['testing','Preview & test','Check the message and its links.'],
        ['mail','Approve & send','Confirm the send or schedule.'],
        ['chart','Review results','Follow delivery and engagement.'],
    ]],
    'sending'=>['Read each delivery stage separately',[
        ['list','Queued','Waiting for processing.'],
        ['mail','Accepted','The provider has accepted the message.'],
        ['chart','Reported outcome','Review delivery, bounces, or failures.'],
    ]],
    'automations'=>['A simple flow at a glance',[
        ['audience','Entry trigger','A qualifying connection enters.'],
        ['flow','Rules & delays','The flow decides what happens next.'],
        ['mail','Action','A configured step runs.'],
        ['chart','Results','Review the execution outcomes.'],
    ]],
    'forms'=>['From signup page to audience insight',[
        ['form','Build & preview','Set fields, wording, and appearance.'],
        ['web','Publish & share','Use the public URL or embed instructions.'],
        ['chart','Review results','Distinguish visits, submissions, and signups.'],
    ]],
    'inbox'=>['A conversation’s working path',[
        ['list','To do','Read the message and decide on follow-up.'],
        ['rename','Draft or reply','Save unfinished work or send a response.'],
        ['testing','Done','Mark the conversation complete.'],
    ]],
    'ai'=>['You review the final message',[
        ['text','Provide context','Describe the goal and facts.'],
        ['rename','Get suggestions','Generate or refine the wording.'],
        ['testing','Review','Check facts, tone, links, and merge tags.'],
        ['mail','Use in a draft','Save and run the usual sending checks.'],
    ]],
];
$manualVisual=$manualVisuals[$chapter['id']]??null;
if($manualVisual):
    [$visualTitle,$visualCards]=$manualVisual;
    $isSequence=!in_array($chapter['id'],['roles','organization'],true);
?>
<figure class="manual-visual<?= $isSequence?' manual-visual-sequence':'' ?>" aria-labelledby="manual-visual-<?= $h($chapter['id']) ?>">
    <figcaption id="manual-visual-<?= $h($chapter['id']) ?>"><?= $h($visualTitle) ?></figcaption>
    <ol class="manual-visual-cards<?= count($visualCards)===3?' manual-visual-three':'' ?>" role="list"><?php foreach($visualCards as $visualIndex=>[$visualIcon,$visualLabel,$visualDescription]): $isCurrentRole=$chapter['id']==='roles'&&$visualLabel===$manualRole; ?>
        <li class="manual-visual-card<?= $isCurrentRole?' is-current-role':'' ?>"><div class="manual-card-top"><span class="manual-card-icon" aria-hidden="true"><?php audienceNavIcon($visualIcon); ?></span><?php if($isCurrentRole): ?><span class="manual-current-role">Your role</span><?php elseif($isSequence): ?><span class="manual-card-number" aria-hidden="true"><?= str_pad((string)($visualIndex+1),2,'0',STR_PAD_LEFT) ?></span><?php endif; ?></div><strong><?= $h($visualLabel) ?></strong><p><?= $h($visualDescription) ?></p></li>
    <?php endforeach; ?></ol>
</figure>
<?php endif; ?>
<?php if($chapter['id']==='getting-started'): ?>
<figure class="manual-action-legend" aria-labelledby="manual-action-legend-title"><figcaption id="manual-action-legend-title">Recognize the action icons</figcaption><ul role="list">
    <?php foreach(['results'=>['Results','Read performance','report'],'edit'=>['Edit','Change content','edit'],'duplicate'=>['Duplicate','Make a new copy','edit'],'archive'=>['Archive','Keep for later','archive'],'restore'=>['Restore','Bring back','restore'],'delete'=>['Delete','Remove permanently','delete']] as $legendIcon=>[$legendLabel,$legendDescription,$legendTone]): ?>
    <li><span class="manual-legend-icon manual-legend-<?= $h($legendTone) ?>" aria-hidden="true"><?= actionIconSvg($legendIcon) ?></span><strong><?= $h($legendLabel) ?></strong><small><?= $h($legendDescription) ?></small></li>
    <?php endforeach; ?>
</ul></figure>
<?php elseif($chapter['id']==='roles'): ?>
<aside class="manual-callout"><span class="manual-unlock-sample" aria-hidden="true"><?= actionIconSvg('delete') ?></span><div><strong>Orange outline = Admin Unlock required</strong><p>Unlock first, then review the action’s confirmation. Permanent deletion cannot be undone.</p></div></aside>
<?php elseif($chapter['id']==='sending'): ?>
<aside class="manual-callout"><span class="manual-callout-symbol" aria-hidden="true">!</span><div><strong>Accepted does not mean delivered</strong><p>Follow the reported outcome before deciding whether a message needs further attention.</p></div></aside>
<?php elseif($chapter['id']==='ai'): ?>
<aside class="manual-callout"><span class="manual-callout-symbol" aria-hidden="true">✓</span><div><strong>Keep a human review before sending</strong><p>An AI suggestion does not publish, schedule, or send a message.</p></div></aside>
<?php endif; ?>
