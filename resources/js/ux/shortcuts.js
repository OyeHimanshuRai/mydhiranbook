// Keyboard shortcuts: "/" search box, "n" new loan, "g" then l / c / r / s to jump, "?" help. Ctrl/Cmd K belongs to palette.js.
// Targets come from the palette's server-built page list (data-items on #dhPalette), so a page the user may not open is never a shortcut.
// Paused while typing in a field, while any dialog is open, and with Ctrl/Alt/Meta held. Turbo replaces the layout on every visit:
// listeners are added on turbo:load and removed on turbo:before-cache; elements are looked up when used.
const SEQ_MS = 1000;
const JUMPS = { l: ['loans'], c: ['customers'], r: ['reports'], s: ['settings', 'receipt', 'notices', 'users'] };
const NON_TEXT = new Set(['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file', 'image']);

let ac = null;
let armed = 0;

const pageUrl = (keys) => {
    let items = [];
    try { items = JSON.parse(document.getElementById('dhPalette')?.dataset.items || '[]'); } catch { /* none */ }
    for (const k of keys) {
        const u = items.find((i) => i.key === k)?.url;
        if (typeof u === 'string' && u.startsWith('/') && !u.startsWith('//')) return u;
    }
    return null;
};
const visit = (url) => (window.Turbo ? window.Turbo.visit(url) : window.location.assign(url));

function typing(el) {
    if (!(el instanceof Element)) return false;
    if (el.isContentEditable || el.closest('[contenteditable=""], [contenteditable="true"]')) return true;
    if (el.matches('textarea, select, [role=textbox], [role=combobox], [role=searchbox]')) return true;
    return el.tagName === 'INPUT' && !NON_TEXT.has((el.type || '').toLowerCase());
}

function focusSearch(e) {
    const shown = (el) => !el.disabled && el.getClientRects().length > 0 && !el.closest('[inert]');
    const pick = (sel) => [...document.querySelectorAll(sel)].find(shown);
    const el = pick('input[type=search], input[data-list-search], [data-list-search] input, [data-dhiran-search] input')
        || pick('.dh-content .dh-fbar input[type=text], .dh-content .dh-fbar input:not([type])');
    e.preventDefault();
    if (el) { el.focus(); el.select?.(); } else document.dispatchEvent(new CustomEvent('dhiran:palette')); // no search box here: open the palette
}

function onKey(e) {
    if (e.defaultPrevented || e.isComposing || e.repeat || e.ctrlKey || e.altKey || e.metaKey) return;
    if (typing(e.target)) return;
    const k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
    const help = document.getElementById('dhShortcuts');
    if (document.querySelector('dialog[open]')) {
        if (help?.open && k === '?') { e.preventDefault(); help.close(); }
        return;
    }
    if (document.querySelector('.dh-shell.is-open')) return; // phone menu drawer
    if (e.shiftKey && k !== '?') return;

    if (armed) {
        const fresh = Date.now() - armed < SEQ_MS;
        armed = 0;
        if (fresh) {
            const url = JUMPS[k] && pageUrl(JUMPS[k]);
            if (url) { e.preventDefault(); visit(url); }
            return;
        }
    }
    if (k === '/') focusSearch(e);
    else if (k === 'n') { const url = pageUrl(['new']); if (url) { e.preventDefault(); visit(url); } }
    else if (k === 'g') armed = Date.now();
    else if (k === '?' && help) { e.preventDefault(); help.showModal(); }
}

function onClick(e) {
    const help = document.getElementById('dhShortcuts');
    if (help?.open && (e.target === help || e.target.closest?.('[data-shortcuts-close]'))) help.close();
}

function destroy() {
    armed = 0;
    const help = document.getElementById('dhShortcuts');
    if (help?.open) help.close();
    ac?.abort();
    ac = null;
}

function init() {
    destroy();
    ac = new AbortController();
    document.addEventListener('keydown', onKey, { signal: ac.signal });
    document.addEventListener('click', onClick, { signal: ac.signal });
}

document.addEventListener('turbo:load', init);
document.addEventListener('turbo:before-cache', destroy);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
