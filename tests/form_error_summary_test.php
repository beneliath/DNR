<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/functions.php';

function expectFormErrorSummary(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

expectFormErrorSummary(formErrorSummary([]) === '', 'No errors should render no summary.');
$html = formErrorSummary(['email' => 'Use an address containing <name> & domain'], ['email' => 'account-email']);
expectFormErrorSummary(
    str_contains($html, 'role="alert" tabindex="-1"')
        && str_contains($html, 'data-error-for="account-email"')
        && str_contains($html, 'href="#account-email"')
        && str_contains($html, '&lt;name&gt; &amp; domain'),
    'An explicitly mapped field error must be linked, focusable, and escaped.'
);
$general = formErrorSummary('Unable to save this record', ['username' => 'username']);
expectFormErrorSummary(
    !str_contains($general, 'data-error-for=') && !str_contains($general, '<a '),
    'General server errors must not falsely mark an unrelated field.'
);
echo "Form error summary tests passed.\n";
