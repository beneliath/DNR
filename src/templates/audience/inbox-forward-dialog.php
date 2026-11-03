<?php
$forwardFailed = $error !== '' && ($_POST['action'] ?? '') === 'inbox_forward';
$selectedUsers = $forwardFailed && is_array($_POST['recipient_ids'] ?? null) ? $_POST['recipient_ids'] : [];
$selectedMessage = $forwardFailed ? (int)($_POST['message_id'] ?? 0) : (int)$forwardMessages[0]['id'];
$forwardKey = $forwardFailed && is_string($_POST['forward_key'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $_POST['forward_key'])
    ? $_POST['forward_key'] : bin2hex(random_bytes(16));
?>
<dialog id="inbox-forward-dialog" aria-labelledby="inbox-forward-title"<?= $forwardFailed?' data-reopen-dialog':'' ?>>
    <form method="post" action="<?= $h($filterUrl(['thread'=>$threadId,'message_page'=>$messagePage,'message_size'=>$messageSize,'before'=>$before])) ?>">
        <?php $form('inbox_forward',['thread_id'=>$threadId,'forward_key'=>$forwardKey]); ?>
        <div class="dialog-heading"><h2 id="inbox-forward-title">Forward email</h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
        <?php if(count($forwardMessages)===1): $original=$forwardMessages[0]; ?>
            <input type="hidden" name="message_id" value="<?= (int)$original['id'] ?>">
            <p><strong><?= $h($original['email_subject']) ?></strong><br><?= $h($original['sender_email']) ?> · <?php $time($original['occurred_at']); ?></p>
        <?php else: ?>
            <label>Email to forward
                <select name="message_id"><?php foreach($forwardMessages as $original): ?>
                    <option value="<?= (int)$original['id'] ?>"<?= $selectedMessage===(int)$original['id']?' selected':'' ?>><?= $h($original['email_subject'].' · '.audienceProfileDate($original['occurred_at']).' · '.mb_substr($original['body_text'],0,60)) ?></option>
                <?php endforeach; ?></select>
            </label>
        <?php endif; ?>
        <fieldset><legend>Recipients</legend>
            <?php if($forwardUsers): ?>
                <p>Choose up to 25 MOED users. Each receives a separate copy with the original attachments.</p>
                <div class="inbox-forward-users"><?php foreach($forwardUsers as $user): ?>
                    <label class="inbox-check"><input type="checkbox" name="recipient_ids[]" value="<?= (int)$user['id'] ?>"<?= in_array((string)$user['id'],$selectedUsers,true)?' checked':'' ?>>
                        <span><strong><?= $h($user['username']) ?></strong><small><?= $h($user['email']) ?></small></span>
                    </label>
                <?php endforeach; ?></div>
            <?php else: ?><p>No active MOED users in this account have a verified email address.</p><?php endif; ?>
        </fieldset>
        <?php if($forwardRequirement!==null): ?><p class="inbox-error"><?= $h($forwardRequirement) ?></p><?php endif; ?>
        <?php if($forwardFailed): ?><p class="inbox-error" role="alert"><?= $h($error) ?></p><?php endif; ?>
        <div class="form-actions"><button type="button" data-close-dialog>Cancel</button>
            <button type="submit" class="primary"<?= !$forwardUsers||$forwardRequirement!==null?' disabled':' data-forward-submit' ?>><?php $icon('forward'); ?>Forward email</button>
        </div>
    </form>
</dialog>
