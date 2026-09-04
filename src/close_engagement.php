<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';

use Dnr\Domain\FinancialReportInput;
use Dnr\Http\RequestInput;

startSecureSession();
requireLogin();

$user_role = (string) ($_SESSION['role'] ?? '');
if (!in_array($user_role, ['admin', 'editor'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$engagement_id = RequestInput::positiveInt($_GET, 'id');
if ($engagement_id === null) {
    header('Location: engagements.php');
    exit();
}

$engagement_stmt = $conn->prepare(
    'SELECT engagement.id, engagement.event_title, engagement.event_start_date,
            engagement.event_end_date, engagement.confirmation_status,
            engagement.lifecycle_status,
            organization.organization_name
     FROM engagements engagement
     INNER JOIN organizations organization ON organization.id = engagement.organization_id
     WHERE engagement.id = ? AND engagement.is_deleted = 0'
);
if (!$engagement_stmt) {
    abortApplication(503, 'The engagement closeout is temporarily unavailable.', [
        'error' => $conn->error,
    ]);
}
$engagement_stmt->bind_param('i', $engagement_id);
$engagement_stmt->execute();
$engagement = $engagement_stmt->get_result()->fetch_assoc();
$engagement_stmt->close();
if (!$engagement) {
    header('Location: engagements.php');
    exit();
}

try {
    $financial_report = fetchEngagementFinancialReport($conn, $engagement_id);
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement closeout is temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}
if ($financial_report === null
    && in_array((string) $engagement['lifecycle_status'], ['postponed', 'canceled'], true)
) {
    http_response_code(409);
    exit('Postponed or canceled engagements cannot be financially closed.');
}

$form_values = $financial_report ?: [
    'giving_income_received' => '0.00',
    'lodging_received' => '0.00',
    'travel_received' => '0.00',
    'notes' => '',
];
$form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        $submitted = FinancialReportInput::normalize($_POST);
        $form_values = $submitted;
        $submitted_version = trim((string) ($_POST['report_version'] ?? ''));
        $confirmed_final = isset($_POST['confirm_final']) && $_POST['confirm_final'] === 'yes';
        $current_user_id = (int) ($_SESSION['user_id'] ?? 0);
        if ($current_user_id < 1) {
            throw new RuntimeException('The current user is unavailable.');
        }

        $conn->begin_transaction();
        try {
            // Every closeout writer locks the parent first. This also serializes
            // the first insert, when no report row exists yet to lock.
            $lock_engagement_stmt = $conn->prepare(
                'SELECT id, lifecycle_status
                 FROM engagements WHERE id = ? AND is_deleted = 0 FOR UPDATE'
            );
            if (!$lock_engagement_stmt) {
                throw new RuntimeException('Unable to prepare the engagement closeout.');
            }
            $lock_engagement_stmt->bind_param('i', $engagement_id);
            $lock_engagement_stmt->execute();
            $locked_engagement = $lock_engagement_stmt->get_result()->fetch_assoc();
            $lock_engagement_stmt->close();
            if (!$locked_engagement) {
                throw new InvalidArgumentException('That engagement is no longer active.');
            }

            $lock_report_stmt = $conn->prepare(
                'SELECT updated_at
                 FROM engagement_financial_reports
                 WHERE engagement_id = ?
                 FOR UPDATE'
            );
            if (!$lock_report_stmt) {
                throw new RuntimeException('Unable to prepare the financial report.');
            }
            $lock_report_stmt->bind_param('i', $engagement_id);
            $lock_report_stmt->execute();
            $locked_report = $lock_report_stmt->get_result()->fetch_assoc();
            $lock_report_stmt->close();
            if (!$locked_report
                && in_array(
                    (string) $locked_engagement['lifecycle_status'],
                    ['postponed', 'canceled'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Postponed or canceled engagements cannot be financially closed.'
                );
            }

            $notes = $submitted['notes'] === '' ? null : $submitted['notes'];
            $giving_received = $submitted['giving_income_received'];
            $lodging_received = $submitted['lodging_received'];
            $travel_received = $submitted['travel_received'];
            if ($locked_report) {
                if ($submitted_version === ''
                    || !hash_equals((string) $locked_report['updated_at'], $submitted_version)
                ) {
                    throw new InvalidArgumentException(
                        'This financial report changed after you opened it. Reload the page before saving so newer figures are not overwritten.'
                    );
                }
                $save_stmt = $conn->prepare(
                    'UPDATE engagement_financial_reports
                     SET giving_income_received = ?, lodging_received = ?,
                         travel_received = ?, notes = ?, updated_by = ?,
                         updated_at = UTC_TIMESTAMP(6)
                     WHERE engagement_id = ?'
                );
                if (!$save_stmt) {
                    throw new RuntimeException('Unable to prepare the financial report correction.');
                }
                $save_stmt->bind_param(
                    'ssssii',
                    $giving_received,
                    $lodging_received,
                    $travel_received,
                    $notes,
                    $current_user_id,
                    $engagement_id
                );
                $success_message = 'Final financial report corrected.';
            } else {
                $task_readiness = fetchEngagementCloseoutTaskReadiness(
                    $conn,
                    $engagement_id,
                    true
                );
                $task_hold_message = engagementCloseoutTaskHoldMessage($task_readiness);
                if ($task_hold_message !== '') {
                    throw new InvalidArgumentException($task_hold_message);
                }
                if ($submitted_version !== '') {
                    throw new InvalidArgumentException(
                        'This engagement closeout changed after you opened it. Reload the page before saving.'
                    );
                }
                if (!$confirmed_final) {
                    throw new InvalidArgumentException(
                        'Confirm that these are the final received amounts before closing the event.'
                    );
                }
                $save_stmt = $conn->prepare(
                    'INSERT INTO engagement_financial_reports
                        (engagement_id, giving_income_received, lodging_received,
                         travel_received, notes, closed_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$save_stmt) {
                    throw new RuntimeException('Unable to prepare the financial report.');
                }
                $save_stmt->bind_param(
                    'issssii',
                    $engagement_id,
                    $giving_received,
                    $lodging_received,
                    $travel_received,
                    $notes,
                    $current_user_id,
                    $current_user_id
                );
                $success_message = 'Event closed with a final financial report.';
            }

            if (!$save_stmt->execute() || $save_stmt->affected_rows !== 1) {
                $save_stmt->close();
                throw new RuntimeException('Unable to save the financial report.');
            }
            $save_stmt->close();
            if (!$locked_report
                && (string) $locked_engagement['lifecycle_status'] !== 'completed'
            ) {
                $complete_stmt = $conn->prepare(
                    "UPDATE engagements
                     SET lifecycle_status = 'completed',
                         cancellation_reason = NULL,
                         rescheduled_to_engagement_id = NULL,
                         lifecycle_changed_by = ?,
                         lifecycle_changed_at = UTC_TIMESTAMP(6),
                         updated_at = CURRENT_TIMESTAMP(6)
                     WHERE id = ?"
                );
                if (!$complete_stmt) {
                    throw new RuntimeException('Unable to complete the engagement lifecycle.');
                }
                $complete_stmt->bind_param('ii', $current_user_id, $engagement_id);
                if (!$complete_stmt->execute() || $complete_stmt->affected_rows !== 1) {
                    $complete_stmt->close();
                    throw new RuntimeException('Unable to complete the engagement lifecycle.');
                }
                $complete_stmt->close();
                $success_message = 'Event completed with a final financial report.';
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        $_SESSION['financial_report_message'] = $success_message;
        header('Location: view_engagement.php?id=' . $engagement_id . '#financial-closeout');
        exit();
    } catch (Throwable $exception) {
        if ($exception instanceof InvalidArgumentException) {
            $form_error = $exception->getMessage();
        } else {
            applicationLog('error', 'Unable to save an engagement financial report', [
                'engagement_id' => $engagement_id,
                'error' => $exception->getMessage(),
            ]);
            $form_error = 'Unable to save the financial report. Please try again.';
        }
    }
}

$is_correction = $financial_report !== null;
$task_readiness = [
    'last_presentation_date' => null,
    'blocking_tasks' => [],
];
if (!$is_correction) {
    try {
        $task_readiness = fetchEngagementCloseoutTaskReadiness($conn, $engagement_id);
    } catch (Throwable $exception) {
        abortApplication(503, 'The engagement closeout tasks are temporarily unavailable.', [
            'engagement_id' => $engagement_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
$task_hold_message = engagementCloseoutTaskHoldMessage($task_readiness);
$closeout_is_held = $task_hold_message !== '';
if ($closeout_is_held && $form_error === $task_hold_message) {
    $form_error = '';
}
$closed_timestamp = $is_correction
    ? chronLogTimestampDetails($financial_report['closed_at'])
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle(($is_correction ? 'Correct' : 'Close') . ' Event'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/close_engagement.min.css',
    ],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container closeout-container" role="main">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="engagements.php">Engagements</a><span aria-hidden="true">/</span>
        <a href="view_engagement.php?id=<?php echo $engagement_id; ?>">Engagement Details</a><span aria-hidden="true">/</span>
        <span><?php echo $is_correction ? 'Correct Financial Report' : 'Financial Closeout'; ?></span>
    </nav>

    <div class="page-heading">
        <div>
            <h1><?php echo $is_correction ? 'Correct final financial report' : 'Close out event'; ?></h1>
            <p class="page-intro">
                <?php echo htmlspecialchars((string) $engagement['event_title'], ENT_QUOTES, 'UTF-8'); ?>
                · <?php echo htmlspecialchars((string) $engagement['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>
    </div>

    <?php if ($is_correction && $closed_timestamp !== null): ?>
        <p class="closeout-status">
            Finalized <time datetime="<?php echo htmlspecialchars($closed_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($closed_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time>
            <?php if (!empty($financial_report['closed_by_username'])): ?>
                by <?php echo htmlspecialchars((string) $financial_report['closed_by_username'], ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>.
            Saving here records a correction without changing the original close date.
        </p>
    <?php elseif ($closeout_is_held): ?>
        <section class="closeout-task-hold" aria-labelledby="closeout-task-hold-title">
            <h2 id="closeout-task-hold-title">Complete Earlier Event Tasks First</h2>
            <p class="error" role="alert"><?php echo htmlspecialchars($task_hold_message, ENT_QUOTES, 'UTF-8'); ?></p>
            <ul class="closeout-blocking-task-list">
                <?php foreach ($task_readiness['blocking_tasks'] as $blocking_task): ?>
                    <?php
                    $task_edit_url = 'edit_task.php?' . http_build_query([
                        'id' => (int) $blocking_task['id'],
                        'return_to' => 'view_engagement.php?id=' . $engagement_id . '#financial-closeout',
                    ]);
                    $task_status_label = ucfirst(str_replace('_', ' ', (string) $blocking_task['status']));
                    ?>
                    <li>
                        <a href="<?php echo htmlspecialchars($task_edit_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $blocking_task['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <span>Due <?php echo htmlspecialchars((string) $blocking_task['due_date'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($task_status_label, ENT_QUOTES, 'UTF-8'); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="form-actions">
                <a href="view_engagement.php?id=<?php echo $engagement_id; ?>#follow-up-work" class="action-button back-button">Back to Event Tasks</a>
            </div>
        </section>
    <?php else: ?>
        <p class="closeout-status">
            Enter actual amounts received. Planning estimates on the engagement are not copied into this final report.
        </p>
    <?php endif; ?>

    <?php if ($form_error !== ''): ?>
        <p class="error" role="alert"><?php echo htmlspecialchars($form_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if (!$closeout_is_held): ?>
    <form method="post" action="close_engagement.php?id=<?php echo $engagement_id; ?>" class="closeout-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="report_version" value="<?php echo htmlspecialchars((string) ($financial_report['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <fieldset>
            <legend>Actual receipts</legend>
            <p class="field-help">Enter 0 when no amount was received. Do not enter anticipated or outstanding amounts.</p>
            <div class="financial-fields">
                <?php foreach ([
                    'giving_income_received' => 'Giving / income received',
                    'lodging_received' => 'Lodging received',
                    'travel_received' => 'Travel received',
                ] as $field_name => $field_label): ?>
                    <div class="form-group">
                        <label for="<?php echo $field_name; ?>"><?php echo $field_label; ?> <span class="required" aria-hidden="true">*</span></label>
                        <div class="money-field">
                            <span aria-hidden="true">$</span>
                            <input type="number" id="<?php echo $field_name; ?>" name="<?php echo $field_name; ?>"
                                   min="0" max="9999999999.99" step="0.01" inputmode="decimal" required
                                   value="<?php echo htmlspecialchars((string) ($form_values[$field_name] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="form-group">
            <label for="notes">Closeout notes</label>
            <textarea id="notes" name="notes" rows="6" placeholder="Optional context, payment references, or correction reason"><?php echo htmlspecialchars((string) ($form_values['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <?php if (!$is_correction): ?>
            <label class="final-confirmation">
                <input type="checkbox" name="confirm_final" value="yes" required>
                <span>I confirm these are the final received amounts and this event is ready to be financially closed.</span>
            </label>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="action-button save-button"><?php echo $is_correction ? 'Save correction' : 'Finalize and close event'; ?></button>
            <a href="view_engagement.php?id=<?php echo $engagement_id; ?>#financial-closeout" class="action-button back-button">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
