<h1>Manage sources</h1>
<div class="inbox-source-workspace">
<section class="inbox-routing-card" aria-labelledby="inbox-routing-title">
<span class="inbox-source-icon" aria-hidden="true">✉</span>
<h2 id="inbox-routing-title">Forward mail to Inbox</h2>
<p>Use this account’s receiving address for direct messages. Send a test from an external mailbox, such as Gmail or Outlook, and refresh this page to check receipt and sender authentication.</p>
<p>Preparing an address does not connect email sources or send an email. Test messages stay in Incoming Mail review until routing is connected.</p>
<?php if($canSources): ?>
<?php if(!$routing['configured']): ?><p class="inbox-gate">The receiving gateway is not configured for this installation. An administrator must configure its existing mailbox before preparing an address.</p>
<?php elseif($routing['address']===''): ?><form method="post"><?php $form('inbox_prepare_route'); ?><button class="primary">Prepare receiving address</button></form>
<?php else: ?><label>Account receiving address<input readonly value="<?= $h($routing['address']) ?>" aria-label="Inbox receiving test address" autocomplete="off"></label>
<div class="inbox-routing-result" role="status">
<?php if($routing['verified_at']): ?><strong>Authenticated message received</strong><p>Verified by the receiving gateway on <?= $h($routing['verified_at']) ?> UTC. You can confirm routing below, save your sources, and retry the held message from Incoming Mail.</p>
<?php elseif($routing['latest']): ?><strong>Message received — sender not verified</strong><p>The mailbox received a message on <?= $h($routing['latest']['received_at']) ?> UTC, but the receiving provider did not authenticate its sender. It stays in review. Try a message from an external mailbox with authenticated email.</p>
<?php else: ?><strong>Waiting for a receiving test</strong><p>Send a short message to the address above. No message has been recorded for this route yet.</p><?php endif; ?>
</div><a class="audience-button" href="<?= $h($url(['panel'=>'sources'])) ?>">Refresh receiving status</a>
<?php endif; ?>
<?php else: ?><p>An unrestricted administrator manages receiving addresses and sources.</p><?php endif; ?>
</section>
<h2 class="inbox-sources-heading">Add sources to help your audience reach you</h2>
<form method="post" class="inbox-settings inbox-sources-form"><?php $form('inbox_sources',['version'=>$settings['version']]); ?>
<fieldset<?= !$canSources?' disabled':'' ?>><legend class="sr-only">Inbox sources</legend>
<section class="inbox-source-card"><div class="inbox-source-copy"><span class="inbox-source-state"><?= $settings['gateway_confirmed']?'Confirmed by administrator':'Not connected' ?></span><h2>Email routing</h2><p>Confirm the signed receiving address reaches this account and the provider authenticates its sender. Replies remain subject to Email delivery readiness.</p></div><label class="inbox-check"><input type="checkbox" name="gateway_confirmed" value="1"<?= $settings['gateway_confirmed']?' checked':'' ?>>Confirm routing</label></section>
<section class="inbox-source-card"><div class="inbox-source-copy"><span class="inbox-source-state"><?= $settings['gateway_confirmed']&&$settings['campaign_replies']?'Connected':'Not connected' ?></span><h2>Campaign &amp; automation replies</h2><p>Receive replies from new campaign and flow emails. Previously sent email keeps its original reply address.</p></div><label class="inbox-check"><input type="checkbox" name="campaign_replies" value="1"<?= $settings['campaign_replies']?' checked':'' ?>>Connect email replies</label></section>
<section class="inbox-source-card"><div class="inbox-source-copy"><span class="inbox-source-state"><?= $settings['survey_replies']?'Connected':'Not connected' ?></span><h2>Reply to survey responses</h2><p>Collect native survey responses that include an email address and an open text answer. Signed recipient links identify existing connections.</p></div><label class="inbox-check"><input type="checkbox" name="survey_replies" value="1"<?= $settings['survey_replies']?' checked':'' ?>>Connect survey responses</label></section>
<section class="inbox-source-card inbox-contact-source"><div class="inbox-source-copy"><span class="inbox-source-state"><?= $settings['contact_enabled']?'Connected':'Not connected' ?></span><h2>Contact form</h2><p>Let visitors send you a message using a hosted page. Sending a message does not subscribe them to newsletters.</p></div><label class="inbox-check"><input type="checkbox" name="contact_enabled" value="1"<?= $settings['contact_enabled']?' checked':'' ?>>Enable contact form</label>
<details class="inbox-contact-design"><summary>Edit contact form</summary><?php foreach(['title'=>'Form title','intro'=>'Introduction','button'=>'Button text','success'=>'Success message']as$key=>$label): ?><label><?= $label ?><input name="<?= $key ?>" value="<?= $h($settings['document'][$key]) ?>" maxlength="<?= in_array($key,['intro','success'],true)?1000:120 ?>" required></label><?php endforeach; ?><?php if($settings['contact_enabled']): ?><a href="audience_inbox_contact.php?key=<?= $h($settings['contact_token']) ?>" target="_blank" rel="noopener">Open contact form ↗</a><?php endif; ?></details></section>
<div class="inbox-source-save"><button class="primary">Save sources</button><p>Saving source preferences does not send any email.</p></div>
</fieldset></form>
</div>
