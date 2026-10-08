<?php
declare(strict_types=1);
putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('K',32)));
putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R',32)));
require_once __DIR__ . '/../src/inbound_email_helpers.php';
require_once __DIR__ . '/../src/account_mail_helpers.php';
// The pure routing test supplies environment-only secrets without opening a DB.
function configurationSecret($name, $default = '') { return getenv($name) ?: $default; }
function checkAccountMail(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$keys = ['account-alpha'=>str_repeat('a',64), 'account-beta'=>str_repeat('b',64)];
$lookup = static fn(string $key) => $keys[$key] ?? null;
$marker = static fn(string $key, string $type, int $id) => '[MOED@' . $key . '#' . $type . $id . '.' . accountMailMarkerTag($key,$type,$id,$keys[$key]) . ']';
$alpha = $marker('account-alpha','E',7); $beta = $marker('account-beta','E',7);
foreach ([$alpha, 'Reply ' . $alpha . "\n" . $alpha, $alpha . ' ' . $marker('account-alpha','I',7),
    $alpha . ' ' . $marker('account-alpha','E',8)] as $text) {
    checkAccountMail(resolveAccountMailMarkers($text,$lookup)['account_key'] === 'account-alpha', 'Same-Account tokens should select exactly that Account.');
}
checkAccountMail(resolveAccountMailMarkers($beta,$lookup)['account_key']==='account-beta', 'Identical record IDs in different Accounts remain isolated.');
foreach (['A known sender with no token', $alpha . ' ' . $beta, str_replace('account-alpha','account-beta',$alpha),
    str_replace('#E7.','#E8.',$alpha), str_replace('#E7.','#I7.',$alpha),
    $alpha . ' [MOED@bad]', $alpha . ' [MOED@unfinished', $alpha . ' [DNR#unfinished',
    $alpha . ' [UNKNOWN#7.invalid]', str_repeat($alpha . ' ',65),
    '[MOED@account-alpha#E2147483648.' . str_repeat('a',22) . ']'] as $text) {
    checkAccountMail(resolveAccountMailMarkers($text,$lookup)['account_key']===null, 'Missing, conflicting, incomplete, or tampered tokens must be quarantined.');
}
checkAccountMail(resolveAccountMailMarkers($beta, static fn(string $key)=>null)['account_key']===null, 'Unavailable Accounts must not receive mail.');
putenv('DNR_ACCOUNTS_ENABLED=1'); putenv('DNR_ACCOUNT_KEY=account-alpha'); putenv('DNR_ACCOUNT_MODE=member');
putenv('DNR_PLATFORM_API_KEY=' . $keys['account-alpha']);
checkAccountMail(!accountMailEnabled(), 'Unmigrated installations must not activate the new mail queue.');
putenv('DNR_ACCOUNT_MAIL_ENABLED=1');
checkAccountMail(applicationInboundMarker(7)===$alpha, 'Outbound Engagement markers carry the Account.');
checkAccountMail(parseInboundEmailEngagementMarkers($alpha)['ids']===[7], 'Local record routing accepts its Account token.');
checkAccountMail(parseInboundEmailEngagementMarkers($beta)['ids']===[], 'Local record routing rejects a foreign Account token.');
checkAccountMail(parseInboundEmailInquiryMarkers(applicationInquiryInboundMarker(7))['ids']===[7], 'Inquiry markers retain their record type.');
putenv('DNR_ACCOUNT_MODE=primary');
$prefix=deploymentConfig()->string('inbound_email.emitted_marker_prefix');
$legacy='[' . $prefix . '#7.' . applicationInboundMarkerTag(7,$prefix) . ']';
checkAccountMail(localAccountMailRoute(['subject'=>$legacy])['account_key']==='account-alpha', 'Existing primary Account reply tokens remain valid.');
checkAccountMail(resolveAccountMailMarkers($legacy . ' ' . $beta,$lookup,true)['account_key']===null, 'Legacy primary and member tokens must not be mixed.');
checkAccountMail(resolveAccountMailMarkers(str_repeat($alpha,32) . str_repeat($legacy,32),$lookup,true)['account_key']==='account-alpha', '64 mixed current/legacy markers are accepted.');
checkAccountMail(resolveAccountMailMarkers(str_repeat($alpha,32) . str_repeat($legacy,33),$lookup,true)['account_key']===null, 'The 64-marker budget is shared by both formats.');
checkAccountMail(resolveAccountMailMarkers(str_replace('MOED@','moed@',$alpha),$lookup)['account_key']==='account-alpha', 'Marker prefixes remain case insensitive.');
// Run at a realistic message limit under a small heap; repeated tiny markers
// used to allocate hundreds of MiB before the 64-marker guard ran.
ini_set('memory_limit', '64M');
foreach (['[MOED@]', '[DNR#]', '[MOED@unfinished', '[DNR#unfinished', 'ordinary body ', $alpha, $legacy] as $piece) {
    $large = str_repeat($piece, (int) ceil(16777216 / strlen($piece)));
    checkAccountMail(resolveAccountMailMarkers($large,$lookup,true)['account_key']===null, 'Maximum-sized unrouteable bodies fail closed without heap amplification.');
    unset($large);
}
$large = str_repeat('x',16777000) . $alpha;
checkAccountMail(resolveAccountMailMarkers($large,$lookup)['account_key']==='account-alpha', 'A valid marker at the end of a maximum-sized body remains discoverable.');
unset($large);
checkAccountMail(resolveAccountMailMarkers($alpha . '[MOED@' . str_repeat('x',1000),$lookup)['account_key']===null, 'Oversized incomplete marker cannot hide behind a valid marker.');
putenv('DNR_ACCOUNT_MODE=member');
checkAccountMail(localAccountMailRoute(['subject'=>$legacy])['account_key']===null, 'Member Accounts cannot claim legacy primary tokens.');
$message = ['deduplication_hash' => hash('sha256', 'manual message', true)];
$assignment = ['account_key' => 'account-alpha', 'fingerprint' => bin2hex($message['deduplication_hash']),
    'reviewed_by' => 17, 'reviewed_at' => '2026-10-08 00:00:00'];
$assignment['signature'] = accountMailManualTag($assignment, $keys['account-alpha']);
checkAccountMail(accountMailManualAssignmentIsValid($assignment, $message, 'account-alpha', $keys['account-alpha']), 'Signed manual assignment is accepted.');
checkAccountMail(!accountMailManualAssignmentIsValid($assignment, $message, 'account-beta', $keys['account-beta']), 'Manual assignment cannot cross Accounts.');
checkAccountMail(!accountMailManualAssignmentIsValid($assignment, ['deduplication_hash'=>random_bytes(32)], 'account-alpha', $keys['account-alpha']), 'Manual assignment cannot be replayed for another message.');
foreach (['reviewed_by'=>18, 'reviewed_at'=>'2026-10-09 00:00:00', 'signature'=>'', 'account_key'=>'account-beta', 'fingerprint'=>str_repeat('0',64)] as $field=>$value) {
    $forged = $assignment; $forged[$field] = $value;
    checkAccountMail(!accountMailManualAssignmentIsValid($forged, $message, 'account-alpha', $keys['account-alpha']), 'Manual decision cannot be changed without its signature.');
}
checkAccountMail(!accountMailManualAssignmentIsValid([], $message, 'account-alpha', $keys['account-alpha']), 'Unsigned manual assignment fails closed.');
checkAccountMail(platformMailDeliveryHasStarted(['attempts'=>1], []), 'Existing attempts prevent reassignment.');
checkAccountMail(platformMailDeliveryHasStarted(['attempts'=>0], ['delivery_account_key'=>'account-alpha']), 'Retry cannot erase a previous delivery attempt.');
echo "Account mail routing tests passed: unique Account/type/ID, legacy tokens, conflict/forgery/unavailable quarantine, bounded parsing, local rejection, activation guard.\n";
