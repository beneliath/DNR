<?php
$coach_page = aiCoachPage();
$coach_key = aiCoachStorageKey();
?>
<button type="button" class="coach-launcher button-secondary" data-coach-open aria-controls="moed-coach" aria-expanded="false">AI Coach</button>
<aside id="moed-coach" class="coach-panel" aria-labelledby="coach-title" hidden
    data-page-label="<?php echo htmlspecialchars(aiCoachPages()[$coach_page], ENT_QUOTES, 'UTF-8'); ?>"
    data-coach data-page="<?php echo htmlspecialchars($coach_page, ENT_QUOTES, 'UTF-8'); ?>"
    data-role="<?php echo htmlspecialchars((string) ($_SESSION['role'] ?? 'reviewer'), ENT_QUOTES, 'UTF-8'); ?>"
    data-storage-key="<?php echo $coach_key; ?>"
    data-endpoint="ai_coach.php" data-csrf-token="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <header class="coach-heading">
        <div><h2 id="coach-title">AI Coach</h2><p>Learn one step at a time</p></div>
        <button type="button" class="coach-close button-secondary" data-coach-close aria-label="Minimize AI Coach">−</button>
    </header>
    <div class="coach-context"><span data-coach-context>User Manual</span></div>
    <div class="coach-scroll">
        <section class="coach-welcome" data-coach-welcome>
            <h3>What would you like to learn?</h3>
            <p>Ask about MOED or start a walkthrough. You stay in control of every change.</p>
            <div class="coach-starters">
                <?php foreach (aiCoachWorkflows() as $id => $workflow): ?>
                <button type="button" class="button-secondary" data-coach-workflow="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($workflow['label'], ENT_QUOTES, 'UTF-8'); ?></button>
                <?php endforeach; ?>
            </div>
        </section>
        <div class="coach-messages" data-coach-messages role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions"></div>
        <section class="coach-step" data-coach-step hidden aria-label="Current Guided Step">
            <div class="coach-step-heading"><strong data-coach-workflow-title></strong><button type="button" class="button-secondary" data-coach-end>End Walkthrough</button></div>
            <p data-coach-step-message></p>
            <div class="coach-step-actions">
                <button type="button" class="button-primary" data-coach-show hidden>Show Me</button>
                <a class="button-primary" data-coach-go hidden></a>
                <button type="button" class="button-primary" data-coach-acknowledge hidden>I Have Reviewed the Details</button>
                <button type="button" class="button-secondary" data-coach-source>Read in Comprehensive Manual</button>
                <button type="button" class="button-secondary" data-coach-missing>I Can’t See That Control</button>
            </div>
            <p class="coach-step-note" data-coach-step-note>You make the changes; the coach follows your progress.</p>
        </section>
    </div>
    <div class="coach-compose">
        <p class="coach-request-status" data-coach-status role="status" aria-live="polite"></p>
        <form data-coach-form>
            <label for="coach-question">Ask the Coach</label>
            <textarea id="coach-question" name="question" aria-describedby="coach-key-hint" rows="2" maxlength="1200" placeholder="How do I…?" required></textarea>
            <p class="coach-key-hint" id="coach-key-hint">Enter to ask · Shift+Enter for a new line</p>
            <div class="coach-compose-actions">
                <button type="button" class="button-secondary" data-coach-reset>New Conversation</button>
                <button type="button" class="button-secondary" data-coach-stop hidden>Stop Answer</button>
                <button type="submit" class="button-primary" data-coach-send>Ask</button>
            </div>
        </form>
        <p class="coach-privacy">Questions and responses are recorded for administrator review to improve guidance. Do not include passwords. The coach does not collect page-field contents.</p>
    </div>
    <script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" data-coach-steps><?php echo json_encode(aiCoachSteps(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
    <script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" data-coach-workflows><?php echo json_encode(aiCoachBrowserWorkflows(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
    <script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" data-coach-controls><?php echo json_encode(aiCoachControlCatalog(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
</aside>
