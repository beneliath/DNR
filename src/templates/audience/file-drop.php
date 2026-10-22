<label for="<?= $h($uploadId) ?>"><?= $h($uploadLabel) ?></label>
<div class="audience-file-drop" data-file-drop role="group" aria-label="<?= $h($uploadLabel) ?>">
    <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M14 33H11a8 8 0 0 1-1-16 13 13 0 0 1 25-2 9 9 0 0 1 2 18h-3M24 39V22m-7 7 7-7 7 7"/></svg>
    <strong>Drag and drop your file here</strong>
    <span id="<?= $h($uploadId) ?>-help"><?= $h($uploadHelp) ?></span>
    <button type="button" data-file-browse hidden>Browse files</button>
    <input id="<?= $h($uploadId) ?>" type="file" accept="<?= $h($uploadAccept) ?>" aria-describedby="<?= $h($uploadId) ?>-help <?= $h($uploadId) ?>-status" data-file-types="<?= $h($uploadTypes) ?>"<?php if($uploadKey==='web_font'): ?> data-brand-font<?php else: ?> data-content-upload="<?= $h($uploadKey) ?>"<?php endif; ?><?= $uploadKey==='image'?' name="image_file"':'' ?>>
    <p id="<?= $h($uploadId) ?>-status" data-file-drop-status role="status" aria-live="polite"><?= $h($uploadStatus??'No file selected.') ?></p>
</div>
