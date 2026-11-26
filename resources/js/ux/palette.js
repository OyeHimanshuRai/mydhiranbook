// Command palette: Ctrl/Cmd K opens a native <dialog> (focus trap and Esc come with it). The "Go to" pages are filtered here;
// from two typed characters on, loans and customers are fetched from the shop-scoped endpoint (debounced, previous request cancelled).
// Everything shown from the server is written with textContent, never as HTML. Turbo replaces the layout (and the dialog) on every
// visit, so the elements are looked up each time and the document listeners are added on turbo:load and removed on turbo:before-cache.
const MIN_Q = 2;
const MAX_Q = 60;
const DEBOUNCE_MS = 200;
const MAX_LOCAL = 8;
const TYPES = { loan: 'Loans', customer: 'Customers' };

const S = { items: [], remote: [], q: '', rows: [], active: -1, pending: false, failed: false, timer: 0, ctl: null };
let ac = null;

const dlg = () => document.getElementById('dhPalette');
const input = () => document.getElementById('dhPaletteInput');
const list = () => document.getElementById('dhPaletteList');
const status = () => document.getElementById('dhPaletteStatus');
const visit = (url) => (window.Turbo ? window.Turbo.visit(url) : window.location.assign(url));
const safeUrl = (u) => {
    if (typeof u !== 'string' || !u.startsWith('/') || u.startsWith('//')) return null;
    try { return new URL(u, location.href).origin === location.origin ? u : null; } catch { return null; }
};

/** Every word typed must be found in label + keywords: as a plain substring (word starts rank first), else as letters in order. Lower score is better, -1 is no match. */
function score(words, text) {
    let total = 0;
    for (const w of words) {
        const at = text.indexOf(w);
        if (at >= 0) { total += at === 0 || text[at - 1] === ' ' ? 0 : 1; continue; }
        if (w.length < 2) return -1;
        let j = 0;
        for (let i = 0; i < text.length && j < w.length; i++) if (text[i] === w[j]) j++;
        if (j < w.length) return -1;
        total += 5;
    }
    return total;
}

function localMatches() {
    const words = S.q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return S.items;
    return S.items
        .map((it, i) => [score(words, it.t), i, it])
        .filter(([s]) => s >= 0)
        .sort((a, b) => a[0] - b[0] || a[1] - b[1])
        .slice(0, MAX_LOCAL)
        .map(([, , it]) => it);
}

function option(it, n) {
    const a = document.createElement('a');
    a.className = 'pal-o';
    a.id = 'dhPaletteO' + n;
    a.href = it.url;
    a.tabIndex = -1;
    a.setAttribute('role', 'option');
    a.setAttribute('aria-selected', 'false');
    const l = document.createElement('span');
    l.className = 'pal-o-label';
    l.textContent = it.label;
    a.append(l);
    if (it.sub) {
        const s = document.createElement('span');
        s.className = 'pal-o-sub';
        s.textContent = it.sub;
        a.append(s);
    }
    return a;
}

function render() {
    const box = list();
    if (!box) return;
    const prevUrl = S.rows[S.active]?.getAttribute('href');
    const groups = [['Go to', localMatches()]];
    for (const type of Object.keys(TYPES)) groups.push([TYPES[type], S.remote.filter((r) => r.type === type)]);
    const frag = document.createDocumentFragment();
    S.rows = [];
    groups.forEach(([title, rows], g) => {
        if (!rows.length) return;
        const wrap = document.createElement('div');
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-labelledby', 'dhPaletteG' + g);
        const h = document.createElement('div');
        h.className = 'pal-gh';
        h.id = 'dhPaletteG' + g;
        h.textContent = title;
        wrap.append(h);
        for (const it of rows) {
            const o = option(it, S.rows.length);
            S.rows.push(o);
            wrap.append(o);
        }
        frag.append(wrap);
    });
    box.replaceChildren(frag);

    const st = status();
    const empty = !S.rows.length && !S.pending;
    st.classList.toggle('is-empty', empty || (S.failed && !S.rows.length));
    if (S.pending) st.textContent = 'Searching…';
    else if (S.failed) st.textContent = S.rows.length ? 'Loans and customers could not be searched. Pages still work.' : 'Search is not available right now. Try again.';
    else if (empty) st.textContent = 'No results' + (S.q ? ' for “' + S.q + '”' : '');
    else st.textContent = S.q ? S.rows.length + (S.rows.length === 1 ? ' result' : ' results') : '';

    const keep = prevUrl ? S.rows.findIndex((r) => r.getAttribute('href') === prevUrl) : -1;
    setActive(S.rows.length ? Math.max(keep, 0) : -1, false);
}

function setActive(i, scroll = true) {
    S.rows[S.active]?.classList.remove('is-active');
    S.rows[S.active]?.setAttribute('aria-selected', 'false');
    S.active = i;
    const el = S.rows[i];
    if (el) {
        el.classList.add('is-active');
        el.setAttribute('aria-selected', 'true');
        input().setAttribute('aria-activedescendant', el.id);
        if (scroll) el.scrollIntoView({ block: 'nearest' });
    } else {
        input()?.setAttribute('aria-activedescendant', '');
    }
}

function cancelSearch() {
    clearTimeout(S.timer);
    S.ctl?.abort();
    S.ctl = null;
}

function clean(rows) {
    if (!Array.isArray(rows)) return [];
    const count = { loan: 0, customer: 0 };
    const out = [];
    for (const r of rows) {
        if (!r || typeof r !== 'object' || !(r.type in TYPES) || typeof r.label !== 'string') continue;
        const url = safeUrl(r.url);
        if (!url || count[r.type] >= 6) continue;
        count[r.type]++;
        out.push({ type: r.type, label: r.label, sub: typeof r.sub === 'string' ? r.sub : '', url });
    }
    return out;
}

function search() {
    cancelSearch();
    S.remote = [];
    S.failed = false;
    S.pending = S.q.length >= MIN_Q;
    render();
    if (!S.pending) return;
    S.timer = setTimeout(() => {
        const q = S.q.slice(0, MAX_Q);
        const ctl = new AbortController();
        S.ctl = ctl;
        fetch(dlg().dataset.searchUrl + '?q=' + encodeURIComponent(q), {
            signal: ctl.signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http ' + r.status))))
            .then((rows) => { if (S.ctl !== ctl) return; S.remote = clean(rows); S.pending = false; render(); })
            .catch((e) => { if (e?.name === 'AbortError' || S.ctl !== ctl) return; S.pending = false; S.failed = true; render(); });
    }, DEBOUNCE_MS);
}

function open() {
    const d = dlg();
    if (!d || d.open || document.querySelector('dialog[open]')) return;
    try {
        S.items = JSON.parse(d.dataset.items || '[]')
            .filter((it) => it && typeof it.label === 'string' && safeUrl(it.url))
            .map((it) => ({ ...it, sub: '', t: (it.label + ' ' + (it.kw || '')).toLowerCase() }));
    } catch { S.items = []; }
    S.q = '';
    S.remote = [];
    S.pending = S.failed = false;
    input().value = '';
    d.showModal();
    render();
    input().focus();
}

function close() { dlg()?.close(); }

function go(row) {
    const url = row?.getAttribute('href');
    if (!url) return;
    close();
    visit(url);
}

function onKey(e) {
    if (e.isComposing) return;
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k') {
        const d = dlg();
        if (!d) return;
        e.preventDefault();
        if (d.open) { if (e.target.closest?.('#dhPalette')) close(); } else open();
        return;
    }
    if (!e.target.closest?.('#dhPalette')) return;
    const n = S.rows.length;
    switch (e.key) {
        case 'ArrowDown': if (n) { e.preventDefault(); setActive((S.active + 1) % n); } break;
        case 'ArrowUp': if (n) { e.preventDefault(); setActive((S.active - 1 + n) % n); } break;
        case 'Home': if (n) { e.preventDefault(); setActive(0); } break;
        case 'End': if (n) { e.preventDefault(); setActive(n - 1); } break;
        case 'Enter': if (S.rows[S.active]) { e.preventDefault(); go(S.rows[S.active]); } break;
        case 'Tab': { // stay in the dialog: the box and the close button
            e.preventDefault();
            const order = [input(), dlg().querySelector('[data-palette-close]')].filter(Boolean);
            const at = order.indexOf(document.activeElement);
            order[(at + (e.shiftKey ? -1 : 1) + order.length) % order.length].focus();
            break;
        }
        default:
    }
}

function onClick(e) {
    const t = e.target;
    if (t.closest?.('[data-palette-open]')) { open(); return; }
    if (t.closest?.('[data-palette-close]') || t === dlg()) { close(); return; }
    const o = t.closest?.('#dhPalette [role=option]');
    if (!o) return;
    if (e.button || e.ctrlKey || e.metaKey || e.shiftKey) { setTimeout(close, 0); return; } // new tab or window: keep the browser's own behaviour
    e.preventDefault();
    go(o);
}

function onInput(e) {
    if (e.target.id !== 'dhPaletteInput') return;
    S.q = e.target.value.trim();
    search();
}

function onOver(e) {
    const i = S.rows.indexOf(e.target.closest?.('#dhPalette [role=option]'));
    if (i >= 0 && i !== S.active) setActive(i, false);
}

function onClosed(e) { if (e.target.id === 'dhPalette') cancelSearch(); }

function destroy() {
    cancelSearch();
    const d = dlg();
    if (d?.open) d.close();
    ac?.abort();
    ac = null;
}

function init() {
    destroy();
    ac = new AbortController();
    const o = { signal: ac.signal };
    document.addEventListener('keydown', onKey, o);
    document.addEventListener('click', onClick, o);
    document.addEventListener('input', onInput, o);
    document.addEventListener('mouseover', onOver, o);
    document.addEventListener('close', onClosed, { ...o, capture: true }); // dialog "close" does not bubble
    document.addEventListener('dhiran:palette', open, o); // the "/" shortcut asks for the palette when the page has no search box
    if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
        document.querySelectorAll('[data-pal-mod]').forEach((k) => { k.textContent = k.textContent.replace('Ctrl', '⌘'); });
    }
}

document.addEventListener('turbo:load', init);
document.addEventListener('turbo:before-cache', destroy);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
