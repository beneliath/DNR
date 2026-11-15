<dialog id="engagement-cancel-dialog" class="confirmation-dialog" aria-labelledby="engagement-cancel-title" aria-describedby="engagement-cancel-message">
    <form method="post" action="engagements.php" id="engagement-cancel-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="engagement_id" value="">
        <input type="hidden" name="expected_version" value="">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($list_current_url, ENT_QUOTES, 'UTF-8'); ?>">
        <h2 id="engagement-cancel-title">Are You Sure You Want to Cancel This Engagement?</h2>
        <p id="engagement-cancel-name"></p>
        <p id="engagement-cancel-message">This changes the lifecycle to Canceled and archives all associated tasks, including completed and pending tasks.</p>
        <label for="engagement-cancel-reason" class="confirmation-dialog-label">Cancellation Reason</label>
        <textarea id="engagement-cancel-reason" name="cancellation_reason" class="confirmation-dialog-input" rows="3" maxlength="1000" required></textarea>
        <label for="engagement-cancel-confirmation" class="confirmation-dialog-label">Type <code>CANCEL</code> to Confirm</label>
        <input id="engagement-cancel-confirmation" name="cancel_confirmation" class="confirmation-dialog-input" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" pattern="CANCEL" required aria-describedby="engagement-cancel-help">
        <p id="engagement-cancel-help" class="dialog-supporting-text">The word must match exactly.</p>
        <div class="confirmation-dialog-actions">
            <button type="button" class="button-secondary" data-keep-engagement>Keep Engagement</button>
            <button type="submit" class="danger-button" data-confirm-engagement-cancel disabled>Cancel Engagement</button>
        </div>
    </form>
</dialog>
