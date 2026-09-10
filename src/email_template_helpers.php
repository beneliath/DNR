<?php

declare(strict_types=1);

require_once __DIR__ . '/engagement_contact_helpers.php';

/** @return array<string, string> */
function emailMessageTemplatePlaceholders(): array
{
    return [
        'event_name' => 'Event name',
        'organization_name' => 'Organization name',
        'event_dates' => 'Event date range',
        'event_start_date' => 'Event start date',
        'event_end_date' => 'Event end date',
        'event_location' => 'Event location',
        'speaker_names' => 'Speaker names',
        'presentation_schedule' => 'Presentation schedule (message only)',
    ];
}

function validateEmailMessageTemplatePlaceholders(string $text, bool $isSubject = false): void
{
    $allowed = emailMessageTemplatePlaceholders();
    preg_match_all('/\{\{(.*?)\}\}/s', $text, $matches);
    foreach ($matches[1] as $placeholder) {
        $name = trim($placeholder);
        if (!isset($allowed[$name])) {
            throw new InvalidArgumentException('Use only the event fields listed below the message. Unknown field: {{' . $name . '}}.');
        }
        if ($isSubject && $name === 'presentation_schedule') {
            throw new InvalidArgumentException('The presentation schedule can be used in the message only.');
        }
    }
    $remaining = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;
    if (str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
        throw new InvalidArgumentException('Write event fields with matching double braces, such as {{event_name}}.');
    }
}

/** @param array<string, string> $values */
function renderEmailMessageTemplateText(string $text, array $values, bool $isSubject = false): string
{
    // A single replacement pass keeps event content from being interpreted as another field.
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $match) use ($values, $isSubject): string {
        $value = $values[$match[1]] ?? '';
        return $isSubject ? (preg_replace('/\s+/u', ' ', $value) ?? '') : $value;
    }, $text) ?? $text;
}

/**
 * @param array<string, mixed> $input
 * @return array{name: string, subject_template: string, body_template: string, suggested_roles: list<string>, sort_order: int}
 */
function normalizeEmailMessageTemplateInput(array $input): array
{
    $text = [];
    foreach (['name' => 100, 'subject_template' => 255, 'body_template' => 100000] as $key => $maxLength) {
        if (!is_string($input[$key] ?? null)) {
            throw new InvalidArgumentException('Enter a template name, subject, and message.');
        }
        $text[$key] = trim(str_replace(["\r\n", "\r"], "\n", $input[$key]));
        if ($text[$key] === '' || mb_strlen($text[$key], 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException(match ($key) {
                'name' => 'Enter a template name of 100 characters or fewer.',
                'subject_template' => 'Enter a subject of 255 characters or fewer.',
                default => 'Enter a message of 100,000 characters or fewer.',
            });
        }
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $text['name'] . $text['subject_template']) === 1) {
        throw new InvalidArgumentException('The template name and subject must each fit on one line.');
    }
    validateEmailMessageTemplatePlaceholders($text['subject_template'], true);
    validateEmailMessageTemplatePlaceholders($text['body_template']);
    $roles = $input['suggested_roles'] ?? [];
    if (!is_array($roles) || count($roles) > count(engagementContactRoles())) {
        throw new InvalidArgumentException('Select valid suggested event contacts.');
    }
    foreach ($roles as $role) {
        if (!is_string($role) || !isset(engagementContactRoles()[$role])) {
            throw new InvalidArgumentException('Select valid suggested event contacts.');
        }
    }
    $sortOrder = $input['sort_order'] ?? '0';
    if (!is_scalar($sortOrder) || !ctype_digit((string) $sortOrder) || (int) $sortOrder > 65535) {
        throw new InvalidArgumentException('Enter a display order between 0 and 65535.');
    }
    return [
        'name' => $text['name'],
        'subject_template' => $text['subject_template'],
        'body_template' => $text['body_template'],
        'suggested_roles' => array_values(array_unique($roles)),
        'sort_order' => (int) $sortOrder,
    ];
}

/** @return list<array<string, mixed>> */
function fetchEmailMessageTemplates(mysqli $conn): array
{
    $result = $conn->query('SELECT * FROM email_message_templates WHERE is_archived = 0 ORDER BY sort_order, name, id');
    if (!$result) {
        throw new RuntimeException('Unable to load email templates.');
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/** @return array<string, mixed>|null */
function fetchEmailMessageTemplate(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM email_message_templates WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the email template.');
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $template ?: null;
}

/** @param array<string, mixed> $input */
function saveEmailMessageTemplate(mysqli $conn, array $input, int $userId, ?int $id = null, ?int $version = null): int
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required.');
    }
    $data = normalizeEmailMessageTemplateInput($input);
    $rolesJson = json_encode($data['suggested_roles'], JSON_THROW_ON_ERROR);
    if ($id === null) {
        $key = 'template_' . bin2hex(random_bytes(16));
        $stmt = $conn->prepare(
            'INSERT INTO email_message_templates
             (template_key, name, subject_template, body_template, suggested_roles_json, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) throw new RuntimeException('Unable to prepare the email template.');
        $stmt->bind_param('sssssii', $key, $data['name'], $data['subject_template'], $data['body_template'], $rolesJson, $data['sort_order'], $userId);
    } else {
        if ($id < 1 || $version === null || $version < 1) {
            throw new InvalidArgumentException('Reload the email template before saving.');
        }
        $stmt = $conn->prepare(
            'UPDATE email_message_templates
             SET name = ?, subject_template = ?, body_template = ?, suggested_roles_json = ?, sort_order = ?, version = version + 1
             WHERE id = ? AND version = ? AND is_archived = 0'
        );
        if (!$stmt) throw new RuntimeException('Unable to prepare the email template update.');
        $stmt->bind_param('ssssiii', $data['name'], $data['subject_template'], $data['body_template'], $rolesJson, $data['sort_order'], $id, $version);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $savedId = $id ?? (int) $conn->insert_id;
    $stmt->close();
    if ($affected !== 1) {
        throw new InvalidArgumentException('This template changed or was archived in another session. Reload it and review the latest version before saving.');
    }
    return $savedId;
}

function changeEmailMessageTemplateStatus(mysqli $conn, int $id, int $version, string $action, int $userId): void
{
    if ($id < 1 || $version < 1 || $userId < 1) {
        throw new InvalidArgumentException('Select a valid email template.');
    }
    if ($action === 'archive') {
        $stmt = $conn->prepare('UPDATE email_message_templates SET is_archived = 1, archived_at = UTC_TIMESTAMP(6), archived_by = ?, version = version + 1 WHERE id = ? AND version = ? AND is_archived = 0');
        if (!$stmt) throw new RuntimeException('Unable to prepare the template archive.');
        $stmt->bind_param('iii', $userId, $id, $version);
    } elseif ($action === 'restore') {
        $stmt = $conn->prepare('UPDATE email_message_templates SET is_archived = 0, archived_at = NULL, archived_by = NULL, version = version + 1 WHERE id = ? AND version = ? AND is_archived = 1');
        if (!$stmt) throw new RuntimeException('Unable to prepare the template restore.');
        $stmt->bind_param('ii', $id, $version);
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM email_message_templates WHERE id = ? AND version = ? AND is_archived = 1');
        if (!$stmt) throw new RuntimeException('Unable to prepare the template deletion.');
        $stmt->bind_param('ii', $id, $version);
    } else {
        throw new InvalidArgumentException('Select a valid email template action.');
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        throw new InvalidArgumentException('This template changed in another session. Reload the list before trying again. Templates must be archived before deletion.');
    }
}

/** Call inside the queue transaction to keep archive/delete from racing a new message. */
function emailMessageTemplateLabelForSend(mysqli $conn, string $key): string
{
    if ($key === 'custom') return 'Custom message';
    $stmt = $conn->prepare('SELECT name FROM email_message_templates WHERE template_key = ? AND is_archived = 0 FOR SHARE');
    if (!$stmt) throw new RuntimeException('Unable to check the email template.');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$template) {
        throw new InvalidArgumentException('This email template was archived or deleted. Select another template or Custom message before sending.');
    }
    return (string) $template['name'];
}
