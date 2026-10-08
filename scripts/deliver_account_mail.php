<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/inbound_email_helpers.php';
require_once '/var/www/html/account_mail_helpers.php';
if (!accountMailEnabled() || !accountIsPrimary()) exit(0);
echo json_encode(['processed' => deliverPlatformInboundMail($conn)]), "\n";
