<?php

declare(strict_types=1);

namespace Dnr\Service;

use Dnr\Domain\EngagementInput;
use Exception;
use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

final class EngagementWrite
{
    /**
     * Insert the engagement inside the caller's transaction so related records
     * can commit with it. The caller supplies its own workflow defaults.
     *
     * @param array<string, float|int|string|null> $data
     */
    public static function insert(mysqli $conn, array $data, int $actorId): int
    {
        $inserted = $conn->execute_query(
            'INSERT INTO engagements (
                organization_id, event_title, event_description, event_start_date, event_end_date,
                event_type, event_type_other, book_table, brochures, caller_name, caller_user_id,
                confirmation_status, lifecycle_status, cancellation_reason,
                rescheduled_to_engagement_id, lifecycle_changed_by,
                event_address_line_1, event_address_line_2, event_city, event_state,
                event_zipcode, event_country, travel_covered, travel_amount,
                compensation_type, other_compensation, housing_type, other_housing,
                housing_amount, presentation_not_required
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['organization_id'], $data['event_title'], $data['event_description'],
                $data['event_start_date'], $data['event_end_date'], $data['event_type'], $data['event_type_other'],
                $data['book_table'], $data['brochures'], $data['caller_name'], $data['caller_user_id'],
                $data['confirmation_status'], $data['lifecycle_status'], $data['cancellation_reason'] ?? null,
                $data['rescheduled_to_engagement_id'] ?? null, $actorId,
                $data['event_address_line_1'], $data['event_address_line_2'], $data['event_city'],
                $data['event_state'], $data['event_zipcode'], $data['event_country'],
                $data['travel_covered'], $data['travel_amount'] ?? null, $data['compensation_type'],
                $data['other_compensation'] ?? null, $data['housing_type'], $data['other_housing'] ?? null,
                $data['housing_amount'] ?? null, $data['presentation_not_required'] ?? 0,
            ]
        );
        if (!$inserted) {
            throw new RuntimeException('Unable to insert the engagement: ' . $conn->error);
        }
        return (int) $conn->insert_id;
    }

    /** @param array<string, mixed> $engagement */
    public static function queueLocation(mysqli $conn, array $engagement): void
    {
        $address = engagementMapAddress($engagement);
        if ($address !== '' && !queueEngagementMapAddress($conn, $address, true)) {
            throw new RuntimeException('Unable to queue the engagement location.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @param array{
     *     assignments: list<array{contact_id: int, contact_role: string}>,
     *     added_ids: list<int>,
     *     new_contacts: list<array<string, mixed>>
     * } $contacts
     * @param array<string, mixed>|null $locked_engagement
     * @param-out array<string, mixed>|null $locked_engagement Snapshot for redisplaying a rejected form.
     * @return array{archived_tasks: int, consumed_upload_tokens: list<string>}
     */
    public static function edit(
        mysqli $conn,
        int $engagement_id,
        array $input,
        array $files,
        array $contacts,
        ?int $default_speaker_id,
        int $current_user_id,
        string $current_username,
        ?array &$locked_engagement
    ): array {
        $locked_engagement = null;
        $conn->begin_transaction();
        try {
            $engagement_lock_stmt = $conn->prepare(
                'SELECT * FROM engagements WHERE id = ? AND is_deleted = 0 FOR UPDATE'
            );
            if (!$engagement_lock_stmt) {
                throw new RuntimeException('Unable to lock the engagement for editing.');
            }
            $engagement_lock_stmt->bind_param('i', $engagement_id);
            $engagement_lock_stmt->execute();
            $current_engagement = $engagement_lock_stmt->get_result()->fetch_assoc();
            $engagement_lock_stmt->close();
            if (!$current_engagement) {
                throw new InvalidArgumentException('That engagement is no longer active.');
            }
            $submitted_version = trim((string) ($input['engagement_version'] ?? ''));
            if ($submitted_version === ''
                || !hash_equals((string) $current_engagement['updated_at'], $submitted_version)
            ) {
                throw new InvalidArgumentException(
                    'This engagement changed after you opened it. Reload the page before saving so newer changes are not overwritten.'
                );
            }
            $locked_engagement = $current_engagement;
            $engagement_input = EngagementInput::normalize($input);
            requireActiveOrganization($conn, $engagement_input['organization_id'], true);
            validateEngagementLifecycleStatus($conn, $engagement_id, $engagement_input['lifecycle_status']);
            validateEngagementRescheduleLink(
                $conn,
                $engagement_input['organization_id'],
                $engagement_input['lifecycle_status'],
                $engagement_input['rescheduled_to_engagement_id'],
                $engagement_id
            );
            $caller_user_id = $engagement_input['caller_user_id'];
            $caller = $caller_user_id !== null
                && $caller_user_id === (int) ($current_engagement['caller_user_id'] ?? 0)
                ? [
                    'id' => $caller_user_id,
                    'username' => (string) ($current_engagement['caller_name'] ?? ''),
                ]
                : EngagementInput::resolveCaller($conn, $caller_user_id);
            $caller_user_id = $caller['id'];
            $caller_name = $caller['username'];
            $engagement_input['caller_user_id'] = $caller_user_id;
            $engagement_input['caller_name'] = $caller_name;
            $new_chron_entry = trim((string) ($input['new_chron_entry'] ?? ''));

            $submitted_presentations = $engagement_input['presentation_not_required'] ? null : ($input['presentations'] ?? null);
            $presentation_files = !$engagement_input['presentation_not_required'] && is_array($files['presentations'] ?? null)
                ? $files['presentations']
                : [];
            $presentation_asset_uploads = presentationAssetUploadMap($presentation_files);
            $staged_assets = stagedPresentationAssets(is_array($submitted_presentations) ? $submitted_presentations : [], $engagement_id);
            $presentations = normalizeEngagementPresentations(
                $submitted_presentations,
                $engagement_input['event_start_date'],
                $engagement_input['event_end_date'],
                $default_speaker_id,
                true,
                array_fill_keys(array_merge(array_keys($presentation_asset_uploads), array_keys($staged_assets)), true)
            );
            $presentations = attachPresentationAssetChanges(
                $presentations,
                $submitted_presentations,
                $presentation_files,
                $staged_assets
            );
            requirePresentationForConfirmedEngagement(
                $engagement_input['confirmation_status'],
                $presentations,
                $engagement_input['lifecycle_status'],
                (bool) $engagement_input['presentation_not_required']
            );

            $submitted_chron_entries = normalizeSubmittedChronLogEntries(
                $input['chron_entries'] ?? null
            );
            $submitted_chron_versions = normalizeSubmittedChronLogVersions(
                $input['chron_entry_versions'] ?? null
            );

            // Validate required fields
            if (
                ($engagement_input['compensation_type'] === 'Other' && empty($engagement_input['other_compensation'])) ||
                ($engagement_input['housing_type'] === 'Other' && empty($engagement_input['other_housing'])) ||
                (isset($input['save_and_add_chron']) && $new_chron_entry === '')
            ) {
                throw new InvalidArgumentException("Please provide valid required fields, dates, and non-negative amounts.");
            }

            $lifecycle_changed = $engagement_input['lifecycle_status']
                !== (string) ($current_engagement['lifecycle_status'] ?? 'active');
            $update_plan = EngagementUpdatePlan::build($current_engagement, $engagement_input);
            $update_fields = $update_plan['assignments'];
            $update_values = $update_plan['values'];
            $types = $update_plan['types'];
            if ($lifecycle_changed) {
                $update_fields[] = 'lifecycle_changed_by = ?, lifecycle_changed_at = UTC_TIMESTAMP(6)';
                $update_values[] = $current_user_id;
                $types .= 'i';
            }

            $update_values[] = $engagement_id;
            $types .= "i";

            if (!empty($update_fields)) {
                $update_query = "UPDATE engagements SET " . implode(", ", $update_fields) . " WHERE id = ?";
                $stmt = $conn->prepare($update_query);

                // Bind parameters dynamically
                $bind_params = array_merge([$types], $update_values);
                $stmt->bind_param(...$bind_params);

                if (!$stmt->execute()) {
                    applicationLog('error', 'Engagement update query failed', ['error' => $stmt->error]);
                    throw new Exception("Failed to update engagement.");
                }
            }

            $engagement_dates_changed = $update_plan['date_range_changed'];
            if ($engagement_dates_changed) {
                if ($engagement_input['presentation_not_required']) {
                    $outside_dates = $conn->execute_query(
                        'SELECT 1 FROM presentations WHERE engagement_id = ? AND is_archived = 0
                             AND presentation_date NOT BETWEEN ? AND ? LIMIT 1',
                        [$engagement_id, $engagement_input['event_start_date'], $engagement_input['event_end_date']]
                    )->fetch_row();
                    if ($outside_dates) {
                        throw new InvalidArgumentException('A saved presentation falls outside the new event dates. Clear “No Presentation Required” to adjust its date.');
                    }
                }
                rescheduleGeneratedEngagementTasks(
                    $conn,
                    $engagement_id,
                    $engagement_input['event_start_date'],
                    $engagement_input['event_end_date']
                );
            }

            $archived_task_count = 0;
            if ($lifecycle_changed && $engagement_input['lifecycle_status'] === 'canceled') {
                $archived_task_count = cancelEngagementFollowUpTasks($conn, $engagement_id, $current_user_id);
            }

            if (!$engagement_input['presentation_not_required'] && syncEngagementPresentations($conn, $engagement_id, $presentations, $current_user_id)) {
                $touch_engagement_stmt = $conn->prepare(
                    'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?'
                );
                if (!$touch_engagement_stmt) {
                    throw new RuntimeException('Unable to update the engagement calendar timestamp.');
                }
                $touch_engagement_stmt->bind_param('i', $engagement_id);
                if (!$touch_engagement_stmt->execute()) {
                    $touch_engagement_stmt->close();
                    throw new RuntimeException('Unable to update the engagement calendar timestamp.');
                }
                $touch_engagement_stmt->close();
            }

            $saved_engagement_contacts = prepareEngagementContactAssignments(
                $conn,
                $engagement_input['organization_id'],
                $contacts['assignments'],
                $contacts['added_ids'],
                $contacts['new_contacts'],
                $engagement_id
            );
            syncEngagementContacts(
                $conn,
                $engagement_id,
                $saved_engagement_contacts,
                $current_user_id
            );
            if ($submitted_chron_entries) {
                $current_chron_stmt = $conn->prepare(
                    'SELECT id, entry_text, updated_at
                     FROM engagement_chron_entries
                     WHERE engagement_id = ? AND is_archived = 0
                     FOR UPDATE'
                );
                if (!$current_chron_stmt) {
                    throw new RuntimeException('Unable to prepare the Chron entry update.');
                }
                $current_chron_stmt->bind_param('i', $engagement_id);
                if (!$current_chron_stmt->execute()) {
                    $current_chron_stmt->close();
                    throw new RuntimeException('Unable to load the Chron entries for updating.');
                }
                $current_chron_entries = [];
                $current_chron_result = $current_chron_stmt->get_result();
                while ($current_chron_entry = $current_chron_result->fetch_assoc()) {
                    $current_chron_entries[(int) $current_chron_entry['id']] = [
                        'entry_text' => (string) $current_chron_entry['entry_text'],
                        'updated_at' => (string) $current_chron_entry['updated_at'],
                    ];
                }
                $current_chron_stmt->close();

                $chron_update_stmt = $conn->prepare(
                    'UPDATE engagement_chron_entries
                     SET entry_text = ?, updated_by = ?, updated_at = UTC_TIMESTAMP(6)
                     WHERE id = ? AND engagement_id = ? AND is_archived = 0 AND updated_at = ?'
                );
                if (!$chron_update_stmt) {
                    throw new RuntimeException('Unable to prepare the Chron entry changes.');
                }
                $updated_chron_text = '';
                $updated_chron_id = 0;
                $expected_chron_version = '';
                $chron_update_stmt->bind_param(
                    'siiis',
                    $updated_chron_text,
                    $current_user_id,
                    $updated_chron_id,
                    $engagement_id,
                    $expected_chron_version
                );
                foreach ($submitted_chron_entries as $submitted_entry_id => $submitted_entry_text) {
                    if (!array_key_exists($submitted_entry_id, $current_chron_entries)
                        || $submitted_entry_text === $current_chron_entries[$submitted_entry_id]['entry_text']) {
                        continue;
                    }
                    $expected_chron_version = (string) (
                        $submitted_chron_versions[$submitted_entry_id] ?? ''
                    );
                    if ($expected_chron_version === ''
                        || !hash_equals(
                            $current_chron_entries[$submitted_entry_id]['updated_at'],
                            $expected_chron_version
                        )
                    ) {
                        $chron_update_stmt->close();
                        throw new InvalidArgumentException(
                            'A Chron entry changed after you opened this page. Reload before saving so newer history is not overwritten.'
                        );
                    }
                    $updated_chron_id = $submitted_entry_id;
                    $updated_chron_text = $submitted_entry_text;
                    if (!$chron_update_stmt->execute() || $chron_update_stmt->affected_rows !== 1) {
                        $chron_update_stmt->close();
                        throw new RuntimeException('Unable to save the Chron entry changes.');
                    }
                }
                $chron_update_stmt->close();
            }

            if ($new_chron_entry !== '') {
                $chron_stmt = $conn->prepare(
                    'INSERT INTO engagement_chron_entries
                        (engagement_id, entry_text, created_by,
                         created_by_username_snapshot, updated_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                if (!$chron_stmt) {
                    throw new RuntimeException('Unable to prepare the new Chron entry.');
                }
                $chron_stmt->bind_param(
                    'isisi',
                    $engagement_id,
                    $new_chron_entry,
                    $current_user_id,
                    $current_username,
                    $current_user_id
                );
                if (!$chron_stmt->execute()) {
                    $chron_error = $chron_stmt->error;
                    $chron_stmt->close();
                    throw new RuntimeException('Unable to save the new Chron entry: ' . $chron_error);
                }
                $chron_stmt->close();
            }

            self::queueLocation($conn, $engagement_input);

            $consumed_upload_tokens = [];
            foreach ($staged_assets as $row => $assets) {
                foreach (array_keys($assets) as $asset_key) {
                    $token_field = $asset_key === 'ppt_slidedeck' ? 'ppt_upload_token' : 'pdf_upload_token';
                    $consumed_upload_tokens[] = $input['presentations'][$row][$token_field];
                }
            }
            $conn->commit();

            return [
                'archived_tasks' => $archived_task_count,
                'consumed_upload_tokens' => $consumed_upload_tokens,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
