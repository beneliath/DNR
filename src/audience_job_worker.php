<?php
declare(strict_types=1);

require_once __DIR__ . '/audience_store.php';

/**
 * Each claim and bounded batch share one transaction. SKIP LOCKED permits
 * independent workers; a lost connection rolls back the batch and releases its
 * claim. Previously committed rows survive restarts and are never replayed.
 */
function processAudienceJobs(mysqli $db, int $batchSize = 40, int $maximumJobs = 10, ?int $audienceId = null): int
{
    $candidates = $db->execute_query("SELECT id,audience_id,actor_user_id,actor_label FROM marketing_jobs
        WHERE state IN ('queued','processing')" . ($audienceId === null ? '' : ' AND audience_id=?') .
        ' ORDER BY updated_at,id LIMIT 100', $audienceId === null ? [] : [$audienceId])->fetch_all(MYSQLI_ASSOC);
    $processed = 0;
    foreach ($candidates as $candidate) {
        if ($processed >= max(1, min(100, $maximumJobs))) break;
        $store = new AudienceStore($db, (int) $candidate['audience_id'], (int) $candidate['actor_user_id'], (string) $candidate['actor_label']);
        $worked = $store->transaction(function () use ($store, $candidate, $batchSize): bool {
            $job = $store->one("SELECT * FROM marketing_jobs WHERE id=? AND audience_id=?
                AND state IN ('queued','processing') FOR UPDATE SKIP LOCKED", [$candidate['id'], $store->audienceId]);
            if (!$job) return false;
            // A resume may have replaced the initiating user since discovery.
            if ((int) $job['actor_user_id'] !== $store->actorId || $job['actor_label'] !== $store->actorLabel) return false;
            $params = audienceJson($job['params_json']);
            $actor = $store->one('SELECT role,account_status FROM users WHERE id=? FOR SHARE', [$store->actorId]);
            $roles = ($job['job_type'] === 'bulk' && ($params['action'] ?? '') === 'delete') ? ['admin'] : ['admin', 'editor'];
            if (!$actor || $actor['account_status'] !== 'active' || !in_array($actor['role'], $roles, true)) {
                $store->write("UPDATE marketing_jobs SET state='failed',error_message=? WHERE id=?", [
                    'Processing stopped because the initiating user no longer has permission. An authorized user can resume the remaining rows.', $job['id'],
                ]);
                return true;
            }
            try {
                $store->processJob((int) $job['id'], $batchSize);
            } catch (mysqli_sql_exception $error) {
                // Deadlocks and connection loss must release the claim and retry
                // on a new connection, never write a failure after a rollback.
                if (in_array($error->getCode(), [1205, 1213, 2006, 2013], true)) throw $error;
                applicationLog('error', 'Audience background job database failure', ['job_id' => $job['id'], 'error' => $error->getMessage()]);
                $store->write("UPDATE marketing_jobs SET state='failed',error_message=? WHERE id=?", [
                    'Processing stopped. Resume to retry the remaining rows.', $job['id'],
                ]);
            } catch (Throwable $error) {
                applicationLog('error', 'Audience background job failed', ['job_id' => $job['id'], 'error' => $error->getMessage()]);
                $store->write("UPDATE marketing_jobs SET state='failed',error_message=? WHERE id=?", [
                    'Processing stopped. Resume to retry the remaining rows.', $job['id'],
                ]);
            }
            return true;
        }, true);
        if ($worked) ++$processed;
    }
    return $processed;
}
