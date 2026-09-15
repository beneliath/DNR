<?php

declare(strict_types=1);

/** Hide active work while its parent is archived, without changing task state. */
function followUpTaskUnarchivedParentSql(string $taskAlias = 't'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $taskAlias)) {
        throw new InvalidArgumentException('Choose a valid task table alias.');
    }
    return "({$taskAlias}.engagement_id IS NULL OR EXISTS (
                SELECT 1 FROM engagements task_parent_engagement
                WHERE task_parent_engagement.id = {$taskAlias}.engagement_id
                  AND task_parent_engagement.is_deleted = 0
            )) AND ({$taskAlias}.inquiry_id IS NULL OR EXISTS (
                SELECT 1 FROM booking_inquiries task_parent_inquiry
                WHERE task_parent_inquiry.id = {$taskAlias}.inquiry_id
                  AND task_parent_inquiry.archived_at IS NULL
            ))";
}
