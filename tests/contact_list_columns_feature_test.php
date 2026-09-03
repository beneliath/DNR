<?php

function expectContactListColumns($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Contact list columns feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$contacts_page = file_get_contents($root . '/src/contacts.php');
$styles = file_get_contents($root . '/src/assets/css/modern.css');
$contact_styles = file_get_contents($root . '/src/assets/css/pages/contacts.css');
$desktop_phone_rule = '';
if (preg_match(
    '/@media\s*\(min-width:\s*761px\)\s*\{\s*\.contact-phone-link\s*\{([^}]*)\}/s',
    $contact_styles,
    $desktop_phone_matches
) === 1) {
    $desktop_phone_rule = $desktop_phone_matches[1];
}

expectContactListColumns(
    str_contains($contacts_page, 'c.contact_phone,')
        && str_contains($contacts_page, 'c.contact_email,'),
    'the contact list query should fetch phone numbers and email addresses.'
);

expectContactListColumns(
    str_contains($contacts_page, '<th>Phone number</th>')
        && str_contains($contacts_page, '<th>Email address</th>')
        && str_contains($contacts_page, 'colspan="5"'),
    'the table headings and empty state should account for both contact-method columns.'
);

expectContactListColumns(
    str_contains($contacts_page, 'href="tel:')
        && str_contains($contacts_page, 'class="contact-phone-link"')
        && str_contains($contacts_page, 'formatPhoneNumberForDisplay(')
        && str_contains($contacts_page, 'href="mailto:')
        && !str_contains($contacts_page, '<span class="empty-value"'),
    'contact methods should be actionable links while missing values remain blank.'
);

expectContactListColumns(
    str_contains($desktop_phone_rule, 'font-size: 0.9rem;')
        && str_contains($desktop_phone_rule, 'font-variant-numeric: tabular-nums;')
        && str_contains($desktop_phone_rule, 'white-space: nowrap;'),
    'desktop contact phone links should use modestly smaller, non-wrapping text without changing mobile typography.'
);

expectContactListColumns(
    str_contains($styles, '.contact-table td:nth-child(3)::before { content: "Phone number"; }')
        && str_contains($styles, '.contact-table td:nth-child(4)::before { content: "Email address"; }')
        && str_contains($styles, '.contact-table td:nth-child(5)::before { content: "Actions"; }'),
    'responsive contact cards should label the new columns correctly.'
);

expectContactListColumns(
    preg_match('/\.contact-table a\s*\{[^}]*text-decoration:\s*none\s*!important;/s', $styles) === 1,
    'links in the contact table should not be underlined.'
);

echo "Contact list columns feature tests passed.\n";
