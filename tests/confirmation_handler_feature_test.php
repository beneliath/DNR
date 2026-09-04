<?php

declare(strict_types=1);

function expectConfirmationHandler(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Confirmation handler feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$footerScript = file_get_contents($root . '/src/assets/js/footer.js');
$pageActions = file_get_contents($root . '/src/assets/js/page-actions.js');
$footerTemplate = file_get_contents($root . '/src/templates/footer.php');
$modernStyles = file_get_contents($root . '/src/assets/css/modern.css');

$nativeDialogUsages = [];
foreach (glob($root . '/src/assets/js/*.js') ?: [] as $scriptPath) {
    if (str_ends_with($scriptPath, '.min.js')) {
        continue;
    }
    $script = file_get_contents($scriptPath);
    if (is_string($script)
        && preg_match('/(?:window\s*\.\s*)?\b(?:alert|confirm|prompt)\s*\(/', $script)
    ) {
        $nativeDialogUsages[] = basename($scriptPath);
    }
}
expectConfirmationHandler(
    $nativeDialogUsages === [],
    'application scripts should not use unthemeable browser alert, confirm, or prompt dialogs.'
);

expectConfirmationHandler(
    is_string($footerScript)
        && str_contains($footerScript, "form.matches('[data-confirm]')")
        && str_contains($footerScript, "event.submitter?.closest('[data-confirm]')")
        && substr_count($footerScript, 'new WeakSet()') >= 2
        && str_contains($footerScript, 'confirmation.showModal()')
        && str_contains($footerScript, 'form.requestSubmit(submitter || undefined)')
        && substr_count($footerScript, 'if (event.defaultPrevented) return;') >= 2
        && str_contains($footerScript, 'form[data-sensitive-action]')
        && str_contains($footerScript, "requiredPhrase = deleting ? 'DELETE USER' : 'RESET 2FA'")
        && str_contains($footerScript, "confirmationInput.setAttribute('aria-invalid', 'true')"),
    'the shared dialog controller should support form and button confirmations, exact phrases, and one-shot resubmission.'
);

expectConfirmationHandler(
    is_string($footerTemplate)
        && str_contains($footerTemplate, '<dialog id="action-confirmation"')
        && str_contains($footerTemplate, '<dialog id="sensitive-action-confirmation"')
        && str_contains($footerTemplate, 'aria-labelledby="action-confirmation-title"')
        && str_contains($footerTemplate, 'aria-describedby="sensitive-action-confirmation-message sensitive-action-confirmation-help"')
        && str_contains($footerTemplate, 'aria-required="true" aria-describedby="sensitive-action-confirmation-help sensitive-action-confirmation-error"')
        && str_contains($footerTemplate, 'id="sensitive-action-confirmation-error"')
        && str_contains($footerTemplate, 'role="alert" hidden'),
    'shared confirmation dialogs should expose labelled, described, accessible in-app controls.'
);

expectConfirmationHandler(
    is_string($modernStyles)
        && substr_count($modernStyles, '--dialog-backdrop:') === 2
        && str_contains($modernStyles, 'background: var(--surface) !important;')
        && str_contains($modernStyles, 'color: var(--text) !important;')
        && str_contains($modernStyles, 'background: var(--dialog-backdrop);')
        && str_contains($modernStyles, '.confirmation-dialog-input')
        && str_contains($modernStyles, '.dialog-inline-error')
        && !str_contains($modernStyles, 'html.dark-mode body div:not(')
        && preg_match(
            '/\.qr-code-preview-frame\s*\{[^}]*background:\s*#ffffff !important;/s',
            $modernStyles
        ) === 1,
    'in-app dialogs and phrase validation should inherit the selected light or dark theme tokens.'
);

expectConfirmationHandler(
    is_string($pageActions)
        && str_contains($pageActions, "document.getElementById('qr-code-preview')")
        && str_contains($pageActions, "previewImage.alt = qrContext + ' QR code preview'")
        && str_contains($pageActions, 'preview.showModal()'),
    'QR clipboard fallback should stay inside the themed app instead of opening an unstyled browser window.'
);

echo "Confirmation handler feature tests passed.\n";
