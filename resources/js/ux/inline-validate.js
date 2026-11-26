// Inline validation. Opt in per form with data-validate; add data-rule="mobile|pan|aadhaar|prefix|money" on an input for an Indian-format check.
// Messages are friendly hints only: the server stays the authority (App\Support\Mobile, App\Rules\IndianMobileRule and the controllers re-check everything),
// and if this file never loads the browser's own validation and the server still work.

const MOBILE_MSG = 'Enter a 10-digit mobile number that starts with 6, 7, 8 or 9.';

/** Each rule returns '' when the (non-empty) value is fine, else the message. */
export const RULES = {
    // Mirrors App\Support\Mobile::normalize: drop spaces and punctuation, strip +, a 0091/091/91/0 prefix only when exactly 10 digits remain.
    mobile(v) {
        const raw = v.trim();
        if (/[A-Za-z]/.test(raw)) return 'A mobile number cannot contain letters.';
        let d = raw.replace(/[\s\-().]+/g, '').replace(/^\++/, '');
        if (!/^\d+$/.test(d)) return MOBILE_MSG;
        for (const p of ['0091', '091', '91', '0']) {
            if (d.length === p.length + 10 && d.startsWith(p)) { d = d.slice(p.length); break; }
        }
        if (/^[6-9]\d{9}$/.test(d)) return '';
        if (d.length < 10) return `A mobile number has 10 digits; this one has only ${d.length}.`;
        if (d.length > 10) return 'Enter just the 10 digits, without the country code.';
        return MOBILE_MSG;
    },
    pan: (v) => (/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(v.trim().toUpperCase()) ? '' : 'A PAN looks like ABCDE1234F: five letters, four digits, one letter.'),
    aadhaar: (v) => (/^[2-9]\d{11}$/.test(v.replace(/\s+/g, '')) ? '' : 'Enter a 12-digit Aadhaar number. It cannot start with 0 or 1.'),
    // Server rule is alpha_dash (Unicode letters and digits allowed), max 10.
    prefix: (v) => (/^[\p{L}\p{M}\p{N}_-]{1,10}$/u.test(v.trim()) ? '' : 'Use up to 10 letters, digits, hyphens or underscores.'),
    money: (v) => (/^\d+(\.\d{1,2})?$/.test(v.trim()) && parseFloat(v) > 0 ? '' : 'Enter an amount above 0, with at most 2 decimal places.'),
};

export function checkRule(rule, value) {
    const fn = RULES[rule];
    return fn && String(value).trim() !== '' ? fn(String(value)) : '';
}

const SKIP_TYPES = new Set(['hidden', 'file', 'radio', 'submit', 'button', 'reset', 'image']);
const inBrowser = typeof document !== 'undefined';

const visible = (el) => el.getClientRects().length > 0 && !el.closest('[hidden], dialog:not([open])');
const fieldsOf = (form) => [...form.elements].filter((el) => /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && !SKIP_TYPES.has(el.type) && el.willValidate && visible(el));

/** The first problem with a field as a friendly sentence, or ''. */
function problem(el) {
    const v = el.value;
    if (el.type === 'checkbox') return el.required && !el.checked ? 'Tick this box to continue.' : '';
    if (v.trim() === '') return el.required ? (el.tagName === 'SELECT' ? 'Choose an option.' : 'This field is required.') : '';
    const rule = el.getAttribute('data-rule');
    if (rule) { const m = checkRule(rule, v); if (m) return m; }
    const vd = el.validity;
    if (vd.badInput) return 'Enter a number.';
    if (vd.typeMismatch) return el.type === 'email' ? 'Enter a valid email address, like name@example.com.' : 'Check the format of this entry.';
    // length is checked by hand: browsers only flag tooShort after the user typed in the field
    if (el.minLength > 0 && v.length < el.minLength) return `Use at least ${el.minLength} characters.`;
    if (el.maxLength >= 0 && v.length > el.maxLength) return `Use at most ${el.maxLength} characters.`;
    if (vd.rangeUnderflow) return `Enter ${el.min} or more.`;
    if (vd.rangeOverflow) return `Enter ${el.max} or less.`;
    if (vd.stepMismatch) {
        const d = (el.step.split('.')[1] || '').length;
        return d ? `Use at most ${d} decimal place${d > 1 ? 's' : ''}.` : 'Enter a whole number.';
    }
    if (vd.patternMismatch && !rule) return el.title || 'Check the format of this entry.';
    return '';
}

const states = new WeakMap(); // form -> { ac, errs: Map(el -> <p>), server: WeakSet(el), noValidate }
const live = new Set();
let counter = 0;

const tokens = (el) => (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
function setTokens(el, list) {
    if (list.length) el.setAttribute('aria-describedby', list.join(' '));
    else el.removeAttribute('aria-describedby');
}

function anchorOf(el) {
    return el.closest('.fm-affix, .st-affix, .ui-input') || (el.type === 'checkbox' ? el.closest('label') : null) || el;
}

function show(st, el, msg) {
    let p = st.errs.get(el);
    if (!p) {
        p = document.createElement('p');
        p.className = el.closest('.ui-field') ? 'ux-err ui-err' : 'ux-err';
        p.id = 'ux-err-' + (el.id || el.name || 'f').replace(/[^\w-]+/g, '-') + '-' + ++counter;
        anchorOf(el).after(p);
        st.errs.set(el, p);
        setTokens(el, [...tokens(el), p.id]);
    }
    p.textContent = msg;
    el.setAttribute('aria-invalid', 'true');
}

function clear(st, el) {
    const p = st.errs.get(el);
    if (!p) return;
    setTokens(el, tokens(el).filter((t) => t !== p.id));
    p.remove();
    st.errs.delete(el);
    el.removeAttribute('aria-invalid');
}

/** The field was edited: the server's message about the old value no longer applies. */
function dropServer(st, el) {
    st.server.delete(el);
    const keep = [];
    for (const id of tokens(el)) {
        const n = document.getElementById(id);
        if (n && !n.classList.contains('ux-err') && /\b(fm-error|st-error|ui-err)\b/.test(n.className)) n.remove();
        else keep.push(id);
    }
    setTokens(el, keep);
    el.removeAttribute('aria-invalid');
    el.classList.remove('is-invalid');
    el.closest('.st-affix, .fm-affix')?.classList.remove('is-invalid');
    el.closest('.ui-field')?.classList.remove('has-err');
}

function check(st, el) {
    if (st.server.has(el)) return; // a server-rendered error stays until the user edits the field
    const m = problem(el);
    if (m) show(st, el, m); else clear(st, el);
}

function attach(form) {
    if (states.has(form)) return;
    const st = { ac: new AbortController(), errs: new Map(), server: new WeakSet(), noValidate: form.noValidate };
    states.set(form, st);
    live.add(form);
    // Our messages replace the browser's pop-ups. Without this script the attribute is never set, so native validation still protects the form.
    form.noValidate = true;
    for (const el of form.elements) if (el.getAttribute && el.getAttribute('aria-invalid') === 'true') st.server.add(el);
    const opts = { signal: st.ac.signal };
    const fieldFrom = (e) => (e.target && e.target.form === form && /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName) && !SKIP_TYPES.has(e.target.type) ? e.target : null);
    form.addEventListener('focusout', (e) => { const el = fieldFrom(e); if (el && visible(el) && el.willValidate) check(st, el); }, opts);
    const edited = (e) => {
        const el = fieldFrom(e);
        if (!el) return;
        if (st.server.has(el)) dropServer(st, el);
        if (st.errs.has(el)) check(st, el); // already shown invalid: re-check live so the message goes as soon as it is fixed
    };
    form.addEventListener('input', edited, opts);
    form.addEventListener('change', edited, opts);
}

function cleanup(form) {
    const st = states.get(form);
    if (!st) return;
    st.ac.abort();
    for (const el of [...st.errs.keys()]) clear(st, el);
    form.noValidate = st.noValidate;
    states.delete(form);
    live.delete(form);
}

function init() {
    document.querySelectorAll('form[data-validate]').forEach(attach);
}

if (inBrowser) {
    // Capture phase on document: runs before the form's own handlers (Alpine busy flags, the site's data-once guard, Turbo), and on an invalid
    // form it stops the event so none of them marks the form as "sending".
    document.addEventListener('submit', (e) => {
        const form = e.target;
        const st = states.get(form);
        if (!st) return;
        let first = null;
        for (const el of fieldsOf(form)) {
            if (st.server.has(el)) { if (!first && problem(el)) first = el; continue; }
            const m = problem(el);
            if (m) { show(st, el, m); first ??= el; } else clear(st, el);
        }
        if (!first) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        first.focus({ preventScroll: true });
        const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        first.scrollIntoView({ block: 'center', behavior: calm ? 'auto' : 'smooth' });
    }, true);
    document.addEventListener('turbo:before-cache', () => [...live].forEach(cleanup));
    document.addEventListener('turbo:load', init);
    document.addEventListener('turbo:render', init);
    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
}
