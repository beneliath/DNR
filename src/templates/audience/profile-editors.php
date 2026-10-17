<?php foreach(['marketing'=>'Marketing status','details'=>'Edit connection details','groups'=>'Edit Groups','settings'=>'Edit settings'] as $section=>$editorTitle):
$editorContact=$contact;
$editorError=$error!==''&&($_POST['action']??'')==='save_profile_section'&&($_POST['section']??'')===$section;
if($editorError){
    foreach(['email','first_name','last_name','language','timezone'] as$key)if(isset($_POST[$key])&&is_string($_POST[$key]))$editorContact[$key]=$_POST[$key];
    if(isset($_POST['custom'])&&is_array($_POST['custom']))$editorContact['custom']=array_replace($contact['custom'],$_POST['custom']);
    if($section==='groups')$editorContact['group_ids']=array_map('intval',array_filter([...(array)($_POST['group_ids']??[]),...(array)($_POST['group_pick']??[])],fn($v)=>is_scalar($v)&&$v!==''));
}
?>
<dialog id="edit-<?= $section ?>" class="person-editor" aria-labelledby="edit-<?= $section ?>-title"<?= $editorError?' data-reopen-dialog':'' ?>>
    <form method="post" class="audience-edit-form">
        <?= $csrf ?><?php audienceInputs($baseForm+['action'=>'save_profile_section','section'=>$section,'id'=>$id,'version'=>$editorError?($_POST['version']??0):$contact['version']]); ?>
        <div class="dialog-heading"><h2 id="edit-<?= $section ?>-title"><?= $editorTitle ?></h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
        <?php if($editorError): ?><p class="audience-notice error" role="alert"><?= $h($error) ?></p><?php endif; ?>
        <div class="person-editor-body">
        <?php if($section==='marketing'): ?>
            <h3>Email</h3><label for="marketing-email">Email address</label><input id="marketing-email" name="email" type="email" maxlength="254" required value="<?= $h($editorContact['email']) ?>">
            <label class="audience-check person-consent"><input type="checkbox" name="email_consent" value="1"<?= ($editorError?!empty($_POST['email_consent']):$contact['email_status']==='subscribed')?' checked':'' ?>><span><strong>Email marketing consent</strong><span class="audience-help">This connection granted explicit consent to receive marketing-related emails.</span></span></label>
            <label for="marketing-consent">Permission record</label><textarea id="marketing-consent" name="consent_note" maxlength="1000" rows="3"><?= $h($editorError&&is_string($_POST['consent_note']??null)?$_POST['consent_note']:'') ?></textarea><p class="audience-help">For a new subscription or changed subscribed address, record when and how this connection gave permission.</p>
        <?php elseif($section==='details'): ?>
            <?php foreach(['first_name'=>'First Name','last_name'=>'Last Name'] as$key=>$label): ?><label for="details-<?= $key ?>"><?= $label ?></label><input id="details-<?= $key ?>" name="<?= $key ?>" maxlength="100" value="<?= $h($editorContact[$key]) ?>"><?php endforeach; ?>
            <?php foreach($fields as$field): audienceFieldControl($field,$editorContact['custom'][$field['id']]??$field['default_value']); endforeach; ?>
        <?php elseif($section==='groups'): ?>
            <?php $savedContact=$contact;$contact=$editorContact;include __DIR__.'/person-group-controls.php';$contact=$savedContact; ?>
            <?php if(!$groups): ?><p>No groups have been created yet.</p><a href="<?= $h($url(['view'=>'groups'])) ?>">Manage groups</a><?php endif; ?>
        <?php else: ?>
            <label for="settings-language">Language</label><select id="settings-language" name="language"><?php foreach(audienceLanguageOptions($editorContact['language'])as$code=>$label): ?><option value="<?= $h($code) ?>"<?= $editorContact['language']===$code?' selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select>
            <label for="settings-timezone">Time zone</label><select id="settings-timezone" name="timezone"><option value="">Not set</option><?php foreach(DateTimeZone::listIdentifiers()as$zone): ?><option value="<?= $h($zone) ?>"<?= $editorContact['timezone']===$zone?' selected':'' ?>><?= $h(str_replace('_',' ',$zone)) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        </div><div class="form-actions"><button type="button" data-close-dialog>Cancel</button><button class="primary">Save changes</button></div>
    </form>
</dialog>
<?php endforeach; ?>
