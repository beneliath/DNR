<?php

declare(strict_types=1);

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: view_calendar.php' . ($query !== '' ? '?' . $query : ''), true, 308);
exit;
