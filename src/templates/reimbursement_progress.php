<?php
$flowSteps = ['Select Expenses', 'Review Request', 'Review Email', 'Submit'];
$flowStep = (int) ($flowStep ?? 1);
$flowRequestId = (int) ($flowRequestId ?? 0);
$flowSubmitted = !empty($flowSubmitted);
?>
<nav class="reimbursement-progress" aria-label="Reimbursement progress">
    <ol>
    <?php foreach ($flowSteps as $index => $label): $number = $index + 1;
        $url = $number === 1 && $flowStep === 1 ? '#reimbursement-create-form'
            : ($number === 2 && $flowRequestId > 0 ? 'reimbursement_request.php?id=' . $flowRequestId : ''); ?>
        <li class="<?= $number < $flowStep || $flowSubmitted ? 'is-complete' : '' ?>"<?= $number === $flowStep ? ' aria-current="step"' : '' ?>>
            <span class="progress-number" aria-hidden="true"><?= $number < $flowStep || $flowSubmitted ? '✓' : $number ?></span>
            <?php if ($url !== '' && $number < $flowStep): ?><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a><?php else: ?><span><?= $flowSubmitted && $number === 4 ? 'Submitted' : $label ?></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ol>
    <p class="reimbursement-progress-total" data-flow-selection role="status"><?= isset($flowCount) ? (int) $flowCount . ' expenses · ' . reimbursementMoney((int) $flowTotal) : 'Select expenses to see your request total' ?></p>
</nav>
<?php unset($flowStep, $flowRequestId, $flowSubmitted, $flowCount, $flowTotal); ?>
