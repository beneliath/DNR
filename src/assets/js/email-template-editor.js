"use strict";

(function () {
    const form = document.querySelector("[data-email-template-editor]");
    if (!form) return;
    const subject = form.querySelector("[data-template-subject]");
    const body = form.querySelector("[data-template-body]");
    const status = form.querySelector("[data-email-field-status]");
    let target = body;
    [subject, body].forEach((field) => field?.addEventListener("focus", () => { target = field; }));
    form.querySelectorAll("[data-insert-email-field]").forEach((button) => {
        button.addEventListener("click", () => {
            if (!target || target.readOnly || button.disabled) return;
            const key = button.dataset.insertEmailField;
            if (target === subject && key === "presentation_schedule") {
                if (status) status.textContent = "The presentation schedule can be inserted into the message only.";
                return;
            }
            const token = `{{${key}}}`;
            const start = target.selectionStart ?? target.value.length;
            const end = target.selectionEnd ?? start;
            if (target.maxLength >= 0 && target.value.length - (end - start) + token.length > target.maxLength) {
                if (status) status.textContent = "There is not enough space to insert this field.";
                return;
            }
            target.setRangeText(token, start, end, "end");
            target.dispatchEvent(new Event("input", { bubbles: true }));
            target.focus();
            if (status) status.textContent = "Event field inserted.";
        });
    });
})();
