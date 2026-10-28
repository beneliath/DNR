export function initializeCollectionFieldOrder(list, canManage, changed) {
    if (!list || !canManage) return;
    const pane = list.closest('form'), view = list.ownerDocument.defaultView;
    const status = list.ownerDocument.getElementById('collection-field-order-status');
    const rows = () => Array.from(list.children).filter(row => row.matches('[data-field]'));
    let drag = null, scrolling = null;
    const announce = row => {
        const fields = rows(), label = row.querySelector('[data-field-label]').textContent.trim();
        status.textContent = `${label} moved to position ${fields.indexOf(row) + 1} of ${fields.length}.`;
    };
    const place = y => {
        const before = rows().find(row => row !== drag.row && y < row.getBoundingClientRect().top + row.getBoundingClientRect().height / 2);
        if (before) { if (drag.row.nextElementSibling !== before) before.before(drag.row); }
        else if (list.lastElementChild !== drag.row) list.append(drag.row);
        drag.handle.focus({preventScroll:true});
    };
    const scroll = () => {
        if (!drag?.active) return;
        const box = pane.getBoundingClientRect(), previous = pane.scrollTop;
        if (drag.y < box.top + 36) pane.scrollTop -= 12;
        else if (drag.y > box.bottom - 36) pane.scrollTop += 12;
        if (pane.scrollTop !== previous) place(drag.y);
        scrolling = view.requestAnimationFrame(scroll);
    };
    const finish = cancelled => {
        if (!drag) return;
        const current = drag; drag = null;
        if (scrolling !== null) view.cancelAnimationFrame(scrolling);
        scrolling = null; current.row.classList.remove('collection-field-dragging');
        list.classList.remove('collection-fields-dragging');
        if (cancelled) current.order.forEach(row => list.append(row));
        else if (rows().some((row, index) => row !== current.order[index])) { changed(); announce(current.row); }
        if (list.hasPointerCapture(current.id)) list.releasePointerCapture(current.id);
        current.handle.focus({preventScroll:true});
    };
    list.addEventListener('pointerdown', event => {
        const handle = event.target.closest('[data-field-drag]');
        if (!handle || handle.disabled || event.button !== 0 || !event.isPrimary || drag) return;
        event.preventDefault(); handle.focus({preventScroll:true});
        drag = {id:event.pointerId, row:handle.closest('[data-field]'), handle, startY:event.clientY, y:event.clientY, order:rows(), active:false};
        list.setPointerCapture(event.pointerId);
    });
    list.addEventListener('pointermove', event => {
        if (!drag || event.pointerId !== drag.id) return;
        drag.y = event.clientY;
        if (!drag.active && Math.abs(drag.y - drag.startY) < 4) return;
        event.preventDefault();
        if (!drag.active) {
            drag.active = true; drag.row.classList.add('collection-field-dragging');
            list.classList.add('collection-fields-dragging'); scrolling = view.requestAnimationFrame(scroll);
        }
        place(drag.y);
    });
    list.addEventListener('pointerup', event => { if (drag?.id === event.pointerId) finish(false); });
    list.addEventListener('pointercancel', event => { if (drag?.id === event.pointerId) finish(true); });
    list.addEventListener('lostpointercapture', event => { if (drag?.id === event.pointerId) finish(true); });
    list.addEventListener('keydown', event => {
        if (drag && event.key === 'Escape') { event.preventDefault(); finish(true); return; }
        const handle = event.target.closest('[data-field-drag]');
        if (!handle || handle.disabled || drag) return;
        const row = handle.closest('[data-field]'), fields = rows(), index = fields.indexOf(row);
        const destination = {ArrowUp:index - 1, ArrowDown:index + 1, Home:0, End:fields.length - 1}[event.key];
        if (destination === undefined) return;
        event.preventDefault();
        const target = fields[destination]; if (!target || target === row) return;
        if (destination < index) target.before(row); else target.after(row);
        changed(); announce(row); handle.focus({preventScroll:true});
        const box = row.getBoundingClientRect(), bounds = pane.getBoundingClientRect();
        if (box.top < bounds.top) pane.scrollTop += box.top - bounds.top;
        else if (box.bottom > bounds.bottom) pane.scrollTop += box.bottom - bounds.bottom;
    });
}
