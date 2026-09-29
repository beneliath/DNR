<?php
declare(strict_types=1);

const REIMBURSEMENT_MAX_RECEIPTS = 20;
require_once __DIR__ . '/reimbursement_limits.php';
const REIMBURSEMENT_MAX_RECEIPT_BYTES = 25_000_000;
const REIMBURSEMENT_MAX_EXPENSE_BYTES = 15 * 1024 * 1024;
const REIMBURSEMENT_SNAPSHOT_DAYS = 365;

function reimbursementEvent(mysqli $conn, string $type, int $id, string $action, string $detail = '', ?int $actor = null): void
{
    $actor ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $conn->execute_query('INSERT INTO reimbursement_events (entity_type,entity_id,actor_id,action,detail) VALUES (?,?,?,?,?)',
        [$type,$id,$actor,$action,mb_substr($detail,0,1000)]);
}

function reimbursementReturnUrl(mixed $url, string $fallback = 'reimbursements.php'): string
{
    if (!is_string($url) || strlen($url)>2000 || preg_match('/[\x00-\x20\\\\]/',$url)) return $fallback;
    if (!preg_match('/\A(?:reimbursements|reimbursement_requests|reimbursement_request)\.php(?:\?[^#]*)?\z/D',$url)) return $fallback;
    return $url;
}

function reimbursementSnapshot(mysqli $conn, int $id): ?array
{
    $row = $conn->execute_query('SELECT snapshot_ciphertext FROM reimbursement_submissions WHERE id=? AND created_at > UTC_TIMESTAMP() - INTERVAL 365 DAY',[$id])->fetch_assoc();
    if (empty($row['snapshot_ciphertext'])) return null;
    return json_decode(\Dnr\Security\ApplicationKey::open($row['snapshot_ciphertext']),true,32,JSON_THROW_ON_ERROR);
}

function reimbursementReceiptsReady(array $receipts): void
{
    foreach ($receipts as $receipt) {
        $state = $receipt['scan_state'] ?? 'unscanned';
        if (!in_array($state,['clean','unscanned'],true) || (documentScanningEnabled() && $state !== 'clean')) {
            throw new InvalidArgumentException('Receipt security checks are pending or rejected. Open the expense to review its receipts before downloading or submitting.');
        }
    }
    if (count($receipts)>100 || array_sum(array_column($receipts,'size'))>REIMBURSEMENT_MAX_RECEIPT_BYTES) {
        throw new InvalidArgumentException('This request exceeds 100 receipts or 25 MB of receipt files. Remove expenses from this draft and submit smaller requests.');
    }
}

/** Preserve the request and links; a correction does not retract accepted email. */
function correctReimbursementRequest(mysqli $conn, int $id, int $userId, bool $isAdmin, string $reason): void
{
    $reason=trim($reason);
    if ($reason==='' || mb_strlen($reason)>1000) throw new InvalidArgumentException('Enter a correction reason of 1–1000 characters.');
    $conn->begin_transaction();
    try {
        $request=$conn->execute_query('SELECT user_id,status FROM reimbursement_requests WHERE id=? FOR UPDATE',[$id])->fetch_assoc();
        if (!$request || ((int)$request['user_id']!==$userId && !$isAdmin) || $request['status']!=='submitted') throw new InvalidArgumentException('Only an authorized submitted request can be marked for correction.');
        $conn->execute_query('UPDATE reimbursement_requests SET correction_note=?,correction_at=UTC_TIMESTAMP() WHERE id=?',[$reason,$id]);
        // Claims lock delivery rows, so only delivery that has not begun can be cancelled.
        $conn->execute_query("UPDATE reimbursement_email_deliveries SET status='cancelled',payload_ciphertext=NULL,last_error='Cancelled by a recorded correction.' WHERE request_id=? AND status IN ('pending','retry')",[$id]);
        reimbursementEvent($conn,'request',$id,'correction_recorded',$reason,$userId);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

/** Explicit user action, never automatic recovery of an uncertain SMTP result. */
function recoverReimbursementDelivery(mysqli $conn, int $id, int $deliveryId, int $userId, bool $isAdmin, string $action, string $reason, bool $providerChecked): void
{
    if (!in_array($action,['retry_delivery','resend_delivery'],true) || trim($reason)==='' || mb_strlen($reason)>1000) throw new InvalidArgumentException('Enter a reason for this delivery action.');
    $conn->begin_transaction();
    try {
        $request=$conn->execute_query('SELECT * FROM reimbursement_requests WHERE id=? FOR UPDATE',[$id])->fetch_assoc();
        if (!$request || ((int)$request['user_id']!==$userId && !$isAdmin) || $request['status']!=='submitted' || $request['correction_at']!==null || (int)$request['is_archived']) throw new InvalidArgumentException('Restore the request and resolve any correction before resending.');
        $delivery=$conn->execute_query('SELECT * FROM reimbursement_email_deliveries WHERE id=? AND request_id=? FOR UPDATE',[$deliveryId,$id])->fetch_assoc();
        $allowed=$action==='retry_delivery'?['failed']:['sent','delivery_uncertain','cancelled'];
        if (!$delivery || !in_array($delivery['status'],$allowed,true)) throw new InvalidArgumentException('Delivery state changed. Refresh and review its current status.');
        if (!$providerChecked) throw new InvalidArgumentException('Confirm you reviewed delivery history and approve sending this copy.');
        $snapshot=reimbursementSnapshot($conn,$id);
        $message=$snapshot['deliveries'][(string)$deliveryId] ?? null;
        if (!$message) throw new InvalidArgumentException('The original approved message is unavailable or past its 365-day recovery period. Contact the bookkeeper using the original package; do not submit these expenses again.');
        $conn->execute_query("UPDATE reimbursement_email_deliveries SET status='pending',attempts=0,next_attempt_at=UTC_TIMESTAMP(),processing_started_at=NULL,delivery_started_at=NULL,claim_token=NULL,smtp_message_id=NULL,sent_at=NULL,last_error=NULL,payload_ciphertext=? WHERE id=?",[\Dnr\Security\ApplicationKey::seal(json_encode($message,JSON_THROW_ON_ERROR)),$deliveryId]);
        reimbursementEvent($conn,'request',$id,$action,'Delivery #'.$deliveryId.'; previous state: '.$delivery['status'].'; attempts: '.$delivery['attempts'].'; SMTP ID: '.($delivery['smtp_message_id']??'none').'; reason: '.trim($reason),$userId);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function resolveReimbursementCorrection(mysqli $conn, int $id, int $userId, bool $isAdmin, string $reason): void
{
    $reason=trim($reason);
    if ($reason==='' || mb_strlen($reason)>1000) throw new InvalidArgumentException('Enter how the correction was resolved (1–1000 characters).');
    $conn->begin_transaction();
    try {
        $request=$conn->execute_query('SELECT user_id,correction_at FROM reimbursement_requests WHERE id=? FOR UPDATE',[$id])->fetch_assoc();
        if (!$request || !$request['correction_at'] || ((int)$request['user_id']!==$userId && !$isAdmin)) throw new InvalidArgumentException('No authorized correction to resolve.');
        $conn->execute_query("UPDATE reimbursement_requests SET correction_at=NULL,correction_note='' WHERE id=?",[$id]);
        reimbursementEvent($conn,'request',$id,'correction_resolved',$reason,$userId);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
