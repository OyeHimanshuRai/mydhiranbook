// Form drafts. Opt in per form with data-autosave="<draft-key>" (optional data-autosave-label, data-autosave-add-row on the "add row" button).
// A draft lives in localStorage under mdb-draft:<scope>:<key>, where <scope> comes from <meta name="ux-scope"> (per user + shop,
// rendered only inside the signed-in app shell). No scope (public pages, guests) = this module touches no storage at all.
//
// Never stored: password / file / hidden inputs, and any field whose name or id looks like identity data (PAN, Aadhaar, ID number,
// KYC, OTP, tokens, cards, signatures, photos). Unnamed controls are stored only when they carry data-autosave-include.
// Hidden inputs are skipped because on these forms they are Alpine-computed (customer_id, loan_value, purity...): restoring them
// would put the DOM and the Alpine state out of step. So a restored New Loan does not re-pick the borrower.

const DRAFT_PREFIX = 'mdb-draft:';
const SENT_PREFIX = 'mdb-draft-sent:';
const MAX_AGE = 24 * 3600 * 1000;
const MAX_BYTES = 100 * 1024;
const DEBOUNCE = 600;
const SENT_TTL = 120 * 1000; // submit -> next page load takes seconds; an older flag is stale
const QUIET = 20 * 1000;     // at most one "Draft saved" announcement per 20 s
const SENSITIVE = /aadhaar|aadhar|pan|kyc|id_proof|id_number|otp|token|password|card|signature|photo|image|cvv/i;
const SKIP_TYPES = new Set(['password', 'file', 'hidden', 'submit', 'button', 'reset', 'image']);
const LABELS = { 'new-loan': 'loan', 'new-borrower': 'customer' };
const ERROR_MARKERS = '[aria-invalid="true"], .fm-error, .st-error, .ui-err, .fm-alert[role="alert"]';

const inBrowser = typeof document !== 'undefined' && typeof window !== 'undefined';

/** Every storage touch goes through here: private mode / blocked storage / quota just means "no drafts". */
function safe(fn, fallback = null) {
    try { return fn(); } catch { return fallback; }
}
const local = () => safe(() => window.localStorage);
const session = () => safe(() => window.sessionStorage);

function scopeId() {
    const v = document.querySelector('meta[name="ux-scope"]')?.getAttribute('content') || '';
    return /^[0-9a-f]{16}$/.test(v) ? v : '';
}

const keyOf = (el) => el.name || (el.hasAttribute('data-autosave-include') && el.id ? '#' + el.id : '');

function eligible(el) {
    if (!/^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) return false;
    if (SKIP_TYPES.has(el.type) || el.multiple) return false;
    const k = keyOf(el);
    if (!k || SENSITIVE.test(k) || SENSITIVE.test(el.id || '')) return false;
    if (/^cc-/.test(el.getAttribute('autocomplete') || '')) return false;
    return !el.closest('dialog');
}

const isCheckable = (el) => el.type === 'checkbox' || el.type === 'radio';
const eligibleEls = (form) => [...form.elements].filter(eligible);

/** Ordered list (names repeat: name[], radios), so nothing is keyed by name alone. */
function collect(form) {
    return eligibleEls(form).map((el) => {
        const n = keyOf(el);
        return isCheckable(el) ? { n, v: el.value, c: el.checked } : { n, v: el.value };
    });
}

function validFields(list) {
    return Array.isArray(list) && list.every((f) => f && typeof f.n === 'string' && typeof f.v === 'string' && (!('c' in f) || typeof f.c === 'boolean'));
}

function readDraft(storeKey) {
    const raw = safe(() => local().getItem(storeKey));
    if (!raw) return null;
    const d = safe(() => JSON.parse(raw));
    if (d && typeof d.savedAt === 'number' && validFields(d.fields) && Date.now() - d.savedAt < MAX_AGE && d.savedAt <= Date.now() + 60000) return d;
    safe(() => local().removeItem(storeKey));
    return null;
}

const removeDraft = (storeKey) => safe(() => local().removeItem(storeKey));

/** Old, unreadable or oversized drafts for this scope go; other users' drafts are never touched. */
function expire(scope) {
    const ls = local();
    if (!ls) return;
    const prefix = DRAFT_PREFIX + scope + ':';
    safe(() => {
        for (let i = ls.length - 1; i >= 0; i--) {
            const k = ls.key(i);
            if (k && k.startsWith(prefix)) readDraft(k);
        }
    });
}

const hasPageErrors = () => [...document.querySelectorAll(ERROR_MARKERS)].some((n) => n.getClientRects().length > 0);

/** A submit that went through leaves a flag; the next page that loads removes the draft unless it shows errors. */
function consumeSent(scope) {
    const ss = session();
    if (!ss) return;
    const prefix = SENT_PREFIX + scope + ':';
    const flags = [];
    safe(() => { for (let i = 0; i < ss.length; i++) { const k = ss.key(i); if (k && k.startsWith(prefix)) flags.push(k); } });
    const errors = hasPageErrors();
    for (const k of flags) {
        const at = Number(safe(() => ss.getItem(k)));
        safe(() => ss.removeItem(k));
        if (!errors && Date.now() - at < SENT_TTL) removeDraft(DRAFT_PREFIX + scope + ':' + k.slice(prefix.length));
    }
}

const pause = (ms = 40) => new Promise((r) => setTimeout(r, ms));
const fire = (el) => {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
};

/** Alpine builds rows from state; wait until the form's x-data root has booted before taking the "pristine" snapshot. */
async function alpineReady(form) {
    const root = form.closest('[x-data]');
    for (let i = 0; i < 25 && root && !root._x_dataStack; i++) await pause(40);
    await pause(60);
}

function rowIndexes(names) {
    const out = new Map();
    for (const n of names) {
        const m = /^([\w-]+)\[(\d+)\]/.exec(n);
        if (m) out.set(m[1], Math.max(out.get(m[1]) ?? -1, Number(m[2])));
    }
    return out;
}

/** Click the form's own "add row" button until the rows the draft needs exist (bounded; a missing button just means fewer rows). */
async function ensureRows(form, fields) {
    const btn = form.querySelector('[data-autosave-add-row]');
    if (!btn) return;
    const need = rowIndexes(fields.map((f) => f.n));
    for (let guard = 0; guard < 25; guard++) {
        const have = rowIndexes(eligibleEls(form).map(keyOf));
        const missing = [...need].some(([group, max]) => (have.get(group) ?? -1) < max);
        if (!missing) return;
        btn.click();
        await pause(60);
        const after = rowIndexes(eligibleEls(form).map(keyOf));
        if (![...after].some(([g, m]) => m > (have.get(g) ?? -1))) return; // nothing grew: stop, never loop
    }
}

function apply(form, fields) {
    const queues = new Map();
    const checks = new Map();
    for (const f of fields) {
        if ('c' in f) checks.set(f.n + '\0' + f.v, f.c);
        else (queues.get(f.n) || queues.set(f.n, []).get(f.n)).push(f.v);
    }
    const seen = new Map();
    for (const el of eligibleEls(form)) {
        const n = keyOf(el);
        if (isCheckable(el)) {
            const k = n + '\0' + el.value;
            if (checks.has(k) && el.checked !== checks.get(k)) { el.checked = checks.get(k); fire(el); }
            continue;
        }
        const i = seen.get(n) || 0;
        seen.set(n, i + 1);
        const v = (queues.get(n) || [])[i];
        if (v === undefined || el.value === v) continue;
        if (el.tagName === 'SELECT' && ![...el.options].some((o) => o.value === v)) continue;
        el.value = v;
        fire(el);
    }
}

/** Two passes: changing a metal or a mode makes the form recalculate other fields, so the second pass re-applies what got overwritten. */
async function restore(form, fields) {
    await ensureRows(form, fields);
    apply(form, fields);
    await pause();
    apply(form, fields);
}

function ago(ms) {
    const m = Math.floor((Date.now() - ms) / 60000);
    if (m < 1) return 'a moment ago';
    if (m < 60) return m === 1 ? '1 minute ago' : m + ' minutes ago';
    const h = Math.floor(m / 60);
    return h === 1 ? '1 hour ago' : h + ' hours ago';
}

const live = new Set();       // entries on the current page
const byForm = new WeakMap();
const inited = new WeakSet();

function labelFor(form, key) {
    return form.getAttribute('data-autosave-label') || LABELS[key] || key.replace(/^new-/, '').replace(/[-_]+/g, ' ');
}

function note(entry, text, force) {
    if (!entry.status) return;
    const now = Date.now();
    if (!force && now - entry.lastNote < QUIET) return;
    entry.lastNote = now;
    entry.status.textContent = text;
    entry.status.classList.add('is-on');
    clearTimeout(entry.fade);
    entry.fade = setTimeout(() => {
        entry.status.classList.remove('is-on');
        entry.fade = setTimeout(() => { entry.status.textContent = ''; }, 400);
    }, 2500);
}

function save(entry) {
    clearTimeout(entry.timer);
    if (entry.sent || entry.pending || !entry.form.isConnected) return;
    const fields = collect(entry.form);
    if (JSON.stringify(fields) === entry.baseline) { removeDraft(entry.storeKey); return; } // untouched form: nothing worth keeping
    const payload = JSON.stringify({ savedAt: Date.now(), fields });
    if (payload.length > MAX_BYTES) return;
    if (safe(() => { local().setItem(entry.storeKey, payload); return true; }, false)) note(entry, 'Draft saved');
}

function schedule(entry) {
    if (entry.sent || entry.pending || entry.restoring) return;
    clearTimeout(entry.timer);
    entry.timer = setTimeout(() => save(entry), DEBOUNCE);
}

function makeStatus(form) {
    const el = document.createElement('span');
    el.className = 'ux-draft-status';
    el.setAttribute('aria-live', 'polite');
    const bar = form.querySelector('.fm-bar');
    if (bar) bar.append(el);
    else {
        const btn = form.querySelector('button[type="submit"], input[type="submit"]');
        (btn?.parentElement || form).append(el);
    }
    return el;
}

function button(text, cls, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn ' + cls;
    b.textContent = text;
    b.addEventListener('click', onClick);
    return b;
}

function showBanner(entry, draft) {
    const box = document.createElement('div');
    box.className = 'ux-draft-banner';
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', 'Unsent draft');
    box.tabIndex = -1;
    const text = document.createElement('p');
    text.className = 'ux-draft-text';
    text.textContent = `We saved your unsent ${labelFor(entry.form, entry.key)} from ${ago(draft.savedAt)}.`;
    const acts = document.createElement('div');
    acts.className = 'ux-draft-acts';
    const done = (msg) => {
        acts.remove();
        text.textContent = msg;
        text.setAttribute('role', 'status');
        box.focus({ preventScroll: true });
    };
    acts.append(
        button('Restore', 'btn-primary', async () => {
            entry.restoring = true;
            try { await restore(entry.form, draft.fields); } catch { /* a missing field must never break the form */ }
            entry.restoring = false;
            entry.pending = false;
            done('Draft restored. Check the details before you save.');
            schedule(entry);
        }),
        button('Discard', 'btn-secondary', () => {
            removeDraft(entry.storeKey);
            entry.pending = false;
            done('Draft discarded.');
        }),
    );
    box.append(text, acts);
    entry.banner = box;
    entry.pending = true;
    entry.form.prepend(box);
}

async function attach(form, scope) {
    if (inited.has(form)) return;
    inited.add(form);
    const key = (form.getAttribute('data-autosave') || '').trim();
    if (!/^[\w-]{1,40}$/.test(key)) return;
    const entry = {
        form, key, storeKey: DRAFT_PREFIX + scope + ':' + key, sentKey: SENT_PREFIX + scope + ':' + key,
        timer: 0, fade: 0, baseline: null, pending: false, restoring: false, sent: false, lastNote: 0, banner: null, status: null,
        ac: new AbortController(),
    };
    byForm.set(form, entry);
    live.add(entry);
    entry.status = makeStatus(form);
    const opts = { signal: entry.ac.signal };
    const touch = () => { if (entry.baseline !== null) schedule(entry); };
    form.addEventListener('input', touch, opts);
    form.addEventListener('change', touch, opts);

    await alpineReady(form);
    if (!form.isConnected || entry.ac.signal.aborted) return;
    entry.baseline = JSON.stringify(collect(form));
    const draft = readDraft(entry.storeKey);
    if (!draft) return;
    if (JSON.stringify(draft.fields) === entry.baseline) { removeDraft(entry.storeKey); return; }
    if (hasPageErrors()) return; // a refused save shows the user's own input: keep the draft, offer nothing
    showBanner(entry, draft);
}

function flushAll() {
    for (const e of live) if (e.timer && !e.sent) save(e);
}

function teardown() {
    for (const e of live) {
        clearTimeout(e.timer);
        clearTimeout(e.fade);
        e.ac.abort();
        e.banner?.remove();
        e.status?.remove();
        inited.delete(e.form);
    }
    live.clear();
}

async function boot() {
    const scope = scopeId();
    if (!scope || !local()) return;
    expire(scope);
    const forms = [...document.querySelectorAll('form[data-autosave]')];
    // the sent flag is judged after Alpine settled, so x-show'd error stubs are not mistaken for rendered errors
    if (forms.length) await alpineReady(forms[0]); else await pause(60);
    consumeSent(scope);
    forms.forEach((f) => attach(f, scope));
}

function markSent(entry) {
    entry.sent = true;
    clearTimeout(entry.timer);
    safe(() => session().setItem(entry.sentKey, String(Date.now())));
}

if (inBrowser) {
    // Bubble phase on document: by now Alpine / inline validation have had their say. Turbo registers its own listener last, so a
    // submit that nothing cancelled is still un-prevented here.
    document.addEventListener('submit', (e) => {
        const entry = byForm.get(e.target);
        if (entry && !e.defaultPrevented) markSent(entry);
    });
    // A request that failed (network, 5xx) did not take effect: keep the draft. A redirect-back-with-errors "succeeds" for Turbo, which
    // is why the draft is only deleted by the next page load, and only when no errors are rendered.
    document.addEventListener('turbo:submit-end', (e) => {
        const entry = byForm.get(e.target);
        if (entry && e.detail && e.detail.success === false) {
            entry.sent = false;
            safe(() => session().removeItem(entry.sentKey));
        }
    });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushAll(); });
    window.addEventListener('pagehide', flushAll);
    document.addEventListener('turbo:before-cache', teardown);
    document.addEventListener('turbo:load', boot);
    document.addEventListener('turbo:render', boot);
    if (document.readyState !== 'loading') boot();
    else document.addEventListener('DOMContentLoaded', boot);
}
