"use strict";

(function () {
    const form = document.querySelector("[data-engagement-email-form]");
    if (!form) {
        return;
    }

    const dataNode = document.getElementById("engagement-email-template-data");
    let templates = {};
    try {
        templates = JSON.parse(dataNode?.textContent || "{}");
    } catch (_error) {
        templates = {};
    }

    const templateSelect = form.querySelector("[data-email-template]");
    const subject = form.querySelector("[data-email-subject]");
    const body = form.querySelector("[data-email-body]");
    const recipients = Array.from(form.querySelectorAll("[data-email-recipient]"));
    const count = form.querySelector("[data-recipient-count]");

    function updateCount() {
        const selected = recipients.filter((recipient) => recipient.checked).length;
        if (count) {
            count.textContent = selected === 1
                ? "1 contact selected."
                : `${selected} contacts selected.`;
        }
    }

    function selectSuggestedRoles(roles) {
        if (!Array.isArray(roles) || roles.length === 0) {
            return;
        }
        recipients.forEach((recipient) => {
            const contactRoles = (recipient.dataset.contactRoles || "").split(/\s+/).filter(Boolean);
            recipient.checked = roles.some((role) => contactRoles.includes(role));
        });
        updateCount();
    }

    templateSelect?.addEventListener("change", function () {
        const template = templates[templateSelect.value];
        if (!template) {
            return;
        }
        if (subject) {
            subject.value = template.subject || "";
        }
        if (body) {
            body.value = template.body || "";
        }
        selectSuggestedRoles(template.suggested_roles || []);
    });

    form.querySelectorAll("[data-select-recipient-role]").forEach((button) => {
        button.addEventListener("click", function () {
            const role = button.dataset.selectRecipientRole || "";
            recipients.forEach((recipient) => {
                const roles = (recipient.dataset.contactRoles || "").split(/\s+/).filter(Boolean);
                if (roles.includes(role)) {
                    recipient.checked = true;
                }
            });
            updateCount();
        });
    });

    form.querySelector("[data-select-all-recipients]")?.addEventListener("click", function () {
        recipients.forEach((recipient) => {
            if (!recipient.disabled) {
                recipient.checked = true;
            }
        });
        updateCount();
    });

    form.querySelector("[data-clear-recipients]")?.addEventListener("click", function () {
        recipients.forEach((recipient) => {
            recipient.checked = false;
        });
        updateCount();
    });

    recipients.forEach((recipient) => recipient.addEventListener("change", updateCount));
    updateCount();
})();
