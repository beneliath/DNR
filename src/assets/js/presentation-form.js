(function () {
    "use strict";

    var nextPresentationId = 1;

    function presentationEntries() {
        return Array.from(document.querySelectorAll(".presentation-entry"));
    }

    function presentationId(entry) {
        return parseInt(entry.id.replace("presentation-", ""), 10);
    }

    function updatePresentationHeadings() {
        presentationEntries().forEach(function (entry, index) {
            var heading = entry.querySelector(".presentation-entry-heading");
            if (heading) {
                heading.textContent = "Presentation " + (index + 1);
            }
        });
    }

    function presentationContainer() {
        return document.querySelector(".presentations-inner-container");
    }

    function defaultSpeaker() {
        var container = document.getElementById("presentations-container");
        return container ? container.dataset.defaultSpeaker || "" : "";
    }

    function validTime(time) {
        var match = time.match(/^([0-9]{1,2}):([0-9]{2})$/);
        if (!match) {
            return false;
        }
        var hours = parseInt(match[1], 10);
        var minutes = parseInt(match[2], 10);
        return hours >= 1 && hours <= 12 && minutes >= 0 && minutes <= 59;
    }

    function validWholeNumber(value, minimum, maximum) {
        if (!/^\d+$/.test(value)) {
            return false;
        }
        var number = Number(value);
        return Number.isSafeInteger(number) && number >= minimum && number <= maximum;
    }

    function compact24HourTime(time) {
        var compactValue = time.trim();
        var match = compactValue.match(/^([01][0-9]|2[0-3])([0-5][0-9])$/);
        if (!match) {
            return null;
        }
        if (compactValue.charAt(0) !== "0" && parseInt(compactValue, 10) < 1300) {
            return null;
        }
        var hours = parseInt(match[1], 10);
        var displayHours = hours % 12 || 12;
        return {
            time: String(displayHours).padStart(2, "0") + ":" + match[2],
            period: hours >= 12 ? "PM" : "AM"
        };
    }

    function presentationDropError(files, isPdf) {
        if (!files || files.length !== 1) return "Drop one file at a time.";
        var file = files[0];
        if (!(isPdf ? /\.pdf$/i : /\.pptx?$/i).test(file.name)) {
            return isPdf ? "Choose a PDF file (.pdf)." : "Choose a PowerPoint file (.ppt or .pptx).";
        }
        var limit = isPdf ? 100 : 500;
        if (file.size > limit * 1048576) return "Choose a file no larger than " + limit + " MB.";
        if (file.size === 0) return "Choose a file that is not empty.";
        return "";
    }

    function wirePresentationFileDrop(input, card) {
        var zone = card && card.querySelector("[data-file-drop]");
        if (!zone) return;
        var status = zone.querySelector("[data-file-drop-status]");
        zone.querySelector("[data-file-drop-button]").addEventListener("click", function () {
            input.click();
        });
        zone.addEventListener("dragover", function (event) {
            event.preventDefault();
            zone.classList.add("is-dragging");
            if (event.dataTransfer) event.dataTransfer.dropEffect = "copy";
        });
        zone.addEventListener("dragleave", function (event) {
            if (!zone.contains(event.relatedTarget)) zone.classList.remove("is-dragging");
        });
        zone.addEventListener("drop", function (event) {
            event.preventDefault();
            zone.classList.remove("is-dragging");
            var files = event.dataTransfer && event.dataTransfer.files;
            var error = presentationDropError(files, /\[speaker_notes\]$/.test(input.name));
            status.textContent = error;
            if (error) return;
            input.files = files;
            input.dispatchEvent(new Event("change", { bubbles: true }));
        });
        input.addEventListener("change", function () {
            status.textContent = input.files && input.files[0]
                ? input.files[0].name + " selected. Select Save Changes to upload or replace." : "";
        });
    }

    function shouldReleaseQrPreviews(event) {
        return !event || event.persisted !== true;
    }

    if (typeof module === "object" && module.exports) {
        module.exports = {
            compact24HourTime: compact24HourTime,
            shouldReleaseQrPreviews: shouldReleaseQrPreviews,
            presentationDropError: presentationDropError,
            wirePresentationFileDrop: wirePresentationFileDrop,
            validTime: validTime,
            validWholeNumber: validWholeNumber
        };
    }
    if (typeof document === "undefined") {
        return;
    }

    function applyCompact24HourTime(id) {
        var timeInput = document.getElementById("presentation_time_" + id);
        if (!timeInput) {
            return;
        }
        var convertedTime = compact24HourTime(timeInput.value);
        if (!convertedTime) {
            return;
        }
        timeInput.value = convertedTime.time;
        var periodInput = document.querySelector(
            'input[name="presentation_ampm_' + id + '"][value="' + convertedTime.period + '"]'
        );
        if (periodInput) {
            periodInput.checked = true;
        }
    }

    function updatePresentationTime(id) {
        var timeInput = document.getElementById("presentation_time_" + id);
        var hiddenInput = document.getElementById("presentation_time_hidden_" + id);
        if (!timeInput || !hiddenInput) {
            return;
        }
        var selectedPeriod = document.querySelector(
            'input[name="presentation_ampm_' + id + '"]:checked'
        );
        if (timeInput.value === "") {
            hiddenInput.value = "";
            return;
        }
        if (!validTime(timeInput.value) || !selectedPeriod) {
            hiddenInput.value = "";
            return;
        }
        var parts = timeInput.value.split(":");
        hiddenInput.value = parts[0].padStart(2, "0") + ":" + parts[1] + " " + selectedPeriod.value;
    }

    function updateAllPresentationTimes() {
        presentationEntries().forEach(function (entry) {
            var id = presentationId(entry);
            applyCompact24HourTime(id);
            updatePresentationTime(id);
        });
    }

    function applyPresentationDateConstraints() {
        var startInput = document.getElementById("event_start_date");
        var endInput = document.getElementById("event_end_date");
        if (!startInput || !endInput) {
            return;
        }
        presentationEntries().forEach(function (entry) {
            var dateInput = document.getElementById("presentation_date_" + presentationId(entry));
            if (!dateInput) {
                return;
            }
            dateInput.min = startInput.value || "";
            dateInput.max = endInput.value || "";
        });
    }

    function presentationEntryHasContent(entry) {
        if (entry.querySelector('input[name$="[id]"]')) {
            return true;
        }
        var id = presentationId(entry);
        var topicInput = document.getElementById("presentation_topic_" + id);
        var dateInput = document.getElementById("presentation_date_" + id);
        var timeInput = document.getElementById("presentation_time_" + id);
        var speakerInput = document.getElementById("speaker_id_" + id);
        var durationInput = document.getElementById("duration_minutes_" + id);
        var expectedAttendanceInput = document.getElementById("expected_attendance_" + id);
        var actualAttendanceInput = document.getElementById("actual_attendance_" + id);

        return Boolean(
            (topicInput && topicInput.value.trim())
            || (dateInput && dateInput.value)
            || (timeInput && timeInput.value)
            || (durationInput && durationInput.value !== "" && durationInput.value !== "60")
            || (expectedAttendanceInput && expectedAttendanceInput.value)
            || (actualAttendanceInput && actualAttendanceInput.value)
            || (speakerInput && speakerInput.value.trim()
                && speakerInput.value.trim() !== defaultSpeaker())
            || Array.from(entry.querySelectorAll('input[type="file"]')).some(function (input) {
                return input.files && input.files.length > 0;
            })
            || entry.querySelector('input[name*="[remove_"]:checked')
        );
    }

    function hasPresentation() {
        return presentationEntries().some(function (entry) {
            return presentationEntryHasContent(entry);
        });
    }

    function updateConfirmedAvailability() {
        var status = document.getElementById("confirmation_status");
        if (!status) {
            return;
        }
        var confirmedOption = status.querySelector('option[value="confirmed"]');
        if (confirmedOption) {
            confirmedOption.disabled = !hasPresentation();
        }
        status.setCustomValidity("");
    }

    function validatePresentationEntry(entry, startInput, endInput) {
        var id = presentationId(entry);
        var topicInput = document.getElementById("presentation_topic_" + id);
        var dateInput = document.getElementById("presentation_date_" + id);
        var timeInput = document.getElementById("presentation_time_" + id);
        var durationInput = document.getElementById("duration_minutes_" + id);
        var expectedAttendanceInput = document.getElementById("expected_attendance_" + id);
        var actualAttendanceInput = document.getElementById("actual_attendance_" + id);

        [topicInput, dateInput, timeInput, durationInput, expectedAttendanceInput, actualAttendanceInput].forEach(function (input) {
            if (input) {
                input.setCustomValidity("");
            }
        });
        if (dateInput && dateInput.validity && dateInput.validity.badInput) {
            dateInput.setCustomValidity("Use a valid presentation date.");
            dateInput.reportValidity();
            dateInput.focus();
            return false;
        }
        if (dateInput && dateInput.value && startInput && endInput && startInput.value && endInput.value
            && (dateInput.value < startInput.value || dateInput.value > endInput.value)
        ) {
            dateInput.setCustomValidity("Presentation date must be between the engagement start and end dates.");
            dateInput.reportValidity();
            dateInput.focus();
            return false;
        }
        if (timeInput && timeInput.value !== "" && !validTime(timeInput.value)) {
            timeInput.setCustomValidity("Use a valid presentation time, such as 9:30 AM.");
            timeInput.reportValidity();
            timeInput.focus();
            return false;
        }
        if (durationInput && ((durationInput.validity && durationInput.validity.badInput)
            || (durationInput.value !== "" && !validWholeNumber(durationInput.value, 1, 1440)))) {
            durationInput.setCustomValidity("Enter a duration between 1 and 1440 minutes.");
            durationInput.reportValidity();
            durationInput.focus();
            return false;
        }
        if (expectedAttendanceInput && expectedAttendanceInput.value !== ""
            && !validWholeNumber(expectedAttendanceInput.value, 1, 2147483647)
        ) {
            expectedAttendanceInput.setCustomValidity("Expected attendance must be a whole number of at least 1.");
            expectedAttendanceInput.reportValidity();
            expectedAttendanceInput.focus();
            return false;
        }
        if (actualAttendanceInput && actualAttendanceInput.value !== ""
            && !validWholeNumber(actualAttendanceInput.value, 0, 2147483647)
        ) {
            actualAttendanceInput.setCustomValidity("Actual attendance must be zero or a positive whole number.");
            actualAttendanceInput.reportValidity();
            actualAttendanceInput.focus();
            return false;
        }
        return true;
    }

    function wirePresentationEntry(entry) {
        var id = presentationId(entry);
        var timeInput = document.getElementById("presentation_time_" + id);
        var periodInputs = document.querySelectorAll('input[name="presentation_ampm_' + id + '"]');
        var topicInput = document.getElementById("presentation_topic_" + id);
        var dateInput = document.getElementById("presentation_date_" + id);
        var durationInput = document.getElementById("duration_minutes_" + id);
        var expectedAttendanceInput = document.getElementById("expected_attendance_" + id);
        var actualAttendanceInput = document.getElementById("actual_attendance_" + id);
        var speakerInput = document.getElementById("speaker_id_" + id);
        if (timeInput) {
            timeInput.addEventListener("input", function () {
                timeInput.setCustomValidity("");
                updatePresentationTime(id);
                updateConfirmedAvailability();
            });
            timeInput.addEventListener("blur", function () {
                applyCompact24HourTime(id);
                updatePresentationTime(id);
                updateConfirmedAvailability();
            });
        }
        periodInputs.forEach(function (input) {
            input.addEventListener("change", function () {
                updatePresentationTime(id);
            });
        });
        if (topicInput) {
            topicInput.addEventListener("input", function () {
                topicInput.setCustomValidity("");
                updateConfirmedAvailability();
            });
        }
        if (dateInput) {
            dateInput.addEventListener("change", function () {
                dateInput.setCustomValidity("");
                updateConfirmedAvailability();
            });
        }
        if (durationInput) {
            durationInput.addEventListener("input", function () {
                durationInput.setCustomValidity("");
                updateConfirmedAvailability();
            });
        }
        [speakerInput, expectedAttendanceInput, actualAttendanceInput].forEach(function (input) {
            if (input) {
                input.addEventListener("input", function () {
                    input.setCustomValidity("");
                    updateConfirmedAvailability();
                });
            }
        });
        entry.querySelectorAll("[data-qr-uploader]").forEach(wireQrUploader);
        entry.querySelectorAll("[data-presentation-file-name]").forEach(function (input) {
            var card = input.closest(".presentation-notes-card");
            wirePresentationFileDrop(input, card);
            var remove = card && card.querySelector(".presentation-asset-remove input");
            var notice = card && card.querySelector("[data-file-save-notice]");
            function updateFileSelection() {
                var hasReplacement = Boolean(input.files && input.files[0]);
                var hasRemoval = Boolean(remove && remove.checked);
                var display = input.parentElement.querySelector("[data-selected-file-name]");
                if (display) {
                    display.textContent = hasReplacement
                        ? input.files[0].name
                        : (display.dataset.emptyFileLabel || "No PDF selected");
                }
                if (notice) {
                    var fileType = notice.dataset.fileType;
                    notice.hidden = !hasReplacement && !hasRemoval;
                    if (hasReplacement && hasRemoval) {
                        notice.textContent = "Choose either replacement or removal for this " + fileType
                            + ", then select Save Changes.";
                    } else if (hasRemoval) {
                        notice.textContent = "Removal pending. Select Save Changes to remove the current " + fileType + ".";
                    } else if (hasReplacement) {
                        notice.textContent = "Replacement pending. Select Save Changes to replace the current " + fileType + ".";
                    } else {
                        notice.textContent = "";
                    }
                }
                updateConfirmedAvailability();
            }
            input.addEventListener("change", function () {
                if (input.files && input.files[0] && remove) {
                    remove.checked = false;
                }
                updateFileSelection();
            });
            if (remove) {
                remove.addEventListener("change", function () {
                    if (remove.checked) {
                        input.value = "";
                    }
                    updateFileSelection();
                });
            }
            updateFileSelection();
        });
    }

    function qrUploadMarkup(id, key, label, description) {
        var inputId = key + "_" + id;
        var statusId = inputId + "_status";
        return [
            '<div class="presentation-qr-card" data-qr-uploader>',
            '  <div class="presentation-asset-label">' + label + '</div>',
            '  <p>' + description + '</p>',
            '  <button type="button" class="presentation-qr-preview" data-qr-preview-button data-copy-qr-url="" aria-label="Copy ' + label + '" hidden>',
            '    <img data-qr-preview alt="' + label + '">',
            '    <span>Click QR code to copy</span>',
            '  </button>',
            '  <div class="presentation-qr-actions">',
            '    <button type="button" class="presentation-paste-button" data-paste-qr aria-describedby="' + statusId + '">Paste QR code</button>',
            '    <label class="presentation-file-picker" for="' + inputId + '">Choose image</label>',
            '  </div>',
            '  <input type="file" class="presentation-native-file" name="presentations[' + id + '][' + key + ']" id="' + inputId + '" accept="image/jpeg,image/png,image/webp" data-qr-file>',
            '  <span class="presentation-qr-status" id="' + statusId + '" data-copy-status role="status" aria-live="polite"></span>',
            '</div>'
        ].join("");
    }

    function pdfUploadMarkup(id) {
        var key = "speaker_notes";
        var inputId = key + "_" + id;
        return [
            '  <div class="presentation-notes-card">',
            '    <div class="presentation-upload-details">',
            '    <div class="presentation-asset-label">PDF Speaker Notes</div>',
            '    <p>Anyone with the Speaker Notes QR code can open this PDF without signing in.</p>',
            '    <div class="presentation-pdf-picker-row">',
            '    <label class="presentation-file-picker" for="' + inputId + '">Choose PDF</label>',
            '    <input type="file" class="presentation-native-file" name="presentations[' + id + '][' + key + ']" id="' + inputId + '" accept="application/pdf,.pdf" data-presentation-file-name>',
            '    <span class="presentation-selected-file" data-selected-file-name>No PDF selected</span>',
            '    </div>',
            '    </div>',
            '    <div class="presentation-file-drop" data-file-drop>',
            '      <button type="button" class="presentation-file-drop-button" data-file-drop-button>',
            '        <svg class="presentation-drop-icon" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>',
            '        <strong>Drop PDF here</strong>',
            '        <span>or click to choose · .pdf · up to 100 MB</span>',
            '        <span>Select Save Changes to upload or replace.</span>',
            '      </button>',
            '      <span class="presentation-drop-status" data-file-drop-status role="status" aria-live="polite"></span>',
            '    </div>',
            '  </div>'
        ].join("");
    }

    function slidedeckUploadMarkup(id) {
        var key = "ppt_slidedeck";
        var inputId = key + "_" + id;
        return [
            '  <div class="presentation-notes-card">',
            '    <div class="presentation-upload-details">',
            '    <div class="presentation-asset-label">PPT Slidedeck</div>',
            '    <p>Anyone with the PPT Slidedeck QR code can download this PowerPoint file without signing in.</p>',
            '    <div class="presentation-pdf-picker-row">',
            '    <label class="presentation-file-picker" for="' + inputId + '">Choose PPT</label>',
            '    <input type="file" class="presentation-native-file" name="presentations[' + id + '][' + key + ']" id="' + inputId + '" accept=".ppt,.pptx,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation" data-presentation-file-name>',
            '    <span class="presentation-selected-file" data-selected-file-name>No PowerPoint selected</span>',
            '    </div>',
            '    </div>',
            '    <div class="presentation-file-drop" data-file-drop>',
            '      <button type="button" class="presentation-file-drop-button" data-file-drop-button>',
            '        <svg class="presentation-drop-icon" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>',
            '        <strong>Drop PowerPoint here</strong>',
            '        <span>or click to choose · .ppt or .pptx · up to 500 MB</span>',
            '        <span>Select Save Changes to upload or replace.</span>',
            '      </button>',
            '      <span class="presentation-drop-status" data-file-drop-status role="status" aria-live="polite"></span>',
            '    </div>',
            '  </div>'
        ].join("");
    }

    function presentationAssetsMarkup(id) {
        return [
            '<div class="presentation-assets">',
            '  <div class="presentation-assets-heading">',
            '    <h3>Presentation Files &amp; QR Codes</h3>',
            '    <p>PDF Speaker Notes may be up to 100 MB. PPT Slidedeck (.ppt or .pptx) may be up to 500 MB. Keep each save under 600 MB total. QR codes are generated when you save.</p>',
            '  </div>',
            pdfUploadMarkup(id),
            slidedeckUploadMarkup(id),
            '  <p>Save the presentation to download its unique QR codes.</p>',
            '</div>'
        ].join("");
    }

    function qrImageFromClipboardItems(items) {
        for (var item of Array.from(items || [])) {
            if (item.kind === "file" && /^image\/(jpeg|png|webp)$/.test(item.type)) {
                return item.getAsFile();
            }
        }
        return null;
    }

    function showQrStatus(uploader, message, isError) {
        var status = uploader.querySelector("[data-copy-status]");
        if (!status) return;
        status.textContent = message;
        status.classList.toggle("is-error", Boolean(isError));
    }

    function releaseQrPreview(uploader) {
        var previewButton = uploader.querySelector("[data-qr-preview-button]");
        if (!previewButton) return;
        var previewUrl = previewButton.dataset.copyQrUrl || "";
        if (previewUrl.startsWith("blob:") && typeof URL.revokeObjectURL === "function") {
            URL.revokeObjectURL(previewUrl);
        }
        previewButton.dataset.copyQrUrl = "";
    }

    function setQrImage(uploader, file) {
        if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) {
            showQrStatus(uploader, "Paste or choose a JPEG, PNG, or WebP image.", true);
            return false;
        }
        if (file.size > 5 * 1024 * 1024) {
            showQrStatus(uploader, "QR code images must be 5 MB or smaller.", true);
            return false;
        }

        var input = uploader.querySelector("[data-qr-file]");
        var preview = uploader.querySelector("[data-qr-preview]");
        var previewButton = uploader.querySelector("[data-qr-preview-button]");
        if (!input || !preview || !previewButton
            || typeof DataTransfer === "undefined"
            || typeof URL.createObjectURL !== "function"
        ) {
            showQrStatus(uploader, "This browser cannot attach the pasted image. Choose the image file instead.", true);
            return false;
        }
        var transfer = new DataTransfer();
        var attachedFile = file instanceof File
            ? file
            : new File([file], "pasted-qr.png", { type: file.type });
        transfer.items.add(attachedFile);
        input.files = transfer.files;

        var removal = uploader.querySelector('input[name*="[remove_"]');
        if (removal) removal.checked = false;
        releaseQrPreview(uploader);
        var previewUrl = URL.createObjectURL(input.files[0]);
        preview.src = previewUrl;
        previewButton.dataset.copyQrUrl = previewUrl;
        previewButton.hidden = false;
        showQrStatus(uploader, "QR code ready to save. Click the preview to copy it.", false);
        updateConfirmedAvailability();
        return true;
    }

    function wireQrUploader(uploader) {
        var input = uploader.querySelector("[data-qr-file]");
        var pasteButton = uploader.querySelector("[data-paste-qr]");
        if (input) {
            input.addEventListener("change", function () {
                if (input.files && input.files[0]) setQrImage(uploader, input.files[0]);
            });
        }

        async function clipboardReadIsGranted() {
            if (!navigator.permissions || typeof navigator.permissions.query !== "function") return false;
            try {
                var permission = await navigator.permissions.query({ name: "clipboard-read" });
                return permission.state === "granted";
            } catch (error) {
                return false;
            }
        }

        if (pasteButton) {
            pasteButton.addEventListener("click", async function () {
                pasteButton.focus();
                if (!navigator.clipboard
                    || typeof navigator.clipboard.read !== "function"
                    || !(await clipboardReadIsGranted())
                ) {
                    showQrStatus(uploader, "Press Ctrl/⌘ + V to paste the QR code.", false);
                    return;
                }
                try {
                    var clipboardItems = await navigator.clipboard.read();
                    for (var clipboardItem of clipboardItems) {
                        var imageType = clipboardItem.types.find(function (type) {
                            return /^image\/(jpeg|png|webp)$/.test(type);
                        });
                        if (imageType) {
                            var imageBlob = await clipboardItem.getType(imageType);
                            setQrImage(
                                uploader,
                                new File([imageBlob], "pasted-qr." + imageType.split("/")[1], { type: imageType })
                            );
                            return;
                        }
                    }
                    showQrStatus(uploader, "The clipboard does not contain a supported image.", true);
                } catch (error) {
                    showQrStatus(
                        uploader,
                        "Clipboard access was unavailable. Press Ctrl/⌘ + V while this button is focused.",
                        true
                    );
                }
            });
        }
    }

    function pasteQrImage(event) {
        var pasteButton = document.activeElement;
        if (!pasteButton || !pasteButton.matches("[data-paste-qr]")) return;
        var uploader = pasteButton.closest("[data-qr-uploader]");
        if (!uploader) return;
        var image = qrImageFromClipboardItems(event.clipboardData && event.clipboardData.items);
        if (!image) {
            showQrStatus(uploader, "The clipboard does not contain a supported image.", true);
            return;
        }
        event.preventDefault();
        setQrImage(uploader, image);
    }

    function presentationMarkup(id) {
        return [
            '<h3 class="presentation-entry-heading">Presentation</h3>',
            '<div class="presentation-fields">',
            '  <div class="form-field topic">',
            '    <label for="presentation_topic_' + id + '">Topic/Title</label>',
            '    <input type="text" name="presentations[' + id + '][topic_title]" id="presentation_topic_' + id + '" maxlength="255">',
            '  </div>',
            '  <div class="datetime-row">',
            '    <div class="form-field">',
            '      <label for="presentation_date_' + id + '">Date</label>',
            '      <input type="date" name="presentations[' + id + '][presentation_date]" id="presentation_date_' + id + '">',
            '    </div>',
            '    <div class="form-field">',
            '      <label for="presentation_time_' + id + '">Time</label>',
            '      <div class="time-input-container">',
            '        <input type="text" id="presentation_time_' + id + '" inputmode="numeric" pattern="[0-9]{1,2}:[0-9]{2}" placeholder="HH:MM or 1530">',
            '        <div class="ampm-radio">',
            '          <label><input type="radio" name="presentation_ampm_' + id + '" value="AM" checked> AM</label>',
            '          <label><input type="radio" name="presentation_ampm_' + id + '" value="PM"> PM</label>',
            '        </div>',
            '      </div>',
            '      <input type="hidden" name="presentations[' + id + '][presentation_time]" id="presentation_time_hidden_' + id + '">',
            '    </div>',
            '  </div>',
            '  <div class="speaker-row">',
            '    <div class="form-field speaker">',
            '      <label for="speaker_id_' + id + '">Speaker</label>',
            '      <select name="presentations[' + id + '][speaker_id]" id="speaker_id_' + id + '"></select>',
            '    </div>',
            '    <div class="form-field attendance">',
            '      <label for="duration_minutes_' + id + '">Duration (minutes)</label>',
            '      <input type="number" name="presentations[' + id + '][duration_minutes]" id="duration_minutes_' + id + '" min="1" max="1440" step="1" value="60">',
            '    </div>',
            '  </div>',
            '  <div class="attendance-row">',
            '    <div class="form-field attendance">',
            '      <label for="expected_attendance_' + id + '">Expected Attendance</label>',
            '      <input type="number" name="presentations[' + id + '][expected_attendance]" id="expected_attendance_' + id + '" min="1" step="1">',
            '    </div>',
            '    <div class="form-field attendance">',
            '      <label for="actual_attendance_' + id + '">Actual Attendance</label>',
            '      <input type="number" name="presentations[' + id + '][actual_attendance]" id="actual_attendance_' + id + '" min="0" step="1">',
            '    </div>',
            '  </div>',
                 presentationAssetsMarkup(id),
            '  <div class="remove-btn-container">',
            '    <button type="button" data-remove-presentation="' + id + '" class="remove-presentation-btn">Remove</button>',
            '  </div>',
            '</div>'
        ].join("");
    }

    window.addPresentation = function () {
        updateAllPresentationTimes();
        var entries = presentationEntries();
        var startInput = document.getElementById("event_start_date");
        var endInput = document.getElementById("event_end_date");
        for (var existingEntry of entries) {
            if (!validatePresentationEntry(existingEntry, startInput, endInput)) {
                return;
            }
        }

        var id = nextPresentationId++;
        var entry = document.createElement("div");
        entry.className = "presentation-entry";
        entry.id = "presentation-" + id;
        entry.innerHTML = presentationMarkup(id);
        presentationContainer().appendChild(entry);
        updatePresentationHeadings();
        var speakerSelect = document.getElementById("speaker_id_" + id);
        var sourceSelect = document.getElementById("presentations-container").querySelector('select[name$="[speaker_id]"]');
        Array.from(sourceSelect.options).forEach(function (option) {
            speakerSelect.appendChild(option.cloneNode(true));
        });
        speakerSelect.value = defaultSpeaker();
        wirePresentationEntry(entry);
        applyPresentationDateConstraints();
        updateConfirmedAvailability();
        document.getElementById("presentation_topic_" + id).focus();
    };

    window.removePresentation = function (id) {
        var entry = document.getElementById("presentation-" + id);
        if (entry) {
            entry.querySelectorAll("[data-qr-uploader]").forEach(releaseQrPreview);
            entry.remove();
        }
        updatePresentationHeadings();
        updateConfirmedAvailability();
    };

    window.validateEngagementPresentations = function () {
        updateAllPresentationTimes();
        var startInput = document.getElementById("event_start_date");
        var endInput = document.getElementById("event_end_date");
        var status = document.getElementById("confirmation_status");

        for (var entry of presentationEntries()) {
            if (!validatePresentationEntry(entry, startInput, endInput)) {
                return false;
            }
        }

        if (status && status.value === "confirmed" && !hasPresentation()) {
            status.setCustomValidity("Add at least one presentation before confirming this engagement.");
            status.reportValidity();
            status.focus();
            return false;
        }
        if (status) {
            status.setCustomValidity("");
        }
        return true;
    };

    document.addEventListener("DOMContentLoaded", function () {
        var entries = presentationEntries();
        nextPresentationId = entries.reduce(function (highestId, entry) {
            return Math.max(highestId, presentationId(entry) + 1);
        }, 1);
        entries.forEach(wirePresentationEntry);

        var startInput = document.getElementById("event_start_date");
        var endInput = document.getElementById("event_end_date");
        var status = document.getElementById("confirmation_status");
        if (startInput) {
            startInput.addEventListener("change", applyPresentationDateConstraints);
        }
        if (endInput) {
            endInput.addEventListener("change", applyPresentationDateConstraints);
        }
        if (status) {
            status.addEventListener("change", updateConfirmedAvailability);
        }
        document.querySelectorAll(".engagement-form").forEach(function (form) {
            form.addEventListener("submit", updateAllPresentationTimes);
        });
        document.addEventListener("click", function (event) {
            var addButton = event.target.closest("[data-add-presentation]");
            if (addButton) {
                window.addPresentation();
                return;
            }
            var removeButton = event.target.closest("[data-remove-presentation]");
            if (removeButton) {
                window.removePresentation(parseInt(removeButton.dataset.removePresentation, 10));
            }
        });
        // One delegated listener handles dynamically added presentations and
        // avoids three document listeners for every presentation row.
        document.addEventListener("paste", pasteQrImage);
        window.addEventListener("pagehide", function (event) {
            if (!shouldReleaseQrPreviews(event)) return;
            document.querySelectorAll("[data-qr-uploader]").forEach(releaseQrPreview);
        });
        applyPresentationDateConstraints();
        updateConfirmedAvailability();
    });
})();
