<?php if($canManage): ?>
<?php if($view==='automations'&&!$trash): ?>
<dialog id="flow-rename" class="audience-wide-dialog" aria-labelledby="flow-rename-title"<?= !empty($renameRecovery)?' data-reopen-dialog':'' ?>>
    <div class="dialog-heading"><h2 id="flow-rename-title">Rename Automation</h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
    <form method="post">
        <?= $csrf ?><?php audienceInputs(['action'=>'flow_operation','operation'=>'rename','id'=>$renameRecovery['id']??'','version'=>$renameRecovery['version']??'']); ?>
        <?php if(!empty($renameRecovery)): ?><p role="alert" class="audience-notice error" data-flow-rename-error><?= $h($error) ?></p><?php endif; ?>
        <label for="flow-rename-name">Flow Name</label>
        <input id="flow-rename-name" name="name" maxlength="100" required autocomplete="off" autofocus value="<?= $h($renameRecovery['name']??'') ?>">
        <div class="dialog-actions"><button type="button" data-close-dialog>Cancel</button><button type="submit" class="primary">Save Name</button></div>
    </form>
</dialog>
<?php endif; ?>
<dialog id="flow-archive" class="audience-wide-dialog" aria-labelledby="flow-archive-title"><div class="dialog-heading"><h2 id="flow-archive-title">Archive Flow</h2><button type="button" data-close-dialog aria-label="Close">×</button></div><form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'flow_operation','operation'=>'archive','id'=>'','version'=>'']); ?><p>Archive <strong data-flow-lifecycle-name></strong> and stop its queued actions? Its saved revisions and execution history are kept. Restoring creates a draft.</p><div class="dialog-actions"><button type="button" data-close-dialog>Cancel</button><button class="primary">Archive</button></div></form></dialog>
<?php if($canDelete): ?>
<dialog id="flow-delete" class="audience-wide-dialog" aria-labelledby="flow-delete-title"><div class="dialog-heading"><h2 id="flow-delete-title">Permanently Delete Flow</h2><button type="button" data-close-dialog aria-label="Close">×</button></div><form method="post" data-flow-delete-form data-admin-unlock-required><?= $csrf ?><?php audienceInputs(['action'=>'flow_operation','operation'=>'delete','id'=>'','version'=>'']); ?><p>Permanently delete <strong data-flow-lifecycle-name></strong>, its saved revisions and execution history? This cannot be undone. Connections, email delivery receipts and unsubscribe links are kept.</p><label>Type DELETE to Confirm<input name="confirm" autocomplete="off" spellcheck="false" required pattern="DELETE" maxlength="10"></label><div class="dialog-actions"><button type="button" data-close-dialog>Cancel</button><button class="danger-text">Permanently Delete</button></div></form></dialog>
<?php endif; ?>
<?php endif; ?>
