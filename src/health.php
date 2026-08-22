<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['status' => 'ok', 'request_id' => applicationRequestId()], JSON_THROW_ON_ERROR);
