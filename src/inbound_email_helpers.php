<?php

declare(strict_types=1);

// Stable entry point for existing routes, workers, and integrations.
require_once __DIR__ . '/moed/inbound/parsing.php';
require_once __DIR__ . '/moed/inbound/storage.php';
require_once __DIR__ . '/moed/inbound/sender-authentication.php';
require_once __DIR__ . '/moed/inbound/matching.php';
require_once __DIR__ . '/moed/inbound/markers.php';
require_once __DIR__ . '/moed/inbound/routing.php';
require_once __DIR__ . '/moed/inbound/processing.php';
require_once __DIR__ . '/moed/inbound/queue.php';

require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/user_time_helpers.php';
require_once __DIR__ . '/mattermost_email_helpers.php';


const INBOUND_EMAIL_MAX_RAW_BYTES = 10485760;
const INBOUND_EMAIL_MAX_HEADER_BYTES = 32768;
const INBOUND_EMAIL_MAX_PARTICIPANTS = 100;
const INBOUND_EMAIL_MAX_CHRON_CHARACTERS = 100000;
