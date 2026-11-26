// bulk: row selection on the Loans list (read-only actions: CSV export, print item tags).
// Selected loan ids live in a Set tied to the action bar element: a results-frame refresh (live search) keeps the Set and re-ticks the
// new rows; a full page navigation brings a new bar element, so the selection starts empty again.
const MAX = 100;
let state = { bar: null, ids: new Set(), anchor: null };

const bar = () => document.querySelector('[data-bulk-bar]');
const rows = () => [...document.querySelectorAll('[data-bulk-id]')];
const alls = () => [...document.querySelectorAll('[data-bulk-all]')];
let noteTimer = 0;

function note(text) {
    const el = state.bar?.querySelector('[data-bulk-note]');
    if (!el) return;
    el.textContent = text;
    clearTimeout(noteTimer);
    if (text) noteTimer = setTimeout(() => { el.textContent = ''; }, 4000);
}

function exportUrl(b) {
    // Only the ticked ids: the filters on screen are left out on purpose, so what is ticked (even if live search hid the row) is what downloads.
    const u = new URL(b.dataset.exportUrl, location.href);
    u.searchParams.set('export', 'csv');
    for (const id of state.ids) u.searchParams.append('ids[]', id);
    return u.toString();
}

function tagsUrl(b) {
    const u = new URL(b.dataset.tagsUrl, location.href);
    for (const id of state.ids) u.searchParams.append('ids[]', id);
    return u.toString();
}

function render() {
    const b = state.bar;
    if (!b) return;
    const n = state.ids.size;
    const checks = rows();
    for (const c of checks) {
        const on = state.ids.has(c.dataset.bulkId);
        c.checked = on;
        c.closest('tr')?.toggleAttribute('data-bulk-selected', on);
    }
    const onPage = checks.filter(c => c.checked).length;
    for (const a of alls()) {
        a.checked = checks.length > 0 && onPage === checks.length;
        a.indeterminate = onPage > 0 && onPage < checks.length;
        a.disabled = checks.length === 0;
    }
    b.querySelector('[data-bulk-count]').textContent = `${n} selected`;
    b.querySelector('[data-bulk-export]')?.setAttribute('href', n ? exportUrl(b) : b.dataset.exportUrl);
    b.querySelector('[data-bulk-tags]')?.setAttribute('href', n ? tagsUrl(b) : b.dataset.tagsUrl);
    if (n && b.hidden) requestAnimationFrame(() => { if (state.ids.size) { b.hidden = false; dock(); } }); // text first, then show: screen readers announce the count
    else if (!n) b.hidden = true;
    document.documentElement.classList.toggle('dh-bulk-on', n > 0);
    dock();
}

// Phones: the bar is fixed to the bottom, so the list gets bottom padding of the bar's height.
function dock() {
    const b = state.bar;
    const h = b && !b.hidden ? Math.ceil(b.getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty('--dh-bulk-h', `${h}px`);
}

function clear() {
    state.ids.clear();
    state.anchor = null;
    note('');
    render();
}

function add(id) {
    if (state.ids.has(id)) return true;
    if (state.ids.size >= MAX) { note(`You can select up to ${MAX} loans at once`); return false; }
    state.ids.add(id);
    return true;
}

function mount() {
    const b = bar();
    if (!b) { state = { bar: null, ids: new Set(), anchor: null }; document.documentElement.classList.remove('dh-bulk-on'); return; }
    if (state.bar !== b) state = { bar: b, ids: new Set(), anchor: null };
    render();
}

document.addEventListener('click', e => {
    const t = e.target;
    if (!(t instanceof Element)) return;
    const one = t.closest('input[data-bulk-id]');
    if (one) {
        const checks = rows();
        const i = checks.indexOf(one);
        const wanted = one.checked;
        // Shift+click: everything between the last clicked box and this one gets the same state.
        const span = e.shiftKey && state.anchor !== null && state.anchor < checks.length ? checks.slice(Math.min(state.anchor, i), Math.max(state.anchor, i) + 1) : [one];
        let full = false;
        for (const c of span) {
            if (wanted) { if (!add(c.dataset.bulkId)) full = true; } else state.ids.delete(c.dataset.bulkId);
        }
        state.anchor = i;
        if (!full) note('');
        render();
        return;
    }
    const all = t.closest('input[data-bulk-all]');
    if (all) {
        const checks = rows();
        let full = false;
        for (const c of checks) {
            if (all.checked) { if (!add(c.dataset.bulkId)) full = true; } else state.ids.delete(c.dataset.bulkId);
        }
        if (!full) note('');
        render();
        return;
    }
    if (t.closest('[data-bulk-clear]')) { clear(); return; }
    const act = t.closest('[data-bulk-export], [data-bulk-tags]');
    if (act && state.bar?.contains(act)) {
        if (!state.ids.size) { e.preventDefault(); return; }
        act.setAttribute('href', act.matches('[data-bulk-export]') ? exportUrl(state.bar) : tagsUrl(state.bar)); // fresh, even before the click's own navigation
        setTimeout(clear, 300); // after an action the selection is done with
    }
});

document.addEventListener('turbo:load', mount);
document.addEventListener('turbo:frame-render', mount); // live search replaced the rows: tick the ones still selected
document.addEventListener('turbo:before-cache', () => { // never keep a ticked page in Turbo's snapshot cache
    state = { bar: null, ids: new Set(), anchor: null };
    for (const c of [...rows(), ...alls()]) { c.checked = false; c.indeterminate = false; }
    for (const tr of document.querySelectorAll('[data-bulk-selected]')) tr.removeAttribute('data-bulk-selected');
    const b = bar();
    if (b) b.hidden = true;
    document.documentElement.classList.remove('dh-bulk-on');
});
window.addEventListener('resize', dock);
document.addEventListener('keydown', e => { if (e.key === 'Escape' && state.ids.size && state.bar?.contains(document.activeElement)) clear(); });
