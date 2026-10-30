<?php

declare(strict_types=1);

// Stable entry point for existing routes, workers, and integrations.
require_once __DIR__ . '/shared/rendering.php';
require_once __DIR__ . '/shared/http-security.php';
require_once __DIR__ . '/shared/sessions.php';
require_once __DIR__ . '/shared/validation.php';
require_once __DIR__ . '/shared/addresses.php';
require_once __DIR__ . '/shared/phones.php';
require_once __DIR__ . '/shared/records.php';
require_once __DIR__ . '/shared/authentication.php';
require_once __DIR__ . '/shared/audit.php';
require_once __DIR__ . '/shared/authentication-limits.php';
require_once __DIR__ . '/shared/authorization.php';
require_once __DIR__ . '/shared/csrf.php';

require_once __DIR__.'/audience_pagination.php';
require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/user_time_helpers.php';
require_once __DIR__ . '/account_helpers.php';
require_once __DIR__ . '/account_login_helpers.php';
require_once __DIR__ . '/text_rendering_helpers.php';
require_once __DIR__ . '/ai_coach_helpers.php';
require_once __DIR__ . '/app/Service/ArchiveService.php';
require_once __DIR__ . '/app/Http/ClientAddress.php';
