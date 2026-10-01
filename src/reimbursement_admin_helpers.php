<?php

declare(strict_types=1);
require_once __DIR__ . '/reimbursement_helpers.php';

function requireReimbursementAdminVersion(array $row, mixed $version): void
{
    if (!is_scalar($version) || !ctype_digit((string) $version) || (string) $row['version'] !== (string) $version) {
        throw new InvalidArgumentException('This record changed in another session. Reload and compare the latest values before saving.');
    }
}

function changeReimbursementCostCenter(mysqli $conn, string $action, ?int $id, array $input, int $actor): int
{
    if (!in_array($action, ['save','archive','restore','delete'], true)) throw new InvalidArgumentException('Unknown action.');
    $number = is_string($input['coa_number'] ?? null) ? trim($input['coa_number']) : '';
    $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
    if ($action === 'save' && (!preg_match('/\A[A-Za-z0-9.-]{1,20}\z/D',$number) || $description === '' || mb_strlen($description)>120)) {
        throw new InvalidArgumentException('Enter a valid COA number and a description up to 120 characters.');
    }
    if ((int) ($conn->query("SELECT GET_LOCK('dnr_reimbursement_coa_number',10)")->fetch_row()[0] ?? 0) !== 1) {
        throw new InvalidArgumentException('The chart of accounts is being updated. Please try again.');
    }
    try {
        $conn->begin_transaction();
        try {
            $row = $id ? $conn->execute_query('SELECT * FROM reimbursement_cost_centers WHERE id=? FOR UPDATE',[$id])->fetch_assoc() : null;
            if ($id) {
                if (!$row) throw new InvalidArgumentException('Account not found.');
                requireReimbursementAdminVersion($row,$input['version'] ?? null);
            } elseif ($action !== 'save') throw new InvalidArgumentException('Choose an account.');
            if ($action === 'save') {
                if (!$row || strcasecmp($row['coa_number'],$number)!==0) {
                    if ($conn->execute_query('SELECT id FROM reimbursement_cost_centers WHERE coa_number=? LIMIT 1',[$number])->fetch_row()) {
                        throw new InvalidArgumentException('That COA number already exists. Edit the existing account or choose another number.');
                    }
                }
                if ($id) $conn->execute_query('UPDATE reimbursement_cost_centers SET coa_number=?,description=?,version=version+1 WHERE id=?',[$number,$description,$id]);
                else {
                    $conn->execute_query('INSERT INTO reimbursement_cost_centers (coa_number,description,created_by) VALUES (?,?,?)',[$number,$description,$actor]);
                    $id=(int) $conn->insert_id;
                }
            } elseif ($action === 'delete') {
                if ($conn->execute_query('SELECT id FROM reimbursement_expenses WHERE cost_center_id=? LIMIT 1',[$id])->fetch_row()) {
                    throw new InvalidArgumentException('This account has expenses. Archive it to preserve their history.');
                }
                $conn->execute_query('DELETE FROM reimbursement_cost_centers WHERE id=?',[$id]);
            } else $conn->execute_query('UPDATE reimbursement_cost_centers SET is_archived=?,version=version+1 WHERE id=?',[$action==='archive'?1:0,$id]);
            reimbursementEvent($conn,'cost_center',(int)$id,$action,'',$actor);
            $conn->commit();
            return (int)$id;
        } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
    } finally { $conn->query("SELECT RELEASE_LOCK('dnr_reimbursement_coa_number')"); }
}
