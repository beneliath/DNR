<?php
declare(strict_types=1);
require_once __DIR__.'/email_helpers.php';
require_once __DIR__.'/email_ascii_footer.php';

/** Freeze the same MOED palette and card styling used by the daily digest. */
function audienceInboxAlertEmail(string $url,bool $sample=false,string $kind='inbound'):array
{
    if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true))throw new InvalidArgumentException('A valid Inbox URL is required.');
    $h=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES,'UTF-8');
    $subject=($sample?'[Sample] ':'').'New activity in MessageMonkey Inbox';
    $intro=$sample?'This is the sample MessageMonkey Inbox notification you requested.':($kind==='comment'?'A team member added an internal note to a conversation.':'There is a new message in your Audience Inbox.');
    $text=$intro."\n\nOpen Inbox: ".$url."\n\n".($sample?'This is a one-time preview. No customer message content is included.':'You enabled email notifications in Inbox. Manage delivery in Inbox > Notifications.')."\n\n".emailAsciiFooterText();
    $brandName=$h(applicationBrandLabel());$brandSrc=$h(applicationPublicUrl('assets/dnr-logo-email.svg',['v'=>applicationVersion(),'rev'=>'transparent-1']));$monkeySrc=$h(applicationPublicUrl('assets/audience-logo-email.png',['v'=>applicationVersion(),'rev'=>'transparent-1']));$safeUrl=$h($url);
    ob_start();
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title><?= $h($subject) ?></title>
<style>body,table,td,a{font-family:Rubik,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}table{border-collapse:separate;border-spacing:0}a{text-decoration:none}@media(max-width:600px){.inbox-email-gutter{padding:20px 12px!important}.inbox-email-content{padding:26px 22px!important}.inbox-email-header{padding:20px 18px!important}.inbox-email-monkey-cell{width:48px!important;padding-right:14px!important}.inbox-email-monkey{width:48px!important;height:48px!important}.inbox-email-logo{width:190px!important;height:auto!important}}</style></head>
<body bgcolor="#f6f7fb" style="margin:0;padding:0;background:#f6f7fb;color:#172033">
<div style="display:none;max-height:0;overflow:hidden;opacity:0"><?= $h($intro) ?></div>
<table role="presentation" width="100%" bgcolor="#f6f7fb" style="width:100%;background:#f6f7fb"><tr><td align="center" class="inbox-email-gutter" style="padding:32px 20px 40px">
<!--[if mso]><table role="presentation" width="720"><tr><td><![endif]-->
<table role="presentation" width="720" style="width:100%;max-width:720px">
<tr><td class="inbox-email-header" bgcolor="#ffffff" style="padding:22px 28px;border:1px solid #dfe4ec;border-radius:14px;background:#ffffff"><table role="presentation" width="100%" style="width:100%"><tr><td class="inbox-email-monkey-cell" width="56" valign="middle" style="width:56px;padding-right:18px;vertical-align:middle"><img class="inbox-email-monkey" src="<?= $monkeySrc ?>" alt="MessageMonkey" width="56" height="56" style="display:block;width:56px;height:56px;border:0"></td><td valign="middle" style="vertical-align:middle"><img class="inbox-email-logo" src="<?= $brandSrc ?>" alt="<?= $brandName ?>" width="227" height="39" style="display:block;width:227px;max-width:100%;height:auto;border:0"><p style="margin:12px 0 0;color:#8992a3;font-size:11px;letter-spacing:1.4px;font-weight:700;text-transform:uppercase">MessageMonkey · Audience Inbox</p></td></tr></table></td></tr>
<tr><td height="16" style="height:16px"></td></tr>
<tr><td class="inbox-email-content" bgcolor="#ffffff" style="padding:34px 32px;border:1px solid #dfe4ec;border-radius:14px;background:#ffffff">
<?php if($sample): ?><span style="display:inline-block;padding:5px 10px;border-radius:6px;background:#eff4ff;color:#2457d6;font-size:12px;font-weight:700">SAMPLE NOTIFICATION</span><?php endif; ?>
<h1 style="margin:18px 0 14px;color:#172033;font-size:28px;line-height:1.25;font-weight:700">Your Inbox Has New Activity</h1>
<p style="margin:0 0 16px;color:#424d61;font-size:16px;line-height:1.7"><?= $h($intro) ?></p>
<p style="margin:0 0 28px;color:#667085;font-size:15px;line-height:1.7">Open the conversation to read the message, reply, or leave a note for your team.</p>
<table role="presentation"><tr><td bgcolor="#2457d6" style="padding:14px 24px;border-radius:9px;background:#2457d6"><a href="<?= $safeUrl ?>" style="display:inline-block;color:#ffffff;font-size:16px;font-weight:700;line-height:1.3;text-decoration:none">Open Inbox &#8594;</a></td></tr></table>
<p style="margin:26px 0 0;padding-top:20px;border-top:1px solid #e7ebf1;color:#8992a3;font-size:12px;line-height:1.7"><?= $sample?'This is a one-time preview email. No customer message content is included.':'You enabled email notifications in Inbox. Manage your preferences in Inbox &gt; Notifications.' ?></p>
</td></tr>
<tr><td align="center" style="padding:18px 8px 2px"><?= renderEmailAsciiFooter() ?></td></tr>
</table><!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
    <?php
    return ['subject'=>$subject,'text'=>$text,'html'=>(string)ob_get_clean(),'inline_images'=>[]];
}

/** Preserve delivery of older queued notifications that embedded their logos. */
function audienceInboxAlertInlineImages(array $images):array
{
    if(count($images)>2)throw new DomainException('Invalid Inbox email images.');
    $files=[];foreach($images as$image)$files[]=emailInlineImages([$image])[0];return $files;
}
