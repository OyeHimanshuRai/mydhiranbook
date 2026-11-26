// recovery: calm, plain-wording handling of lost connections and failed requests.
//  - Offline banner (role=status) on <html>, so a Turbo body swap never removes it; "Back online" for 3 s.
//  - Network failure of a visit, form or frame, a missing frame, a 5xx answer to a form: a toast with ONE "Try again"
//    (a user click, never automatic: a retry that fails again shows the toast without the button, so nothing loops).
//  - 419 (page expired) on a form: reload only when the form has no typed-in changes; otherwise keep the page.
//  - A 419 or 5xx answer to a form submit is kept from replacing the page (turbo:before-fetch-response), so what the
//    person typed stays on screen. Every failure is also console.warn'ed with the [ux:recovery] tag.
// Also window.mdbOptimistic, see below.

const TAG = '[ux:recovery]';
const MSG = {
    net: 'We could not reach the server. Nothing was lost on this page.',
    netAgain: 'We still could not reach the server. Nothing was lost on this page. Check your connection and try again in a moment.',
    server: 'Something went wrong on our side. Nothing was saved.',
    serverAgain: 'Something went wrong on our side again. Nothing was saved. Please try again in a moment.',
    expiredClean: 'This page expired. We refreshed it: press the button again.',
    expiredDirty: 'This page expired. Copy the text you typed first, then refresh the page and press the button again.',
};

/**
 * window.mdbOptimistic(el, { apply, rollback, request }) -> Promise<boolean>
 * Shows the result of a request at once (apply(el)), sends it (request() returns a promise, a fetch Response or anything),
 * and undoes it (rollback(el)) when the request throws or answers with ok === false. Resolves true when it held.
 * ONLY for harmless display state. NEVER use it for payments, loan changes or settings: money screens wait for the server.
 * Today's single use: dimming the bell badge when the notices card is marked as seen (components/dhiran/notice-card.blade.php).
 */
window.mdbOptimistic = async function mdbOptimistic(el, { apply, rollback, request } = {}) {
    try { apply?.(el); } catch (err) { console.warn(TAG, 'optimistic apply failed', err); }
    try {
        const res = await request?.();
        if (res && res.ok === false) throw new Error(`HTTP ${res.status}`);
        return true;
    } catch (err) {
        console.warn(TAG, 'optimistic update rolled back', err);
        try { rollback?.(el); } catch (e2) { console.warn(TAG, 'optimistic rollback failed', e2); }
        return false;
    }
};

// ---- the host: banner + toast, both on <html> ----
let host = null;
let banner = null;
let toast = null;
let toastText = null;
let live = null;
let toastTimer = null;
let bannerTimer = null;

function ensureHost() {
    if (host && host.isConnected) return;
    host = document.createElement('div');
    host.className = 'ux-rec';
    banner = document.createElement('div');
    banner.className = 'ux-offline';
    banner.setAttribute('role', 'status');
    banner.hidden = true;
    toast = document.createElement('div');
    toast.className = 'ux-toast';
    toast.hidden = true;
    // a toggled-hidden box is not reliably announced: a permanent visually hidden live region says the same text
    live = document.createElement('span');
    live.className = 'ux-sr';
    live.setAttribute('aria-live', 'polite');
    toastText = document.createElement('span');
    toastText.className = 'ux-toast-text';
    toast.append(toastText);
    host.append(banner, toast, live);
    document.documentElement.append(host);
}

function showBanner(state, text) {
    ensureHost();
    window.clearTimeout(bannerTimer);
    banner.dataset.state = state;
    banner.hidden = false;
    banner.textContent = text;
}
function hideBanner() {
    if (banner) banner.hidden = true;
}

function hideToast() {
    window.clearTimeout(toastTimer);
    if (toast) { toast.hidden = true; toastText.textContent = ''; live.textContent = ''; toast.querySelectorAll('button').forEach((b) => b.remove()); }
}

function showToast(text, retry) {
    ensureHost();
    hideToast();
    toastText.textContent = text;
    live.textContent = text;
    const add = (label, cls, fn, aria) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.textContent = label;
        if (aria) b.setAttribute('aria-label', aria);
        b.addEventListener('click', fn);
        toast.append(b);
    };
    if (retry) add('Try again', 'ux-toast-retry', () => { hideToast(); retry(); });
    add('×', 'ux-toast-close', hideToast, 'Dismiss');
    toast.hidden = false;
    toastTimer = window.setTimeout(hideToast, 20000);
}

// ---- retry bookkeeping: one offered retry per target ----
const retried = new WeakSet();   // forms and frames
const retriedUrls = new Set();   // visits, cleared on every page load

function offer(kind, target, url, text, again, run) {
    const key = kind === 'visit' ? url : target;
    const seen = kind === 'visit' ? retriedUrls.has(key) : retried.has(key);
    if (seen) { // the one retry already failed: say so, no button, and re-arm for a later, deliberate try
        kind === 'visit' ? retriedUrls.delete(key) : retried.delete(key);
        showToast(again, null);
        return;
    }
    showToast(text, () => {
        kind === 'visit' ? retriedUrls.add(key) : retried.add(key);
        try { run(); } catch (err) { console.warn(TAG, 'retry failed to start', err); }
    });
}

function formRetry(form, submitter) {
    return () => {
        if (!form.isConnected) { window.Turbo?.visit(location.href, { action: 'replace' }); return; }
        if (submitter && submitter.form === form && submitter.isConnected) form.requestSubmit(submitter);
        else form.requestSubmit();
    };
}
const frameRetry = (frame) => () => (typeof frame.reload === 'function' ? frame.reload() : (frame.src = frame.src));

// ---- changed fields (a 419 must not throw away typing) ----
export function formIsDirty(form) {
    for (const f of form.elements) {
        if (!f.name || f.disabled) continue;
        const type = f.type;
        if (['hidden', 'submit', 'button', 'reset', 'image'].includes(type)) continue;
        if (type === 'checkbox' || type === 'radio') { if (f.checked !== f.defaultChecked) return true; }
        else if (type === 'file') { if (f.files?.length) return true; }
        else if (f.tagName === 'SELECT') {
            const opts = [...f.options];
            if (f.multiple) { if (opts.some((o) => o.selected !== o.defaultSelected)) return true; }
            else {
                const def = opts.findIndex((o) => o.defaultSelected);
                if (opts.length && f.selectedIndex !== (def < 0 ? 0 : def)) return true;
            }
        } else if (f.value !== f.defaultValue) return true;
    }
    return false;
}

// ---- 419 ----
const FLASH_KEY = 'ux:recovery:flash';
function tell(text) { // the page's own toast when it exists (it overwrites the generic "Session expired" one), else ours
    if (typeof window.showToast === 'function') window.showToast(text, 'warning', 10000);
    else showToast(text, null);
}
function handleExpired(form) {
    if (form && formIsDirty(form)) { console.warn(TAG, '419 with unsaved typing: page kept'); tell(MSG.expiredDirty); return; }
    console.warn(TAG, '419: reloading the page');
    let stored = false;
    try { sessionStorage.setItem(FLASH_KEY, MSG.expiredClean); stored = true; } catch { /* storage blocked */ }
    if (!stored) tell(MSG.expiredClean);
    window.setTimeout(() => location.reload(), stored ? 0 : 1800);
}
function showStoredFlash() {
    let msg = null;
    try { msg = sessionStorage.getItem(FLASH_KEY); sessionStorage.removeItem(FLASH_KEY); } catch { /* storage blocked */ }
    if (msg) tell(msg);
}

// ---- wiring (once per document, Turbo-safe) ----
if (!window.__uxRecovery) {
    window.__uxRecovery = true;
    const d = document;

    // offline banner
    const goOffline = () => showBanner('offline', 'You are offline. What you typed stays on this page. We will keep trying.');
    const goOnline = () => {
        if (!banner || banner.hidden || banner.dataset.state !== 'offline') return;
        showBanner('online', 'Back online');
        bannerTimer = window.setTimeout(hideBanner, 3000);
    };
    window.addEventListener('offline', goOffline);
    window.addEventListener('online', goOnline);
    if (navigator.onLine === false) goOffline();

    // network failure of a visit, a form or a frame
    d.addEventListener('turbo:fetch-request-error', (e) => {
        const err = e.detail?.error;
        const req = e.detail?.request;
        if (err?.name === 'AbortError' || req?.headers?.['X-Sec-Purpose'] === 'prefetch') return;
        console.warn(TAG, 'network error', req?.location?.href ?? '', err?.message ?? '');
        const t = e.target;
        if (t instanceof HTMLFormElement) offer('form', t, '', MSG.net, MSG.netAgain, formRetry(t, req?.delegate?.submitter));
        else if (t.matches?.('turbo-frame')) offer('frame', t, '', MSG.net, MSG.netAgain, frameRetry(t));
        else {
            const url = req?.location?.href ?? location.href;
            offer('visit', null, url, MSG.net, MSG.netAgain, () => window.Turbo?.visit(url));
        }
    });

    // a frame whose answer has no matching frame (Turbo's own handling is left as it is)
    d.addEventListener('turbo:frame-missing', (e) => {
        console.warn(TAG, 'frame missing', e.target?.id ?? '');
        offer('frame', e.target, '', MSG.server, MSG.serverAgain, frameRetry(e.target));
    });

    // a 419 or 5xx answer to a form must not replace the page: what was typed stays
    d.addEventListener('turbo:before-fetch-response', (e) => {
        const code = e.detail?.fetchResponse?.statusCode;
        if (e.target instanceof HTMLFormElement && (code === 419 || code >= 500)) e.preventDefault();
    });
    d.addEventListener('turbo:submit-end', (e) => {
        const form = e.detail?.formSubmission?.formElement ?? e.target;
        const code = e.detail?.fetchResponse?.statusCode;
        if (e.detail?.success) { if (form) retried.delete(form); return; }
        if (code === 419) handleExpired(form);
        else if (code >= 500) {
            console.warn(TAG, `server error ${code}`, form?.action ?? '');
            offer('form', form, '', MSG.server, MSG.serverAgain, formRetry(form, e.detail?.formSubmission?.submitter));
        }
    });

    // cleanup: nothing from a page stays on the next one
    d.addEventListener('turbo:before-render', hideToast);
    d.addEventListener('turbo:before-cache', hideToast);
    d.addEventListener('turbo:load', () => { retriedUrls.clear(); showStoredFlash(); });
    window.setTimeout(showStoredFlash, 0); // after app.js has defined window.showToast
}
