<?php
$calendar_mode_labels = calendarViewerModes();
$calendar_mode_label = $calendar_mode_labels[$calendar_view_mode];
$calendar_return_url = calendarViewerPageUrl(
    $calendar_month['month'],
    $calendar_view_mode,
    $has_requested_day ? $calendar_day['date'] : null
);
$calendar_day_items = $calendar_events_by_date[$calendar_day['date']] ?? [];
$calendar_day_events = array_values(array_filter(
    $calendar_day_items,
    static fn(array $item): bool => ($item['calendar_item_type'] ?? '') !== 'birthday'
));
$calendar_day_birthdays = array_values(array_filter(
    $calendar_day_items,
    static fn(array $item): bool => ($item['calendar_item_type'] ?? '') === 'birthday'
));
$calendar_day_tasks = $calendar_tasks_by_date[$calendar_day['date']] ?? [];
$calendar_day_summary = match ($calendar_view_mode) {
    'my_tasks' => sprintf(
        '%d of my task%s due',
        count($calendar_day_tasks),
        count($calendar_day_tasks) === 1 ? '' : 's'
    ),
    'all_tasks' => sprintf(
        '%d task%s due',
        count($calendar_day_tasks),
        count($calendar_day_tasks) === 1 ? '' : 's'
    ),
    'birthdays' => sprintf(
        '%d birthday%s',
        count($calendar_day_birthdays),
        count($calendar_day_birthdays) === 1 ? '' : 's'
    ),
    'everything' => sprintf(
        '%d event%s, %d task%s, and %d birthday%s',
        count($calendar_day_events),
        count($calendar_day_events) === 1 ? '' : 's',
        count($calendar_day_tasks),
        count($calendar_day_tasks) === 1 ? '' : 's',
        count($calendar_day_birthdays),
        count($calendar_day_birthdays) === 1 ? '' : 's'
    ),
    default => sprintf(
        '%d event%s',
        count($calendar_day_events),
        count($calendar_day_events) === 1 ? '' : 's'
    ),
};
$calendar_month_summary = match ($calendar_view_mode) {
    'my_tasks' => sprintf(
        '%d of my task%s due this month',
        $calendar_month_task_count,
        $calendar_month_task_count === 1 ? '' : 's'
    ),
    'all_tasks' => sprintf(
        '%d task%s due this month',
        $calendar_month_task_count,
        $calendar_month_task_count === 1 ? '' : 's'
    ),
    'birthdays' => sprintf(
        '%d birthday%s this month',
        $calendar_month_birthday_count,
        $calendar_month_birthday_count === 1 ? '' : 's'
    ),
    'everything' => sprintf(
        '%d event%s, %d task%s, and %d birthday%s this month',
        $calendar_month_event_count,
        $calendar_month_event_count === 1 ? '' : 's',
        $calendar_month_task_count,
        $calendar_month_task_count === 1 ? '' : 's',
        $calendar_month_birthday_count,
        $calendar_month_birthday_count === 1 ? '' : 's'
    ),
    default => sprintf(
        '%d event%s this month',
        $calendar_month_event_count,
        $calendar_month_event_count === 1 ? '' : 's'
    ),
};
?>
<section class="security-card calendar-card calendar-viewer" id="event-calendar" aria-label="Schedule calendar">
    <div class="calendar-viewer-heading">
        <div class="calendar-month-heading-copy">
            <p class="calendar-viewer-kicker">Schedule Calendar</p>
            <h2 id="calendar-month-title"><?php echo htmlspecialchars($calendar_month['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="calendar-month-summary"><?php echo htmlspecialchars($calendar_month_summary, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="calendar-agenda-heading-copy">
            <p class="calendar-viewer-kicker">Daily Agenda</p>
            <h2 id="calendar-agenda-title"><time datetime="<?php echo $calendar_day['date']; ?>"><?php echo htmlspecialchars($calendar_day['label'], ENT_QUOTES, 'UTF-8'); ?></time></h2>
            <p class="calendar-month-summary"><?php echo htmlspecialchars($calendar_day_summary, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <nav class="calendar-month-navigation calendar-desktop-navigation" aria-label="Calendar month navigation">
            <a class="calendar-month-control" href="<?php echo htmlspecialchars(calendarViewerPageUrl($calendar_month['previous_month'], $calendar_view_mode), ENT_QUOTES, 'UTF-8'); ?>" aria-label="View <?php echo htmlspecialchars((new DateTimeImmutable($calendar_month['previous_month'] . '-01'))->format('F Y'), ENT_QUOTES, 'UTF-8'); ?>">
                <span aria-hidden="true">←</span> Previous
            </a>
            <a class="calendar-month-control calendar-month-today" href="<?php echo htmlspecialchars(calendarViewerPageUrl(null, $calendar_view_mode), ENT_QUOTES, 'UTF-8'); ?>">Today</a>
            <a class="calendar-month-control" href="<?php echo htmlspecialchars(calendarViewerPageUrl($calendar_month['next_month'], $calendar_view_mode), ENT_QUOTES, 'UTF-8'); ?>" aria-label="View <?php echo htmlspecialchars((new DateTimeImmutable($calendar_month['next_month'] . '-01'))->format('F Y'), ENT_QUOTES, 'UTF-8'); ?>">
                Next <span aria-hidden="true">→</span>
            </a>
        </nav>
        <nav class="calendar-agenda-navigation" aria-label="Daily agenda navigation">
            <a class="calendar-month-control calendar-agenda-arrow" href="<?php echo htmlspecialchars(calendarViewerPageUrl(null, $calendar_view_mode, $calendar_day['previous_day'], true), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous day, <?php echo htmlspecialchars((new DateTimeImmutable($calendar_day['previous_day']))->format('l, F j, Y'), ENT_QUOTES, 'UTF-8'); ?>">
                <span aria-hidden="true">←</span>
            </a>
            <a class="calendar-month-control calendar-month-today" href="<?php echo htmlspecialchars(calendarViewerPageUrl(null, $calendar_view_mode, $calendar_day['today'], true), ENT_QUOTES, 'UTF-8'); ?>"<?php if ($calendar_day['is_today']): ?> aria-current="date"<?php endif; ?>>Today</a>
            <a class="calendar-month-control calendar-agenda-arrow" href="<?php echo htmlspecialchars(calendarViewerPageUrl(null, $calendar_view_mode, $calendar_day['next_day'], true), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next day, <?php echo htmlspecialchars((new DateTimeImmutable($calendar_day['next_day']))->format('l, F j, Y'), ENT_QUOTES, 'UTF-8'); ?>">
                <span aria-hidden="true">→</span>
            </a>
        </nav>
    </div>

    <nav class="calendar-view-filters" aria-label="Calendar content">
        <?php foreach ($calendar_mode_labels as $mode_value => $mode_label): ?>
            <?php
            $filter_classes = ['calendar-view-filter', 'calendar-view-filter-' . str_replace('_', '-', $mode_value)];
            $is_current_mode = $calendar_view_mode === $mode_value;
            if ($is_current_mode) $filter_classes[] = 'is-active';
            ?>
            <a class="<?php echo implode(' ', $filter_classes); ?>" href="<?php echo htmlspecialchars(calendarViewerPageUrl($calendar_month['month'], $mode_value, $has_requested_day ? $calendar_day['date'] : null), ENT_QUOTES, 'UTF-8'); ?>"<?php if ($is_current_mode): ?> aria-current="page"<?php endif; ?>>
                <span class="calendar-filter-swatches" aria-hidden="true">
                    <?php if ($mode_value === 'events' || $mode_value === 'everything'): ?><span class="calendar-filter-swatch calendar-filter-swatch-event"></span><?php endif; ?>
                    <?php if (in_array($mode_value, ['my_tasks', 'all_tasks', 'everything'], true)): ?><span class="calendar-filter-swatch calendar-filter-swatch-my-task"></span><?php endif; ?>
                    <?php if (in_array($mode_value, ['all_tasks', 'everything'], true)): ?><span class="calendar-filter-swatch calendar-filter-swatch-other-task"></span><?php endif; ?>
                    <?php if ($mode_value === 'birthdays' || $mode_value === 'everything'): ?><span class="calendar-filter-swatch calendar-filter-swatch-birthday"></span><?php endif; ?>
                </span>
                <?php echo htmlspecialchars($mode_label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($calendar_viewer_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($calendar_viewer_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php else: ?>
        <div class="calendar-daily-agenda" aria-labelledby="calendar-agenda-title">
            <?php if ($calendar_day_events === [] && $calendar_day_birthdays === [] && $calendar_day_tasks === []): ?>
                <p class="calendar-agenda-empty">Nothing scheduled for this day in the current view.</p>
            <?php else: ?>
                <?php if ($calendar_day_events !== []): ?>
                    <section class="calendar-agenda-group" aria-labelledby="calendar-agenda-events-title">
                        <h3 id="calendar-agenda-events-title">Events <span><?php echo count($calendar_day_events); ?></span></h3>
                        <div class="calendar-agenda-items">
                            <?php foreach ($calendar_day_events as $engagement): ?>
                                <?php
                                $event_label = calendarViewerEventLabel($engagement);
                                $event_meta = calendarViewerEventMeta($engagement);
                                $event_href = calendarViewerEventUrl($engagement);
                                ?>
                                <a class="calendar-item calendar-event calendar-event-<?php echo calendarViewerEventTone($engagement); ?>" href="<?php echo htmlspecialchars($event_href, ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="calendar-item-title"><?php echo htmlspecialchars($event_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="calendar-item-meta"><?php echo htmlspecialchars(implode(' · ', $event_meta), ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($calendar_day_birthdays !== []): ?>
                    <section class="calendar-agenda-group" aria-labelledby="calendar-agenda-birthdays-title">
                        <h3 id="calendar-agenda-birthdays-title">Birthdays <span><?php echo count($calendar_day_birthdays); ?></span></h3>
                        <div class="calendar-agenda-items">
                            <?php foreach ($calendar_day_birthdays as $birthday): ?>
                                <?php
                                $birthday_label = calendarViewerEventLabel($birthday);
                                $birthday_meta = calendarViewerEventMeta($birthday);
                                $birthday_href = calendarViewerEventUrl($birthday);
                                ?>
                                <a class="calendar-item calendar-event calendar-event-birthday" href="<?php echo htmlspecialchars($birthday_href, ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="calendar-item-title"><?php echo htmlspecialchars($birthday_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="calendar-item-meta"><?php echo htmlspecialchars(implode(' · ', $birthday_meta), ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($calendar_day_tasks !== []): ?>
                    <section class="calendar-agenda-group" aria-labelledby="calendar-agenda-tasks-title">
                        <h3 id="calendar-agenda-tasks-title">Tasks <span><?php echo count($calendar_day_tasks); ?></span></h3>
                        <div class="calendar-agenda-items">
                            <?php foreach ($calendar_day_tasks as $task): ?>
                                <?php
                                $task_tone = calendarViewerTaskTone($task, $user_id);
                                $assignee_label = $task_tone === 'mine'
                                    ? 'My task'
                                    : (trim((string) ($task['assignee_username'] ?? '')) ?: 'Unassigned');
                                $task_status = $calendar_task_status_labels[$task['status'] ?? ''] ?? calendarStatusLabel($task['status'] ?? '');
                                $task_priority = $calendar_task_priority_labels[$task['priority'] ?? ''] ?? calendarStatusLabel($task['priority'] ?? '');
                                $task_href = $can_manage_calendar_tasks
                                    ? 'edit_task.php?id=' . (int) $task['id'] . '&return_to=' . rawurlencode($calendar_return_url)
                                    : 'tasks.php?view=' . ($calendar_view_mode === 'my_tasks' ? 'my' : 'all');
                                ?>
                                <a class="calendar-item calendar-task calendar-task-<?php echo $task_tone; ?>" href="<?php echo htmlspecialchars($task_href, ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="calendar-item-title"><?php echo htmlspecialchars(calendarViewerTaskLabel($task), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="calendar-item-meta"><?php echo htmlspecialchars($assignee_label . ' · ' . $task_priority . ' · ' . $task_status, ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="calendar-month-scroll" tabindex="0" aria-label="<?php echo htmlspecialchars($calendar_month['label'] . ' ' . $calendar_mode_label . ' calendar', ENT_QUOTES, 'UTF-8'); ?>">
            <table class="calendar-month-table">
                <caption class="visually-hidden"><?php echo htmlspecialchars($calendar_month['label'] . ' ' . $calendar_mode_label, ENT_QUOTES, 'UTF-8'); ?></caption>
                <thead>
                    <tr>
                        <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday): ?>
                            <th scope="col"><span class="calendar-weekday-full"><?php echo $weekday; ?></span><span class="calendar-weekday-short" aria-hidden="true"><?php echo substr($weekday, 0, 3); ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_chunk($calendar_month['days'], 7) as $week): ?>
                    <tr>
                    <?php foreach ($week as $date): ?>
                        <?php
                        $day = new DateTimeImmutable($date);
                        $outside_month = $day->format('Y-m') !== $calendar_month['month'];
                        $is_today = $date === $calendar_month['today'];
                        $day_items = $calendar_events_by_date[$date] ?? [];
                        $day_events = array_values(array_filter(
                            $day_items,
                            static fn(array $item): bool => ($item['calendar_item_type'] ?? '') !== 'birthday'
                        ));
                        $day_birthdays = array_values(array_filter(
                            $day_items,
                            static fn(array $item): bool => ($item['calendar_item_type'] ?? '') === 'birthday'
                        ));
                        $day_tasks = $calendar_tasks_by_date[$date] ?? [];
                        $day_classes = ['calendar-month-day'];
                        if ($outside_month) $day_classes[] = 'is-outside-month';
                        if ($is_today) $day_classes[] = 'is-today';
                        ?>
                        <td class="<?php echo implode(' ', $day_classes); ?>">
                            <div class="calendar-day-heading">
                                <time datetime="<?php echo $date; ?>" aria-label="<?php echo htmlspecialchars($day->format('l, F j, Y'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php if ($outside_month || $day->format('j') === '1'): ?><span><?php echo $day->format('M'); ?></span><?php endif; ?>
                                    <?php echo $day->format('j'); ?>
                                </time>
                                <?php if ($is_today): ?><span class="calendar-today-label">Today</span><?php endif; ?>
                            </div>
                            <div class="calendar-day-items">
                                <?php foreach ($day_events as $engagement): ?>
                                    <?php
                                    $event_label = calendarViewerEventLabel($engagement);
                                    $event_meta = calendarViewerEventMeta($engagement);
                                    $event_href = calendarViewerEventUrl($engagement);
                                    ?>
                                    <a class="calendar-item calendar-event calendar-event-<?php echo calendarViewerEventTone($engagement); ?>" href="<?php echo htmlspecialchars($event_href, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="calendar-item-title"><?php echo htmlspecialchars($event_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="calendar-item-meta"><?php echo htmlspecialchars(implode(' · ', $event_meta), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach ($day_birthdays as $birthday): ?>
                                    <?php
                                    $birthday_label = calendarViewerEventLabel($birthday);
                                    $birthday_meta = calendarViewerEventMeta($birthday);
                                    $birthday_href = calendarViewerEventUrl($birthday);
                                    ?>
                                    <a class="calendar-item calendar-event calendar-event-birthday" href="<?php echo htmlspecialchars($birthday_href, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="calendar-item-title"><?php echo htmlspecialchars($birthday_label, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="calendar-item-meta"><?php echo htmlspecialchars(implode(' · ', $birthday_meta), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach ($day_tasks as $task): ?>
                                    <?php
                                    $task_tone = calendarViewerTaskTone($task, $user_id);
                                    $assignee_label = $task_tone === 'mine'
                                        ? 'My task'
                                        : (trim((string) ($task['assignee_username'] ?? '')) ?: 'Unassigned');
                                    $task_status = $calendar_task_status_labels[$task['status'] ?? ''] ?? calendarStatusLabel($task['status'] ?? '');
                                    $task_priority = $calendar_task_priority_labels[$task['priority'] ?? ''] ?? calendarStatusLabel($task['priority'] ?? '');
                                    $task_href = $can_manage_calendar_tasks
                                        ? 'edit_task.php?id=' . (int) $task['id'] . '&return_to=' . rawurlencode($calendar_return_url)
                                        : 'tasks.php?view=' . ($calendar_view_mode === 'my_tasks' ? 'my' : 'all');
                                    ?>
                                    <a class="calendar-item calendar-task calendar-task-<?php echo $task_tone; ?>" href="<?php echo htmlspecialchars($task_href, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="calendar-item-title"><?php echo htmlspecialchars(calendarViewerTaskLabel($task), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="calendar-item-meta"><?php echo htmlspecialchars($assignee_label . ' · ' . $task_priority . ' · ' . $task_status, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
