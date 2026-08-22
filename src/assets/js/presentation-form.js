(function () {
    "use strict";

    var nextPresentationId = 1;

    function presentationEntries() {
        return Array.from(document.querySelectorAll(".presentation-entry"));
    }

    function presentationId(entry) {
        return parseInt(entry.id.replace("presentation-", ""), 10);
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

    if (typeof module === "object" && module.exports) {
        module.exports = {
            compact24HourTime: compact24HourTime,
            validTime: validTime
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
        var id = presentationId(entry);
        var topicInput = document.getElementById("presentation_topic_" + id);
        var dateInput = document.getElementById("presentation_date_" + id);
        var timeInput = document.getElementById("presentation_time_" + id);
        var speakerInput = document.getElementById("speaker_name_" + id);
        var attendanceInput = document.getElementById("expected_attendance_" + id);

        return Boolean(
            (topicInput && topicInput.value.trim())
            || (dateInput && dateInput.value)
            || (timeInput && timeInput.value)
            || (attendanceInput && attendanceInput.value)
            || (speakerInput && speakerInput.value.trim()
                && speakerInput.value.trim() !== defaultSpeaker())
        );
    }

    function presentationEntryIsComplete(entry) {
        var id = presentationId(entry);
        var topicInput = document.getElementById("presentation_topic_" + id);
        var dateInput = document.getElementById("presentation_date_" + id);
        var timeInput = document.getElementById("presentation_time_" + id);

        return Boolean(
            topicInput && topicInput.value.trim()
            && dateInput && dateInput.value
            && timeInput && validTime(timeInput.value)
        );
    }

    function hasCompletePresentation() {
        return presentationEntries().some(function (entry) {
            return presentationEntryIsComplete(entry);
        });
    }

    function updateConfirmedAvailability() {
        var status = document.getElementById("confirmation_status");
        if (!status) {
            return;
        }
        var confirmedOption = status.querySelector('option[value="confirmed"]');
        if (confirmedOption) {
            confirmedOption.disabled = !hasCompletePresentation();
        }
        status.setCustomValidity("");
    }

    function validatePresentationEntry(entry, startInput, endInput, requireEntry) {
        var id = presentationId(entry);
        var topicInput = document.getElementById("presentation_topic_" + id);
        var dateInput = document.getElementById("presentation_date_" + id);
        var timeInput = document.getElementById("presentation_time_" + id);
        var shouldValidate = requireEntry || presentationEntryHasContent(entry);

        [topicInput, dateInput, timeInput].forEach(function (input) {
            if (input) {
                input.setCustomValidity("");
            }
        });
        if (!shouldValidate) {
            return true;
        }
        if (!topicInput || topicInput.value.trim() === "") {
            topicInput.setCustomValidity("Enter a topic/title for this presentation.");
            topicInput.reportValidity();
            topicInput.focus();
            return false;
        }
        if (!dateInput || dateInput.value === "") {
            dateInput.setCustomValidity("Enter a date for this presentation.");
            dateInput.reportValidity();
            dateInput.focus();
            return false;
        }
        if (startInput && endInput && startInput.value && endInput.value
            && (dateInput.value < startInput.value || dateInput.value > endInput.value)
        ) {
            dateInput.setCustomValidity("Presentation date must be between the engagement start and end dates.");
            dateInput.reportValidity();
            dateInput.focus();
            return false;
        }
        if (!timeInput || timeInput.value === "") {
            timeInput.setCustomValidity("Enter a time for this presentation.");
            timeInput.reportValidity();
            timeInput.focus();
            return false;
        }
        if (!validTime(timeInput.value)) {
            timeInput.setCustomValidity("Use a valid presentation time, such as 9:30 AM.");
            timeInput.reportValidity();
            timeInput.focus();
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
    }

    function presentationMarkup(id) {
        return [
            '<div class="presentation-fields">',
            '  <div class="form-field topic">',
            '    <label for="presentation_topic_' + id + '">Topic/Title<span class="required">*</span></label>',
            '    <input type="text" name="presentations[' + id + '][topic_title]" id="presentation_topic_' + id + '" maxlength="255">',
            '  </div>',
            '  <div class="datetime-row">',
            '    <div class="form-field">',
            '      <label for="presentation_date_' + id + '">Date<span class="required">*</span></label>',
            '      <input type="date" name="presentations[' + id + '][presentation_date]" id="presentation_date_' + id + '">',
            '    </div>',
            '    <div class="form-field">',
            '      <label for="presentation_time_' + id + '">Time<span class="required">*</span></label>',
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
            '      <label for="speaker_name_' + id + '">Speaker Name</label>',
            '      <input type="text" name="presentations[' + id + '][speaker_name]" id="speaker_name_' + id + '" maxlength="255">',
            '    </div>',
            '    <div class="form-field attendance">',
            '      <label for="expected_attendance_' + id + '">Expected Attendance</label>',
            '      <input type="number" name="presentations[' + id + '][expected_attendance]" id="expected_attendance_' + id + '" min="1" step="1">',
            '    </div>',
            '  </div>',
            '  <div class="remove-btn-container">',
            '    <button type="button" data-remove-presentation="' + id + '" class="remove-presentation-btn">Remove</button>',
            '  </div>',
            '</div>'
        ].join("");
    }

    window.addPresentation = function () {
        var entries = presentationEntries();
        var startInput = document.getElementById("event_start_date");
        var endInput = document.getElementById("event_end_date");
        for (var existingEntry of entries) {
            if (!validatePresentationEntry(existingEntry, startInput, endInput, true)) {
                return;
            }
        }

        var id = nextPresentationId++;
        var entry = document.createElement("div");
        entry.className = "presentation-entry";
        entry.id = "presentation-" + id;
        entry.innerHTML = presentationMarkup(id);
        presentationContainer().appendChild(entry);
        document.getElementById("speaker_name_" + id).value = defaultSpeaker();
        wirePresentationEntry(entry);
        applyPresentationDateConstraints();
        updateConfirmedAvailability();
        document.getElementById("presentation_topic_" + id).focus();
    };

    window.removePresentation = function (id) {
        var entry = document.getElementById("presentation-" + id);
        if (entry) {
            entry.remove();
        }
        updateConfirmedAvailability();
    };

    window.validateEngagementPresentations = function () {
        updateAllPresentationTimes();
        var startInput = document.getElementById("event_start_date");
        var endInput = document.getElementById("event_end_date");
        var status = document.getElementById("confirmation_status");

        for (var entry of presentationEntries()) {
            if (!validatePresentationEntry(entry, startInput, endInput, false)) {
                return false;
            }
        }

        if (status && status.value === "confirmed" && !hasCompletePresentation()) {
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
        applyPresentationDateConstraints();
        updateConfirmedAvailability();
    });
})();
