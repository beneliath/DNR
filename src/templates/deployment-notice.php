<?php if (!empty($_SESSION['user_id'])): ?>
<section class="deployment-notice-banner" data-deployment-notice data-status-url="deployment_status.php" aria-label="Deployment Notice" hidden>
    <div class="deployment-notice-heading">
        <strong data-deployment-title role="status" aria-live="polite"></strong>
        <span data-deployment-timer role="timer" aria-live="off"></span>
    </div>
    <span data-deployment-detail></span>
</section>
<?php renderScript('assets/js/deployment-notice.min.js'); ?>
<?php endif; ?>
