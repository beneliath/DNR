export function initializeSelectAll(selectAllId, inputName) {
    const selectAll = document.getElementById(selectAllId);
    const checkboxes = Array.from(document.querySelectorAll('input[name="' + inputName + '"]'));
    if (!selectAll || checkboxes.length === 0) return;
    const clear = document.getElementById(selectAllId + '-clear');
    const synchronize = function () {
        selectAll.disabled = checkboxes.every(function (checkbox) { return checkbox.checked || checkbox.disabled; });
        if (clear) clear.hidden = !checkboxes.some(function (checkbox) { return checkbox.checked && !checkbox.disabled; });
    };
    selectAll.addEventListener('click', function () {
        checkboxes.forEach(function (checkbox) { if (!checkbox.disabled) checkbox.checked = true; });
        synchronize();
    });
    clear?.addEventListener('click', function () {
        checkboxes.forEach(function (checkbox) { if (!checkbox.disabled) checkbox.checked = false; });
        synchronize();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', synchronize); });
    synchronize();
}
