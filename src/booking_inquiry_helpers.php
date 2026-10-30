<?php

declare(strict_types=1);

// Stable entry point for existing routes, workers, and integrations.
require_once __DIR__ . '/moed/inquiries/policy.php';
require_once __DIR__ . '/moed/inquiries/lifecycle.php';
require_once __DIR__ . '/moed/inquiries/repository.php';
require_once __DIR__ . '/moed/inquiries/input.php';
require_once __DIR__ . '/moed/inquiries/persistence.php';
require_once __DIR__ . '/moed/inquiries/conversion.php';
require_once __DIR__ . '/moed/inquiries/email.php';
require_once __DIR__ . '/moed/inquiries/presentation.php';

require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/engagement_email_helpers.php';
require_once __DIR__ . '/contact_organization_helpers.php';
