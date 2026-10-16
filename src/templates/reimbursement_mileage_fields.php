<?php /* Shared expense form supplies escaped values, permissions, and the stored rate. */ ?>
<label>Date <input type="date" name="expense_date" value="<?= reimbursementExpenseH($expense['expense_date']) ?>" required<?= !$canEdit ? ' disabled' : '' ?>></label>
<div data-mileage-calculation data-mileage-rate="<?= reimbursementExpenseH($mileageRate) ?>">
<input type="hidden" name="mileage_rate" value="<?= reimbursementExpenseH($mileageRate) ?>">
<p><strong>Reimbursement Per Mile:</strong> $<?= reimbursementExpenseH($mileageRate) ?></p>
<p data-mileage-total role="status" aria-live="polite"><?= ($expense['odometer_end'] === null || $expense['odometer_end'] === '') ? 'Incomplete · Ending Odometer Reading required before reimbursement.' : ($id ? 'Reimbursement: ' . reimbursementMoney((int) $expense['amount_cents']) : 'Enter odometer readings to calculate mileage and reimbursement.') ?></p>
<p class="field-help">Miles = ending − starting odometer. The saved rate stays with this entry. Enter business mileage only.</p>
</div>
<label>Origin Location <input type="text" name="origin_location" maxlength="160" placeholder="Rockwall, TX" value="<?= reimbursementExpenseH($expense['origin_location']) ?>" required<?= !$canEdit ? ' disabled' : '' ?>></label>
<label>Destination Location <input type="text" name="destination_location" maxlength="160" placeholder="Sugar Land, TX" value="<?= reimbursementExpenseH($expense['destination_location']) ?>" required<?= !$canEdit ? ' disabled' : '' ?>></label>
<label>Starting Odometer Reading <input type="number" name="odometer_start" min="0" max="9999999.99" step="0.01" inputmode="decimal" value="<?= reimbursementExpenseH($expense['odometer_start']) ?>" required<?= !$canEdit ? ' disabled' : '' ?> data-mileage-start></label>
<label>Ending Odometer Reading <input type="number" name="odometer_end" min="0.01" max="9999999.99" step="0.01" inputmode="decimal" value="<?= reimbursementExpenseH($expense['odometer_end']) ?>"<?= !$canEdit ? ' disabled' : '' ?> data-mileage-end aria-describedby="ending-odometer-help"><span class="field-help" id="ending-odometer-help">Optional when saving. Required before this entry is Available for a reimbursement request.</span></label>
