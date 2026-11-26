// skeleton: placeholders for slow loads, and a busy state for every Turbo submit button.
//  - A Turbo visit (or a GET form) slower than 200 ms puts a skeleton over .dh-content (aria-busy="true");
//    a live-search refresh of turbo-frame#dh-results slower than 150 ms shows 5 skeleton rows. Fast loads show nothing.
//  - Which skeleton: data-skeleton="table|cards|form|detail" on the link or form that started it, else a guess from the
//    URL, else 'table' when the current page has a [data-list-scroll] table, else 'detail'.
//  - Submit buttons without data-turbo-submits-with get "Working…" while the request runs.
// One set of document listeners (guarded); nothing is kept per page except the covers, which are dropped on render.

const VARIANTS = ['table', 'cards', 'form', 'detail'];
const PAGE_DELAY = 200;
const FRAME_DELAY = 150;
const FAILSAFE = 20000; // a cover never outlives this: recovery.js tells the user what went wrong
const TAG = '[ux:skeleton]';

const covers = new Map(); // host element -> { overlay, inerted, timer }
let pageTimer = null;
let pageFailsafe = null;
let linkHint = null; // { variant, at } from the last Turbo link click

// ---- markup (mirrors components/dhiran/skeleton.blade.php) ----
function el(tag, cls, attrs = {}) {
    const node = document.createElement(tag);
    node.className = cls;
    for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
    return node;
}
const line = (w, extra = '') => el('span', `ux-skel ux-skel-line ${extra} ${w}`.replace(/\s+/g, ' ').trim());
const WIDTHS = ['ux-w-35', 'ux-w-80', 'ux-w-50', 'ux-w-65'];

export function buildSkeleton(variant, rows) {
    variant = VARIANTS.includes(variant) ? variant : 'table';
    rows = Math.max(1, Math.min(30, rows | 0));
    const wrap = el('div', 'ux-skel-wrap', { 'data-skeleton-variant': variant });
    const status = el('span', 'ux-sr', { role: 'status' });
    status.textContent = 'Loading';
    const body = el('div', variant === 'table' ? 'ux-skel-card ux-skel-table' : `ux-skel-${variant}`, { 'aria-hidden': 'true' });
    if (variant === 'table') {
        const head = el('div', 'ux-skel-head');
        WIDTHS.forEach(() => head.append(line('ux-w-50')));
        body.append(head);
    } else if (variant === 'detail') {
        const title = el('div', 'ux-skel-title');
        title.append(line('ux-w-35', 'ux-tall'), line('ux-w-50'));
        body.append(title);
    }
    for (let i = 0; i < rows; i++) {
        const row = el('div', variant === 'table' || variant === 'form' ? 'ux-skel-row' : 'ux-skel-row ux-skel-card', { 'data-skel-row': '' });
        if (variant === 'table') WIDTHS.forEach((w) => row.append(line(w)));
        else if (variant === 'form') row.append(line('ux-w-25'), el('span', 'ux-skel ux-skel-box ux-w-100'));
        else if (variant === 'cards') row.append(line('ux-w-50'), line('ux-w-80'), line('ux-w-35'));
        else row.append(line('ux-w-25'), line('ux-w-80'));
        body.append(row);
    }
    wrap.append(status, body);
    return wrap;
}

// ---- cover / uncover a host element ----
function cover(host, variant, rows) {
    if (!host || !host.isConnected || covers.has(host)) return;
    const inerted = [];
    for (const child of host.children) {
        if (!child.inert) { child.inert = true; inerted.push(child); }
    }
    const overlay = el('div', 'ux-skel-overlay');
    overlay.append(buildSkeleton(variant, rows));
    host.append(overlay);
    if (host.matches('turbo-frame')) host.setAttribute('data-ux-skel', '');
    else host.setAttribute('aria-busy', 'true');
    covers.set(host, { overlay, inerted });
}

function uncover(host) {
    const c = covers.get(host);
    if (!c) return;
    covers.delete(host);
    c.overlay.remove();
    c.inerted.forEach((n) => { n.inert = false; });
    host.removeAttribute(host.matches('turbo-frame') ? 'data-ux-skel' : 'aria-busy');
}

function uncoverAll() {
    window.clearTimeout(pageTimer);
    window.clearTimeout(pageFailsafe);
    pageTimer = pageFailsafe = null;
    frameTimers.forEach((t) => window.clearTimeout(t));
    frameTimers.clear();
    [...covers.keys()].forEach(uncover);
}

// ---- which skeleton ----
function variantFor(url, hint) {
    if (hint && VARIANTS.includes(hint)) return hint;
    let path = '';
    try { path = new URL(url, location.href).pathname; } catch { /* keep '' */ }
    if (/\/(create|upload|settings)(\/|$)/.test(path)) return 'form';
    if (/\/(loans|borrowers|items|customers)\/[^/]+$/.test(path) && !/\/(loans|borrowers)\/(create|search)$/.test(path)) return 'detail';
    if (/\/(loans|borrowers|old-loans|reports\/[^/]+)$/.test(path)) return 'table';
    return document.querySelector('[data-list-scroll]') ? 'table' : 'detail';
}

// ---- page visits ----
function startPage(url, hint) {
    window.clearTimeout(pageTimer);
    window.clearTimeout(pageFailsafe);
    const variant = variantFor(url, hint);
    pageTimer = window.setTimeout(() => {
        pageTimer = null;
        cover(document.querySelector('.dh-content'), variant, variant === 'cards' ? 6 : 8);
        pageFailsafe = window.setTimeout(uncoverAll, FAILSAFE);
    }, PAGE_DELAY);
}

// ---- live-search frames ----
const frameTimers = new Map(); // frame -> timer
function startFrame(frame) {
    window.clearTimeout(frameTimers.get(frame));
    frameTimers.set(frame, window.setTimeout(() => {
        frameTimers.delete(frame);
        cover(frame, frame.dataset.skeleton || 'table', 5);
        window.setTimeout(() => uncover(frame), FAILSAFE);
    }, FRAME_DELAY));
}
function stopFrame(frame) {
    if (!frame) return;
    window.clearTimeout(frameTimers.get(frame));
    frameTimers.delete(frame);
    uncover(frame);
}

const isPrefetch = (req) => req?.headers?.['X-Sec-Purpose'] === 'prefetch';
const frameEligible = (f) => f?.matches?.('turbo-frame') && (f.id === 'dh-results' || f.hasAttribute('data-skeleton'));

function onBeforeFetch(e) {
    const opts = e.detail?.fetchOptions;
    if (!opts || isPrefetch({ headers: opts.headers })) return;
    const t = e.target;
    const method = String(opts.method || 'GET').toUpperCase();
    if (t.matches?.('turbo-frame')) {
        if (frameEligible(t)) startFrame(t);
    } else if (t instanceof HTMLFormElement) {
        // A POST keeps the form on screen: its button shows the busy state. A GET form (filters) is a visit.
        const host = t.closest('turbo-frame');
        const fid = t.getAttribute('data-turbo-frame') || (host ? host.getAttribute('target') || host.id : '');
        if (method === 'GET' && (!fid || fid === '_top')) startPage(e.detail.url?.href ?? t.action, t.dataset.skeleton);
    } else if (method === 'GET') {
        const fresh = linkHint && Date.now() - linkHint.at < 1500 ? linkHint.variant : undefined;
        linkHint = null;
        startPage(e.detail.url?.href ?? '', fresh);
    }
}

// ---- busy submit buttons ----
const busy = new WeakMap(); // submitter -> { html, value, minWidth }
function setBusy(submitter) {
    if (!submitter || busy.has(submitter) || submitter.hasAttribute('data-turbo-submits-with')) return;
    const isInput = submitter.tagName === 'INPUT';
    const text = (isInput ? submitter.value : submitter.textContent).trim();
    busy.set(submitter, { nodes: [...submitter.childNodes], value: submitter.value, minWidth: submitter.style.minWidth });
    submitter.style.minWidth = `${submitter.offsetWidth}px`; // the button keeps its width: no layout shift
    submitter.setAttribute('aria-busy', 'true');
    submitter.setAttribute('data-ux-busy', '');
    submitter.disabled = true;
    if (text) { if (isInput) submitter.value = 'Working…'; else submitter.textContent = 'Working…'; }
}
function clearBusy(submitter) {
    const s = submitter && busy.get(submitter);
    if (!s) return;
    busy.delete(submitter);
    if (submitter.tagName === 'INPUT') submitter.value = s.value; else submitter.replaceChildren(...s.nodes);
    submitter.style.minWidth = s.minWidth;
    submitter.removeAttribute('aria-busy');
    submitter.removeAttribute('data-ux-busy');
    submitter.disabled = false;
}

if (!window.__uxSkeleton) {
    window.__uxSkeleton = true;
    const d = document;

    d.addEventListener('turbo:click', (e) => {
        const v = e.target.closest?.('[data-skeleton]')?.dataset.skeleton;
        linkHint = v ? { variant: v, at: Date.now() } : null;
    });
    d.addEventListener('turbo:before-fetch-request', onBeforeFetch);
    // The answer is here: the cover is dropped when the new page or frame content is rendered (or at once on error).
    d.addEventListener('turbo:before-fetch-response', (e) => {
        window.clearTimeout(pageTimer);
        pageTimer = null;
        if (e.target.matches?.('turbo-frame')) window.clearTimeout(frameTimers.get(e.target));
    });
    ['turbo:load', 'turbo:render', 'turbo:before-cache', 'turbo:fetch-request-error', 'turbo:frame-missing'].forEach((n) => d.addEventListener(n, (e) => {
        if (n === 'turbo:frame-missing' || (n === 'turbo:fetch-request-error' && e.target.matches?.('turbo-frame'))) stopFrame(e.target);
        else uncoverAll();
    }));
    ['turbo:frame-render', 'turbo:frame-load'].forEach((n) => d.addEventListener(n, (e) => stopFrame(e.target)));
    window.addEventListener('pageshow', (e) => { if (e.persisted) uncoverAll(); });

    d.addEventListener('turbo:submit-start', (e) => {
        try { setBusy(e.detail?.formSubmission?.submitter); } catch (err) { console.warn(TAG, 'busy state failed', err); }
    });
    d.addEventListener('turbo:submit-end', (e) => {
        try { clearBusy(e.detail?.formSubmission?.submitter); } catch (err) { console.warn(TAG, 'busy reset failed', err); }
    });
}
