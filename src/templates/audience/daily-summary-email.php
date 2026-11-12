<!doctype html>
<html lang="en" style="color-scheme:light only">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light">
    <title>MessageMonkey Daily Audience Summary</title>
    <style>
        body, table, td, a { font-family:Rubik,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
        table { border-collapse:separate; border-spacing:0; }
        a { text-decoration:none; }
        @media only screen and (max-width:620px) {
            .email-gutter { padding:20px 12px!important; }
            .masthead-logo { width:165px!important; }
            .monkey-logo-cell { width:46px!important; padding-right:12px!important; }
            .monkey-logo { width:46px!important; height:46px!important; }
            .summary-cell { display:block!important; box-sizing:border-box!important; width:100%!important; padding:0 0 6px!important; }
        }
        @media (prefers-color-scheme:dark) {
            .email-canvas { background:#f6f7fb!important; color:#172033!important; }
            .email-surface { background:#fff!important; color:#172033!important; }
        }
    </style>
</head>
<body class="email-canvas" bgcolor="#f6f7fb" style="margin:0;padding:0;background:#f6f7fb;color:#172033">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent"><?= $h($summary) ?></div>
<table role="presentation" width="100%" class="email-canvas" bgcolor="#f6f7fb" style="width:100%;background:#f6f7fb">
    <tr><td align="center" class="email-gutter" style="padding:28px 20px 36px">
        <!--[if mso]><table role="presentation" width="720" align="center"><tr><td><![endif]-->
        <table role="presentation" width="720" style="width:100%;max-width:720px">
            <tr><td class="email-surface" bgcolor="#ffffff" style="padding:17px 22px;border:1px solid #dfe4ec;border-radius:14px;background:#fff">
                <table role="presentation" cellspacing="0" cellpadding="0"><tr>
                    <td class="monkey-logo-cell" width="56" valign="middle" style="width:56px;padding-right:16px;vertical-align:middle">
                        <img class="monkey-logo" src="<?= $h($monkeyLogoUrl) ?>" alt="MessageMonkey" width="56" height="56" style="display:block;width:56px;height:56px;border:0">
                    </td>
                    <td valign="middle" style="vertical-align:middle">
                        <a href="<?= $h($analyticsUrl) ?>" style="display:block">
                            <img class="masthead-logo" src="<?= $h($brandLogoUrl) ?>" alt="<?= $h(audienceSummaryLabel(applicationBrandLabel()) ?: 'MOED') ?>" width="227" height="39" style="display:block;width:227px;max-width:100%;height:auto;border:0">
                        </a>
                    </td>
                </tr><tr>
                    <td></td>
                    <td style="padding-top:7px;color:#667085;font-size:10px;font-weight:700;letter-spacing:.13em;text-transform:none">MessageMonkey</td>
                </tr></table>
            </td></tr>
            <tr><td style="padding:26px 2px 18px">
                <h1 style="margin:0;color:#172033;font-size:28px;line-height:1.22;letter-spacing:-.025em">Daily Audience Summary</h1>
                <p style="margin:7px 0 0;color:#475467;font-size:14px;line-height:1.55"><?= $h($audienceName) ?> · <?= $h((new DateTimeImmutable($day))->format('F j, Y')) ?> (UTC)</p>
            </td></tr>
            <tr><td>
                <table role="presentation" width="100%" style="width:100%;table-layout:fixed"><tr>
                    <?php foreach ($sections as $kind => $section): ?>
                        <td width="33%" class="summary-cell" valign="top" style="padding:0 3px">
                            <table role="presentation" width="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;border:1px solid #dfe4ec;border-radius:12px;background:#fff">
                                <tr><td style="padding:13px 14px">
                                    <strong style="display:inline-block;padding:3px 9px;border-radius:8px;background:<?= $section['tint'] ?>;color:<?= $section['color'] ?>;font-size:22px;line-height:1.2"><?= count($groups[$kind]) ?></strong>
                                    <div style="margin-top:7px;color:#475467;font-size:12px;line-height:1.4"><?= $section['title'] ?></div>
                                </td></tr>
                            </table>
                        </td>
                    <?php endforeach; ?>
                </tr></table>
            </td></tr>
            <?php foreach ($sections as $kind => $section): ?>
                <tr><td style="padding-top:16px">
                    <table role="presentation" width="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;border:1px solid #dfe4ec;border-radius:12px;background:#fff">
                        <tr><td bgcolor="<?= $section['tint'] ?>" style="padding:15px 18px;background:<?= $section['tint'] ?>;border-radius:11px 11px 0 0">
                            <h2 style="margin:0;color:#172033;font-size:17px;line-height:1.3"><?= $section['title'] ?></h2>
                        </td></tr>
                        <?php if (!$groups[$kind]): ?>
                            <tr><td style="padding:13px 18px;border-top:1px solid #dfe4ec;color:#667085;font-size:13px"><?= $section['empty'] ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($groups[$kind] as $row): ?>
                            <tr><td style="border-top:1px solid #dfe4ec">
                                <a href="<?= $h($row['url']) ?>" style="display:block;padding:13px 18px;color:#172033;text-decoration:none">
                                    <strong style="color:#2457d6;font-size:15px;line-height:1.4;text-decoration:underline"><?= $h($row['name']) ?></strong>
                                    <?php if ($row['address'] !== ''): ?>
                                        <span style="color:#475467;font-size:13px"> · <?= $h($row['address']) ?></span>
                                    <?php endif; ?>
                                    <span style="display:block;margin-top:4px;color:#475467;font-size:12px;line-height:1.5"><?= $section['place'] ?> · <?= $h($row['location']) ?></span>
                                    <?php if (!empty($row['current_estimate'])): ?>
                                        <span style="display:block;color:#475467;font-size:12px;line-height:1.5">Current IP estimate · <?= $h($row['current_estimate']) ?></span>
                                    <?php endif; ?>
                                    <span style="display:block;color:#667085;font-size:12px;line-height:1.5"><?= $section['action'] ?> · <?= $h($row['time']) ?></span>
                                </a>
                            </td></tr>
                        <?php endforeach; ?>
                    </table>
                </td></tr>
            <?php endforeach; ?>
            <tr><td align="center" style="padding:24px 12px 8px">
                <a href="<?= $h($analyticsUrl) ?>" style="display:inline-block;padding:11px 17px;border-radius:9px;background:#2457d6;color:#fff;font-size:13px;font-weight:700">Open Audience Analytics</a>
            </td></tr>
            <?php if ($usesDbip): ?><tr><td align="center" style="font-size:12px"><a href="https://db-ip.com/">IP geolocation by DB-IP</a></td></tr><?php endif; ?>
            <tr><td align="center" style="padding:34px 8px 2px"><?= renderEmailAsciiFooter() ?></td></tr>
        </table>
        <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
</table>
</body>
</html>
