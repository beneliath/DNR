<form method="post" class="audience-contact-form audience-edit-form">
    <?= $csrf ?><?php audienceInputs($baseForm+['action'=>'save_person','id'=>$contact['id'],'version'=>$contact['version']]); ?>
    <fieldset<?= !$canManage?' disabled':'' ?>><legend class="sr-only">Connection information</legend>
    <div class="form-grid">
        <?php foreach(['first_name'=>'First Name','last_name'=>'Last Name']as$key=>$label): ?><div><label for="person-<?= $key ?>"><?= $label ?></label><input id="person-<?= $key ?>" name="<?= $key ?>" maxlength="100" value="<?= $h($contact[$key]) ?>"></div><?php endforeach; ?>
        <div class="span-two"><label for="person-email">Email Address *</label><input id="person-email" name="email" type="email" maxlength="254" value="<?= $h($contact['email']) ?>" required></div>
    </div>
    <details class="additional-person-details"<?= $error!==''?' open':'' ?>><summary>Additional connection details</summary>
    <div class="form-grid">
        <?php foreach($fields as$field): ?><div><?php audienceFieldControl($field,$contact['custom'][$field['id']]??$field['default_value']); ?></div><?php endforeach; ?>
    </div>
    <h3>Status</h3><label for="person-status">Subscription status</label><select id="person-status" name="email_status"><?php foreach(AUDIENCE_STATUSES as$status): if(in_array($status,['pending','cleaned'],true)&&$contact['email_status']!==$status)continue; ?><option value="<?= $status ?>"<?= $contact['email_status']===$status?' selected':'' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select>
    <label for="consent-note">Permission to send email</label><textarea id="consent-note" name="consent_note" maxlength="1000" rows="2" aria-describedby="consent-help"><?= $h($contact['consent_note']??'') ?></textarea><p class="audience-help" id="consent-help">For a new subscription, resubscription, or email change, record when and how this connection agreed to receive audience email.</p>
    <h3>Tags</h3><div class="choice-list"><?php foreach($tags as$tag): ?><label class="audience-check"><input type="checkbox" name="tag_ids[]" value="<?= (int)$tag['id'] ?>"<?= in_array((int)$tag['id'],array_map('intval',$contact['tag_ids']),true)?' checked':'' ?>><?= $h($tag['name']) ?></label><?php endforeach; ?></div><label for="new-tags">Add new tags</label><textarea id="new-tags" name="new_tags" rows="2" maxlength="5000" placeholder="One tag per line"><?= $h($error!==''&&is_scalar($_POST['new_tags']??null)?$_POST['new_tags']:'') ?></textarea>
    <?php if($groups): ?><h3>Groups</h3><?php include __DIR__.'/person-group-controls.php'; endif; ?>
    <h3>Settings</h3><div class="form-grid"><div><label for="person-language">Language</label><select id="person-language" name="language"><?php foreach(audienceLanguageOptions($contact['language'])as$code=>$label): ?><option value="<?= $h($code) ?>"<?= $contact['language']===$code?' selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select></div><div><label for="person-timezone">Time zone</label><select id="person-timezone" name="timezone"><option value="">Not set</option><?php foreach(DateTimeZone::listIdentifiers()as$zone): ?><option value="<?= $h($zone) ?>"<?= $contact['timezone']===$zone?' selected':'' ?>><?= $h(str_replace('_',' ',$zone)) ?></option><?php endforeach; ?></select></div></div>
    <p class="audience-help">Signup source: <?= $h($contact['source']) ?>. Acquisition history is kept automatically.</p>
    </details>
    <?php if($canManage): ?><div class="form-actions"><button class="primary">Save connection</button><?php if($id||($personModal??false)): ?><button type="button" data-close-dialog>Cancel</button><?php else: ?><a class="button" href="<?= $h($url()) ?>">Cancel</a><?php endif; ?></div><?php endif; ?>
    </fieldset>
</form>
