<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
// Public library links have been retired, including links created by older versions.
// Authenticated Content access and the images embedded in emails remain available.
http_response_code(404);exit;
