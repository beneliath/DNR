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
    <p class="footer-moed-definition"><a class="footer-link" href="https://www.blueletterbible.org/lexicon/h4150/wlc/wlc/0-1/" target="_blank" rel="noopener noreferrer"><span class="footer-moed-hebrew" lang="he" dir="rtl">מוֹעֵד</span>&nbsp;&nbsp;<span class="footer-moed-translation">=&nbsp;&nbsp;appointment, appointed time</span></a></p>
    <pre class="footer-ascii-cat" aria-label="ASCII art cat"><button type="button" id="open-footer-lion" class="footer-ascii-trigger" aria-label="Open lion ASCII art" aria-haspopup="dialog" aria-controls="footer-lion-dialog">     ("`-''-/").___..--''"`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'
  (il),-''  (li),'  ((!.-'</button>

<a class="footer-link" href="https://www.blueletterbible.org/nkjv/gen/49/9-10/s_49009" target="_blank" rel="noopener noreferrer">Genesis 49: 9, 10</a> ... <a class="footer-link" href="https://www.blueletterbible.org/nkjv/rev/5/5/s_1172005" target="_blank" rel="noopener noreferrer">Revelation 5:5</a>
         <a class="footer-link" href="https://www.blueletterbible.org/faq/knowgod.cfm" target="_blank" rel="noopener noreferrer">Do you see Him?</a>
    </pre>
</footer>

<dialog id="footer-lion-dialog" class="confirmation-dialog footer-lion-dialog" aria-label="Lion ASCII art">
    <pre class="footer-lion-art">                              ,aodObo,
Genesis 49:9, 10           ,AMMMMP~~~~
                        ,MMMMMMMMA.
        |             ,M;'     `YV/'
        |            AM' ,OMA,
        |           AM|   `~VMM,.      .,ama,____,amma,..
        |           MML      )MMMD   .AMMMMMMMMMMMMMMMMMMD.
        |           VMMM    .AMMY'  ,AMMMMMMMMMMMMMMMMMMMMD
        |           `VMM, AMMMV'  ,AMMMMMMMMMMMMMMMMMMMMMMM,                ,
        |            VMMMmMMV'  ,AMY~~''  'MMMMMMMMMMMM' '~~             ,aMM
        |            `YMMMM'   AMM'        `VMMMMMMMMP'_              A,aMMMM
        |             AMMM'    VMMA. YVmmmMMMMMMMMMMML MmmmY          MMMMMMM
        |            ,AMMA   _,HMMMMmdMMMMMMMMMMMMMMMML`VMV'         ,MMMMMMM
        |            AMMMA _'MMMMMMMMMMMMMMMMMMMMMMMMMMA `'          MMMMMMMM
        |           ,AMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMa      ,,,   `MMMMMMM
        |           AMMMMMMMMM'~`YMMMMMMMMMMMMMMMMMMMMMMA    ,AMMV    MMMMMMM
        |           VMV MMMMMV   `YMMMMMMMMMMMMMMMMMMMMMY   `VMMY'  adMMMMMMM
        |           `V  MMMM'      `YMMMMMMMV.~~~~~~~~~,aado,`V''   MMMMMMMMM
        |              aMMMMmv       `YMMMMMMMm,    ,/AMMMMMA,      YMMMMMMMM
        |              VMMMMM,,v       YMMMMMMMMMo oMMMMMMMMM'    a, YMMMMMMM
        |              `YMMMMMY'       `YMMMMMMMY' `YMMMMMMMY     MMmMMMMMMMM
        |               AMMMMM  ,        ~~~~~,aooooa,~~~~~~      MMMMMMMMMMM
        |                 YMMMb,d'         dMMMMMMMMMMMMMD,   a,, AMMMMMMMMMM
        ▼                  YMMMMM, A       YMMMMMMMMMMMMMY   ,MMMMMMMMMMMMMMM
                          AMMMMMMMMM        `~~~~'  `~~~~'   AMMMMMMMMMMMMMMM
 Revelation 5:5           `VMMMMMM'  ,A,                  ,,AMMMMMMMMMMMMMMMM
                        ,AMMMMMMMMMMMMMMA,       ,aAMMMMMMMMMMMMMMMMMMMMMMMMM
                      ,AMMMMMMMMMMMMMMMMMMA,    AMMMMMMMMMMMMMMMMMMMMMMMMMMMM
 Do you see Him?    ,AMMMMMMMMMMMMMMMMMMMMMA   AMMMMMMMMMMMMMMMMMMMMMMMMMMMMM
                   AMMMMMMMMMMMMMMMMMMMMMMMMAaAMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM</pre>
    <div class="confirmation-dialog-actions">
        <button type="button" id="close-footer-lion" class="button-secondary" autofocus>Close</button>
    </div>
</dialog>

<dialog id="logout-confirmation" class="confirmation-dialog" aria-labelledby="logout-confirmation-title" aria-describedby="logout-confirmation-message">
    <h2 id="logout-confirmation-title">Log Out?</h2>
    <p id="logout-confirmation-message">You’ll need to sign in again to manage <?php echo htmlspecialchars(applicationBrandName(), ENT_QUOTES, 'UTF-8'); ?> records.</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-logout" class="button-secondary">Cancel</button>
        <button type="button" id="confirm-logout" class="danger-button">Log Out</button>
    </div>
</dialog>

<dialog id="delete-confirmation" class="confirmation-dialog" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
    <h2 id="delete-confirmation-title">Delete Permanently?</h2>
    <p id="delete-confirmation-message">Are you sure you want to delete this item?</p>
    <p class="dialog-supporting-text">Archive it instead to keep the record available for later restoration.</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-delete" class="button-secondary" autofocus>Cancel</button>
        <button type="button" id="archive-instead" class="archive-button">Archive Instead</button>
        <button type="button" id="confirm-delete" class="danger-button">Delete Permanently</button>
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
