<?php
require_once __DIR__ . '/../application_runtime.php';
require_once __DIR__ . '/../github_version_helpers.php';

$footer_version = defined('APP_VERSION') ? APP_VERSION : 'dev';
$footer_push = githubPushMetadata();
$footer_timezone_name = applicationTimezoneName();
$footer_short_commit = $footer_push === null ? '' : substr($footer_push['commit'], 0, 7);
$footer_push_label = $footer_push === null
    ? ''
    : githubPushTimestampLabel($footer_push['pushed_at'], $footer_timezone_name);
[$footer_repository_owner, $footer_repository_name] = githubRepositoryParts();
$footer_repository_url = githubRepositoryUrl();
?>
<footer class="app-footer">
    <p>&copy; <?php echo date("Y"); ?> <a class="footer-link" href="<?php echo htmlspecialchars('https://github.com/' . rawurlencode($footer_repository_owner), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($footer_repository_owner, ENT_QUOTES, 'UTF-8'); ?></a> <span aria-hidden="true">·</span> <a class="footer-link" href="<?php echo htmlspecialchars($footer_repository_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($footer_repository_name, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($footer_version, ENT_QUOTES, 'UTF-8'); ?></a><?php if ($footer_push !== null): ?> <span aria-hidden="true">·</span> <time datetime="<?php echo htmlspecialchars($footer_push['pushed_at'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($footer_push_label, ENT_QUOTES, 'UTF-8'); ?></time> <a class="footer-link" href="<?php echo htmlspecialchars($footer_repository_url . '/commit/' . $footer_push['commit'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="View commit <?php echo htmlspecialchars($footer_push['commit'], ENT_QUOTES, 'UTF-8'); ?> on GitHub">(<?php echo htmlspecialchars($footer_short_commit, ENT_QUOTES, 'UTF-8'); ?>)</a><?php endif; ?></p>
    <pre class="footer-ascii-cat" aria-label="ASCII Art Cat">     ("`-''-/").___..--''"`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'
  (il),-''  (li),'  ((!.-'

Genesis 49:9,10 ... Revelation 5:5
         Do you see Him?
    </pre>
</footer>
