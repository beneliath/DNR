let activeDrag;

export function createReorderHandle(label) {
    const handle = document.createElement('button');
    handle.type = 'button'; handle.className = 'mm-reorder-handle';
    handle.setAttribute('aria-label', label);
    handle.setAttribute('aria-description', 'Drag to reorder, or use the Up and Down arrow keys.');
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
    for (const x of [9,15]) for (const y of [5,12,19]) {
        const dot = document.createElementNS(svg.namespaceURI, 'circle');
        dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.setAttribute('r', '2'); svg.append(dot);
    }
    handle.append(svg);
    handle.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); });
    handle.addEventListener('dragstart', event => { event.preventDefault(); event.stopPropagation(); });
    return handle;
}

function scrollParent(item) {
    for (let node = item.parentElement; node; node = node.parentElement) {
        if (/(auto|scroll)/.test(getComputedStyle(node).overflowY) && node.scrollHeight > node.clientHeight) return node;
    }
    return document.scrollingElement;
}

function announce(handle, position, count) {
    let live = document.querySelector('[data-reorder-announcement]');
    if (!live) {
        live = document.createElement('p'); live.className = 'sr-only'; live.dataset.reorderAnnouncement = '';
        live.setAttribute('role', 'status'); document.body.append(live);
    }
    live.textContent = `${handle.getAttribute('aria-label')}, position ${position + 1} of ${count}`;
}

/** Move the DOM during dragging; commit the model once so Undo remains one step. */
export function wireReorderHandle({handle, item, list, getItems = () => [...list.children], onMove}) {
    handle.addEventListener('keydown', event => {
        if (event.altKey || event.ctrlKey || event.metaKey || !['ArrowUp','ArrowDown','Home','End'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        const items = getItems(), from = items.indexOf(item);
        const to = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : from + (event.key === 'ArrowUp' ? -1 : 1);
        if (from < 0 || to < 0 || to >= items.length || from === to) return;
        onMove(from, to); announce(handle, to, items.length);
        if (handle.isConnected) handle.focus();
    });
    handle.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.isPrimary === false) return;
        event.preventDefault(); event.stopPropagation(); activeDrag?.(true); handle.focus();
        const from = getItems().indexOf(item); if (from < 0) return;
        const anchor = document.createComment('Reorder origin'); item.before(anchor);
        const scroller = scrollParent(item), dialog = item.closest('dialog'), pointerId = event.pointerId;
        let y = event.clientY, frame; item.classList.add('mm-reorder-dragging');
        const reorder = () => {
            const next = getItems().filter(node => node !== item).find(node => {
                const rect = node.getBoundingClientRect(); return y < rect.top + rect.height / 2;
            });
            if (next && item.nextElementSibling !== next) next.before(item);
            else if (!next) {
                const last = getItems().filter(node => node !== item).at(-1);
                if (last && last.nextElementSibling !== item) last.after(item);
            }
        };
        const finish = cancelled => {
            cancelAnimationFrame(frame); activeDrag = null;
            document.removeEventListener('pointermove', move, true); document.removeEventListener('pointerup', up, true);
            document.removeEventListener('pointercancel', cancel, true); document.removeEventListener('keydown', escape, true);
            window.removeEventListener('blur', cancel); dialog?.removeEventListener('close', cancel);
            item.classList.remove('mm-reorder-dragging');
            const items = getItems(), to = items.indexOf(item);
            if (cancelled) anchor.replaceWith(item); else anchor.remove();
            if (!cancelled && to >= 0 && to !== from) { onMove(from, to); announce(handle, to, items.length); }
            if (handle.isConnected) handle.focus({preventScroll:true});
        };
        const move = e => { if (e.pointerId === pointerId) { e.preventDefault(); y = e.clientY; reorder(); } };
        const up = e => { if (e.pointerId === pointerId) finish(false); };
        const cancel = e => { if (e?.pointerId == null || e.pointerId === pointerId) finish(true); };
        const escape = e => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(true); } };
        const scroll = () => {
            if (!item.isConnected || !item.getClientRects().length) { finish(true); return; }
            const bounds = scroller === document.scrollingElement ? {top:0,bottom:innerHeight} : scroller.getBoundingClientRect();
            const before = scroller.scrollTop;
            if (y < bounds.top + 35) scroller.scrollTop -= 8;
            else if (y > bounds.bottom - 35) scroller.scrollTop += 8;
            if (before !== scroller.scrollTop) reorder(); frame = requestAnimationFrame(scroll);
        };
        document.addEventListener('pointermove', move, {capture:true,passive:false}); document.addEventListener('pointerup', up, true);
        document.addEventListener('pointercancel', cancel, true); document.addEventListener('keydown', escape, true);
        window.addEventListener('blur', cancel); dialog?.addEventListener('close', cancel);
        activeDrag = finish; frame = requestAnimationFrame(scroll);
    });
}
