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
    <p class="footer-moed-definition">Moed&nbsp;&nbsp;<span class="footer-moed-hebrew" lang="he" dir="rtl">מוֹעֵד</span>&nbsp;&nbsp;=&nbsp;&nbsp;appointment, appointed time</p>
    <pre class="footer-ascii-cat" aria-label="ASCII art cat">
     ("`-''-/").___..--''"`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'
  (il),-''  (li),'  ((!.-'

Genesis 49:9,10 ... Revelation 5:5
         Do you see Him?
    </pre>
</footer>

<dialog id="logout-confirmation" class="confirmation-dialog" aria-labelledby="logout-confirmation-title" aria-describedby="logout-confirmation-message">
    <h2 id="logout-confirmation-title">Log Out?</h2>
    <p id="logout-confirmation-message">You’ll need to sign in again to manage <?php echo htmlspecialchars(applicationBrandName(), ENT_QUOTES, 'UTF-8'); ?> records.</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-logout" class="button-secondary">Cancel</button>
        <button type="button" id="confirm-logout" class="danger-button">Log out</button>
    </div>
</dialog>

<dialog id="delete-confirmation" class="confirmation-dialog" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
    <h2 id="delete-confirmation-title">Delete Permanently?</h2>
    <p id="delete-confirmation-message">Are you sure you want to delete this item?</p>
    <p class="dialog-supporting-text">Archive it instead to keep the record available for later restoration.</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-delete" class="button-secondary" autofocus>Cancel</button>
        <button type="button" id="archive-instead" class="archive-button">Archive instead</button>
        <button type="button" id="confirm-delete" class="danger-button">Delete permanently</button>
    </div>
</dialog>

<dialog id="action-confirmation" class="confirmation-dialog" aria-labelledby="action-confirmation-title" aria-describedby="action-confirmation-message">
    <h2 id="action-confirmation-title">Confirm Action</h2>
    <p id="action-confirmation-message">Continue with this action?</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-action-confirmation" class="button-secondary" autofocus>Cancel</button>
        <button type="button" id="confirm-action" class="save-button">Continue</button>
    </div>
</dialog>

<dialog id="sensitive-action-confirmation" class="confirmation-dialog" aria-labelledby="sensitive-action-confirmation-title" aria-describedby="sensitive-action-confirmation-message sensitive-action-confirmation-help">
    <h2 id="sensitive-action-confirmation-title">Confirm Sensitive Action</h2>
    <p id="sensitive-action-confirmation-message"></p>
    <label for="sensitive-action-confirmation-input" class="confirmation-dialog-label">
        Type <code id="sensitive-action-confirmation-phrase"></code> to continue
    </label>
    <input type="text" id="sensitive-action-confirmation-input" class="confirmation-dialog-input" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-required="true" aria-describedby="sensitive-action-confirmation-help sensitive-action-confirmation-error">
    <p id="sensitive-action-confirmation-help" class="dialog-supporting-text">The phrase must match exactly.</p>
    <p id="sensitive-action-confirmation-error" class="dialog-inline-error" role="alert" hidden></p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-sensitive-action" class="button-secondary">Cancel</button>
        <button type="button" id="confirm-sensitive-action" class="danger-button">Confirm</button>
    </div>
</dialog>

<dialog id="qr-code-preview" class="confirmation-dialog qr-code-preview-dialog" aria-labelledby="qr-code-preview-title" aria-describedby="qr-code-preview-message">
    <h2 id="qr-code-preview-title">QR Code Preview</h2>
    <p id="qr-code-preview-message" class="dialog-supporting-text">Clipboard image access is unavailable. Use this preview to scan or save the QR code.</p>
    <div class="qr-code-preview-frame">
        <img id="qr-code-preview-image" alt="QR code preview">
    </div>
    <div class="confirmation-dialog-actions">
        <button type="button" id="close-qr-code-preview" class="button-secondary" autofocus>Close</button>
    </div>
</dialog>

<?php renderScript('assets/js/page-actions.min.js'); ?>
<?php renderScript('assets/js/footer.min.js'); ?>
