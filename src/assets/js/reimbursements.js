'use strict';
document.querySelectorAll('[data-clear-reimbursement-selection]').forEach(function (node) {
  try { sessionStorage.removeItem(node.dataset.clearReimbursementSelection); } catch (_) { /* Storage is optional. */ }
});
document.querySelectorAll('[data-remove-reimbursement-selection]').forEach(function (node) {
  try {
    const saved = JSON.parse(sessionStorage.getItem(node.dataset.selectionKey));
    if (saved && Array.isArray(saved.ids)) {
      saved.ids = saved.ids.filter(function (id) { return id !== node.dataset.removeReimbursementSelection; });
      sessionStorage.setItem(node.dataset.selectionKey, JSON.stringify(saved));
    }
  } catch (_) { /* Storage is optional. */ }
});

document.querySelectorAll('[data-reimbursement-selection]').forEach(function (form) {
  const key = form.dataset.selectionKey;
  const scope = form.dataset.selectionScope;
  const boxes = Array.from(form.querySelectorAll('input[name="expense_ids[]"]'));
  const buttons = Array.from(document.querySelectorAll('[data-create-reimbursement]'));
  const summaries = Array.from(form.querySelectorAll('[data-selection-count]'));
  const clearButtons = Array.from(form.querySelectorAll('[data-clear-selection]'));
  const selectAllButtons = Array.from(form.querySelectorAll('[data-select-all-available]'));
  const selectionErrors = Array.from(form.querySelectorAll('[data-selection-error]'));
  let selected = new Set();
  let metadata = {};
  function showSelectionError(message) {
    selectionErrors.forEach(function (error) { error.textContent = message; error.hidden = !message; });
  }
  function restore() {
    try {
      const saved = JSON.parse(sessionStorage.getItem(key));
      metadata = saved && saved.scope === scope && saved.metadata && typeof saved.metadata === 'object' ? saved.metadata : {};
      selected = saved && saved.scope === scope && Array.isArray(saved.ids)
        ? new Set(saved.ids.filter(function (id) { return typeof id === 'string' && /^[1-9][0-9]*$/.test(id); }).slice(0, 500))
        : new Set();
    } catch (_) { /* Use the current page if storage is unavailable. */ }
  }
  restore();
  // A selected expense may have been claimed in another tab since the last visit.
  form.querySelectorAll('[data-selection-expense]').forEach(function (row) {
    if (!row.querySelector('input[name="expense_ids[]"]')) selected.delete(row.dataset.selectionExpense);
  });
  boxes.forEach(function (box) {
    if (box.checked) selected.add(box.value); // Preserve a failed server submission.
    box.checked = selected.has(box.value);
    if (box.dataset && box.dataset.amountCents) metadata[box.value] = {amount: Number(box.dataset.amountCents), missing: box.dataset.receiptCount === '0'};
  });
  function update() {
    try { sessionStorage.setItem(key, JSON.stringify({scope: scope, ids: Array.from(selected), metadata: metadata})); } catch (_) {}
    buttons.forEach(function (button) { button.disabled = selected.size === 0; });
    const visible = new Set(boxes.map(function (box) { return box.value; }));
    const offPage = Array.from(selected).filter(function (id) { return !visible.has(id); }).length;
    summaries.forEach(function (summary) {
      summary.parentElement.hidden = false;
      summary.textContent = selected.size + ' expense' + (selected.size === 1 ? '' : 's') + ' selected'
        + (offPage ? ' (' + offPage + ' on other pages)' : '');
    });
    const known = Array.from(selected).filter(function (id) { return metadata[id]; });
    const cents = known.reduce(function (sum, id) { return sum + metadata[id].amount; }, 0);
    const missing = known.filter(function (id) { return metadata[id].missing; }).length;
    form.querySelectorAll('[data-selection-total]').forEach(function (summary) { summary.textContent = 'Selected Total: $' + (cents / 100).toFixed(2) + ' · ' + missing + ' Without Receipts' + (known.length < selected.size ? ' · Refresh older selections to include their totals' : '') + ' (rechecked when creating the draft)'; });
    clearButtons.forEach(function (clear) { clear.hidden = selected.size === 0; });
    form.querySelectorAll('[data-off-page-selection]').forEach(function (input) { input.remove(); });
    selected.forEach(function (id) {
      if (visible.has(id)) return;
      const input = document.createElement('input');
      input.type = 'hidden'; input.name = 'expense_ids[]'; input.value = id;
      input.dataset.offPageSelection = '';
      form.appendChild(input);
    });
  }
  boxes.forEach(function (box) {
    box.addEventListener('change', function () {
      if (box.checked && selected.size >= 500) { box.checked = false; summaries.forEach(function (summary) { summary.textContent = 'Select at most 500 expenses per request.'; }); return; }
      if (box.checked) selected.add(box.value); else selected.delete(box.value);
      showSelectionError('');
      update();
    });
  });
  selectAllButtons.forEach(function (button) {
    button.addEventListener('click', async function () {
      selectAllButtons.forEach(function (item) { item.disabled = true; });
      showSelectionError('');
      try {
        const response = await fetch(form.dataset.availableUrl, {credentials: 'same-origin'});
        if (!response.ok) throw new Error('Unable to load available expenses.');
        const result = await response.json();
        if (result.too_many) { showSelectionError('More than 500 available expenses match these filters. Narrow the list before selecting all.'); return; }
        if (!Array.isArray(result.expenses)) throw new Error('Invalid available expenses response.');
        const available = result.expenses.map(function (expense) { return String(expense.id); });
        if (new Set([...selected, ...available]).size > 500) {
          showSelectionError('The combined selection exceeds 500 expenses. Clear Selection or narrow the list before selecting all.'); return;
        }
        result.expenses.forEach(function (expense) {
          const id = String(expense.id);
          selected.add(id);
          metadata[id] = {amount: Number(expense.amount_cents), missing: Number(expense.receipt_count) === 0};
        });
        boxes.forEach(function (box) { box.checked = selected.has(box.value); });
        update();
      } catch (_) { showSelectionError('Unable to select available expenses. Try again.'); }
      finally { selectAllButtons.forEach(function (item) { item.disabled = false; }); }
    });
  });
  clearButtons.forEach(function (clear) {
    clear.addEventListener('click', function () {
      selected.clear(); boxes.forEach(function (box) { box.checked = false; }); showSelectionError(''); update();
    });
  });
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;
    restore();
    boxes.forEach(function (box) { box.checked = selected.has(box.value); });
    update();
  });
  update();
});

document.querySelectorAll('[data-reimbursement-filters], [data-reimbursement-date-range]').forEach(function (form) {
  const fields = Array.from(form.querySelectorAll('input, select'));
  const apply = form.querySelector('[data-reimbursement-apply], [data-reimbursement-update-dates]');
  if (!apply) return;
  const appliedValues = fields.map(function (field) { return field.value; });
  function updateApplyReminder() {
    const changed = fields.some(function (field, index) { return field.value !== appliedValues[index]; });
    apply.classList.toggle('reimbursement-apply-reminder', changed);
  }
  form.addEventListener('input', updateApplyReminder);
  form.addEventListener('change', updateApplyReminder);
});

document.querySelectorAll('[data-receipt-upload]').forEach(function (upload) {
  const zone = upload.querySelector('[data-receipt-drop]');
  const input = upload.querySelector('[data-receipt-files]');
  const selected = upload.querySelector('[data-receipt-selected]');
  const photo = upload.querySelector('[data-receipt-photo]');
  const photoSelected = upload.querySelector('[data-receipt-photo-selected]');
  const button = zone.querySelector('[data-receipt-drop-button]');
  const status = upload.querySelector('[data-receipt-drop-status]');
  if (!input || !selected || !button || !status) return;

  const allowedExtensions = /\.(jpe?g|png|webp|pdf)$/i;
  const maximumSize = 15 * 1024 * 1024;
  const staging = upload.querySelector('[data-receipt-staging]');
  const existingCount = Number((upload.dataset || {}).existingCount || 0);
  const existingBytes = Number((upload.dataset || {}).existingBytes || 0);
  let stagedFiles = Array.from(input.files || []);
  let previewUrls = [];
  function setFiles(files) {
    const transfer = new DataTransfer();
    files.forEach(function (file) { transfer.items.add(file); });
    input.files = transfer.files;
  }
  function addFiles(files) {
    const combined = stagedFiles.concat(files);
    if (files.some(function (file) { return !allowedExtensions.test(file.name) || file.size > maximumSize || file.size === 0; })) {
      showStatus('Choose JPEG, PNG, WebP, or PDF files up to 15 MB each.', true); setFiles(stagedFiles); return false;
    }
    if (combined.length + existingCount > 20 || combined.reduce(function (sum, file) { return sum + file.size; }, existingBytes) > maximumSize) {
      showStatus('An expense can hold at most 20 receipts and 15 MB total. Remove files or reduce their size.', true); setFiles(stagedFiles); return false;
    }
    stagedFiles = combined; setFiles(stagedFiles); updateSelection(); return true;
  }

  function showStatus(message, isError) {
    status.textContent = message;
    status.classList.toggle('is-error', isError);
  }

  function updateSelection() {
    const files = Array.from(input.files || []);
    stagedFiles = files;
    if (input.form) { const expected = input.form.querySelector('[data-receipt-file-count]'); if (expected) expected.value = String(files.length); }
    if (typeof input.dispatchEvent === 'function') input.dispatchEvent(new Event('input', {bubbles:true}));
    if (staging) {
      previewUrls.forEach(function (url) { URL.revokeObjectURL(url); }); previewUrls = [];
      staging.replaceChildren();
      files.forEach(function (file, index) {
        const row = document.createElement('li');
        if (/^image\//.test(file.type)) {
          const preview = document.createElement('img');
          preview.src = URL.createObjectURL(file); previewUrls.push(preview.src);
          preview.alt = ''; preview.width = 64; preview.height = 64; row.appendChild(preview);
        }
        const label = document.createElement('span'); label.textContent = file.name + ' · ' + (file.size / 1048576).toFixed(2) + ' MB'; row.appendChild(label);
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button-secondary'; remove.textContent = 'Remove';
        remove.setAttribute('aria-label', 'Remove ' + file.name);
        remove.addEventListener('click', function () { stagedFiles.splice(index, 1); setFiles(stagedFiles); updateSelection(); });
        row.appendChild(remove); staging.appendChild(row);
      });
      if (files.length) { const total = document.createElement('li'); total.textContent = 'Selected Files: ' + files.length + ' · ' + (files.reduce(function (sum, file) { return sum + file.size; }, 0) / 1048576).toFixed(2) + ' MB'; staging.appendChild(total); }
    }
    if (!files.length) {
      selected.textContent = 'No Receipts Selected';
      return;
    }
    selected.textContent = files.length === 1 ? files[0].name : files.length + ' receipts selected';
    showStatus('', false);
  }

  const pasteButton = upload.querySelector('[data-receipt-paste]');
  const pasteBox = upload.querySelector('[data-receipt-paste-box]');
  const pasteTarget = upload.querySelector('[data-receipt-paste-target]');
  let clipboardRead = 0;
  function focusPasteTarget() {
    if (pasteBox) pasteBox.hidden = false;
    if (pasteButton) pasteButton.setAttribute('aria-expanded', 'true');
    if (pasteTarget) pasteTarget.focus();
  }
  function appendClipboardImages(files) {
    const images = files.filter(function (file) { return /^image\/(png|jpeg|webp)$/.test(file.type); });
    if (!images.length) { showStatus('Copy an image first, then paste it here.', true); return; }
    if (images.some(function (file) { return file.size > maximumSize; })) {
      showStatus('Receipt images must be 15 MB or smaller.', true); return;
    }
    try {
      const namedImages = images.map(function (file, index) {
        const extension = file.type === 'image/jpeg' ? 'jpg' : file.type.split('/')[1];
        return new File([file], 'receipt-' + Date.now() + '-' + (index + 1) + '.' + extension, { type: file.type });
      });
      if (!addFiles(namedImages)) return;
      if (pasteTarget) pasteTarget.value = '';
      showStatus('Image added. Select Save expense to save the receipt.', false);
    } catch (error) { showStatus('Please save the image and use Upload receipt in this browser.', true); }
  }
  if (pasteButton) {
    pasteButton.addEventListener('click', async function () {
      focusPasteTarget();
      const read = ++clipboardRead;
      showStatus('Choose Paste in your browser’s prompt, or press Command+V / Ctrl+V in the box below.', false);
      if (!navigator.clipboard || !navigator.clipboard.read) {
        showStatus('Press Command+V or Ctrl+V in the paste box to add your image.', false); return;
      }
      try {
        const items = await navigator.clipboard.read();
        const images = [];
        for (const item of items) {
          const type = item.types.find(function (value) { return /^image\/(png|jpeg|webp)$/.test(value); });
          if (type) images.push(await item.getType(type));
        }
        if (read === clipboardRead) appendClipboardImages(images);
      } catch (error) {
        if (read === clipboardRead) showStatus('Press Command+V or Ctrl+V in the paste box to add your image.', false);
      }
    });
    upload.addEventListener('paste', function (event) {
      const clipboard = event.clipboardData;
      let files = Array.from(clipboard ? clipboard.files || [] : []);
      if (!files.length && clipboard) {
        files = Array.from(clipboard.items || []).filter(function (item) { return item.kind === 'file'; })
          .map(function (item) { return item.getAsFile(); }).filter(Boolean);
      }
      if (!files.some(function (file) { return file.type.startsWith('image/'); })) {
        if (event.target === pasteTarget) {
          event.preventDefault();
          showStatus('No image found. Copy the image itself, rather than its filename or link, then paste again.', true);
        }
        return;
      }
      event.preventDefault();
      ++clipboardRead; // Ignore an outstanding button read after a successful keyboard paste.
      appendClipboardImages(files);
    });
  }

  button.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () {
    try { addFiles(Array.from(input.files || [])); }
    catch (_) { showStatus('Unable to stage these receipts. Please select the files again.', true); }
  });
  if (photo && photoSelected) {
    photo.addEventListener('change', function () {
      const files = Array.from(photo.files || []);
      try { if (addFiles(files)) { photo.value = ''; photoSelected.textContent = 'Photo Added to Receipts'; } }
      catch (_) { showStatus('Unable to add the photo. Use Upload Receipt.', true); }
    });
  }

  ['dragenter', 'dragover'].forEach(function (type) {
    zone.addEventListener(type, function (event) {
      event.preventDefault();
      zone.classList.add('is-dragging');
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    });
  });
  zone.addEventListener('dragleave', function (event) {
    if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-dragging');
  });
  zone.addEventListener('drop', function (event) {
    event.preventDefault();
    zone.classList.remove('is-dragging');
    const dropped = Array.from(event.dataTransfer ? event.dataTransfer.files : []);
    if (!dropped.length) return;
    if (dropped.some(function (file) { return !allowedExtensions.test(file.name) || file.size > maximumSize; })) {
      showStatus('Choose JPEG, PNG, WebP, or PDF files up to 15 MB each.', true);
      return;
    }
    try {
      addFiles(dropped);
    } catch (error) {
      showStatus('Please click to choose receipt files in this browser.', true);
    }
  });
});

// Keep application theme rules out of the email's own layout and colors.
document.querySelectorAll('[data-reimbursement-email-preview]').forEach(function (preview) {
  const template = preview.querySelector('template');
  if (!template) return;
  const root = preview.attachShadow({mode: 'open'});
  root.appendChild(template.content.cloneNode(true));
});

// Warn only for genuinely unsaved entry; prevent accidental repeat submission.
document.querySelectorAll('[data-expense-save]').forEach(function (form) {
  let dirty = false, saving = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('change', function () { dirty = true; });
  window.addEventListener('beforeunload', function (event) { if (dirty && !saving) { event.preventDefault(); event.returnValue = ''; } });
  form.addEventListener('submit', function (event) {
    if (saving) { event.preventDefault(); return; }
    if (!form.checkValidity()) return;
    saving = true;
    form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; button.textContent = 'Saving…'; });
  });
  window.addEventListener('pageshow', function (event) { if (event.persisted) { saving = false; form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = false; button.textContent = 'Save Expense'; }); } });
});
document.querySelectorAll('[data-receipt-thumbnail]').forEach(function (image) {
  function showFallback() { image.hidden = true; const fallback = image.nextElementSibling; if (fallback && fallback.hasAttribute('data-pdf-receipt-preview')) fallback.hidden = false; }
  image.addEventListener('error', showFallback);
  if (image.complete && image.naturalWidth === 0) showFallback();
});
// Preserve the current list filters through authorized view/edit/back navigation.
document.querySelectorAll('.reimbursement-page a[href^="reimbursement_expense.php"], .reimbursement-page a[href^="reimbursement_request.php"]').forEach(function (link) {
  if (!window.location || !/\/(reimbursements|reimbursement_requests)\.php$/.test(window.location.pathname)) return;
  const url = new URL(link.href, window.location.href);
  url.searchParams.set('return', window.location.pathname.split('/').pop() + window.location.search);
  link.href = url.href;
});
