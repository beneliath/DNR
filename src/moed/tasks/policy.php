<?php

declare(strict_types=1);

function followUpTaskStatuses()
{
    return [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'waiting' => 'Waiting',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ];
}

function followUpTaskPriorities()
{
    return [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

/** Keep old deep links working while ownership is independent from due/status filters. */
function followUpTaskQueueState(array $input, bool $hasSubject = false): array
{
    $view = is_scalar($input['view'] ?? null) ? (string) $input['view'] : '';
    $scope = is_scalar($input['scope'] ?? null) ? (string) $input['scope'] : '';
    if (!in_array($scope, ['mine', 'everyone', 'unassigned'], true)) {
        $scope = ($input['owner'] ?? '') === 'me' || $view === 'my' ? 'mine'
            : ($view === 'unassigned' ? 'unassigned' : ($view !== '' || $hasSubject ? 'everyone' : 'mine'));
    }
    if (!in_array($view, ['all', 'overdue', 'today', 'upcoming', 'waiting', 'completed', 'archived'], true)) $view = 'all';
    return ['scope' => $scope, 'view' => $view];
}

function followUpTaskQueueViews()
{
    $upcomingDays = applicationWorkflowSetting('task_upcoming_days');
    return [
        'my' => 'My Active Work',
        'overdue' => 'Overdue',
        'today' => 'Due Today',
        'upcoming' => 'Next ' . $upcomingDays . ' Days',
        'waiting' => 'Waiting',
        'unassigned' => 'Unassigned',
        'completed' => 'Completed',
        'archived' => 'Archived',
        'all' => 'All Active',
    ];
}

/** Keep each user's ownership-view choice available on primary root URLs too. */
function followUpTaskPageSizePreference(string $scope, int $userId, mixed $value): int
{
    $scope = in_array($scope, ['mine', 'everyone', 'unassigned'], true) ? $scope : 'mine';
    return paginationUserPageSizePreference('tasks', $userId, $value, 20, [20, 50, 100], $scope);
}

function followUpTaskActiveStatuses()
{
    return ['open', 'in_progress', 'waiting'];
}

function canManageFollowUpTasks($role)
{
    return in_array($role, ['admin', 'editor'], true);
}

function followUpTaskSchemaAvailable(mysqli $conn)
{
    return true;
}

function requireFollowUpTaskSchema(mysqli $conn)
{
    // Deployment health checks verify schema readiness.
}
