<?php

declare(strict_types=1);

// Stable entry point for existing routes, workers, and integrations.
require_once __DIR__ . '/moed/tasks/policy.php';
require_once __DIR__ . '/moed/tasks/subjects.php';
require_once __DIR__ . '/moed/tasks/input.php';
require_once __DIR__ . '/moed/tasks/repository.php';
require_once __DIR__ . '/moed/tasks/presentation.php';
require_once __DIR__ . '/moed/tasks/lifecycle.php';
require_once __DIR__ . '/moed/tasks/templates.php';
require_once __DIR__ . '/moed/tasks/generation.php';

require_once __DIR__ . '/task_visibility_helpers.php';
