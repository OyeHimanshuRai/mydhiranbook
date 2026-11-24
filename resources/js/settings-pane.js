// Settings pages (Loan, Receipt, Users & roles, Plan & billing) swap inside <turbo-frame id="settings-pane">: the rail, top bar and
// title stay where they are, only the pane changes, and the address advances. This file keeps the title, scroll, focus, flash and the
// unsaved-changes guard in step, and holds the Receipt form's Alpine state (it used to be a page script, which a frame swap would miss).
const FRAME = 'settings-pane';

// Alpine state shared by every settings form: `dirty` (edited since load) and `saving` (submit in flight). A second submit while saving is
// ignored; a failed save (validation or network) frees the form again, a good one is replaced by the next page.
const saveGuard = {
    submit(event) { if (this.saving) event.preventDefault(); else this.saving = true; },
    freeOnFailure() { this.$root.addEventListener('turbo:submit-end', (e) => { if (!e.detail.success) this.saving = false; }); },
};
window.stSaveForm = (dirty = false) => ({ dirty, saving: false, submit: saveGuard.submit, init: saveGuard.freeOnFailure });

// Alpine state for the receipt form. Global and loaded with the app, so it exists when the Receipt pane is swapped in from another settings page.
window.rsReceiptSettings = (opts) => ({
    tab: 'pledge', dirty: !!opts.dirty, saving: false, announce: '', counts: {}, panels: {}, len: {}, base: null,
    get message() { return this.saving ? 'Saving…' : this.dirty ? 'Unsaved changes' : (this.announce || 'No changes yet'); },
    submit: saveGuard.submit,
    snapshot() { const d = new FormData(this.$root); d.delete('_token'); return new URLSearchParams(d).toString(); },
    init() {
        const names = ['pledge', 'payment'];
        let want = location.hash.slice(1);
        if (!names.includes(want)) { try { want = sessionStorage.getItem('rs-tab'); } catch (e) { want = null; } }
        this.tab = names.includes(want) ? want : 'pledge';
        this.$root.classList.add('rs-tabbed');
        this.freeOnFailure();
        this.recount();
        this.base = opts.dirty ? null : this.snapshot();
        if (opts.saved) setTimeout(() => { this.announce = opts.saved; }, 300); // set after load so the status region is announced
    },
    setTab(t) {
        this.tab = t;
        try { sessionStorage.setItem('rs-tab', t); history.replaceState(history.state, '', '#' + t); } catch (e) {}
    },
    move(step) { const n = ['pledge', 'payment']; const t = n[(n.indexOf(this.tab) + step + n.length) % n.length]; this.setTab(t); this.$nextTick(() => document.getElementById('rs-tab-' + t).focus()); },
    recount() {
        const counts = {}, panels = {}, len = {};
        this.$root.querySelectorAll('[data-rs-group]').forEach((g) => {
            const boxes = [...g.querySelectorAll('input[name="show[]"]')];
            counts[g.dataset.rsGroup] = { on: boxes.filter((b) => b.checked).length, total: boxes.length };
        });
        this.$root.querySelectorAll('[data-rs-panel]').forEach((p) => {
            const boxes = [...p.querySelectorAll('input[name="show[]"]')];
            panels[p.dataset.rsPanel] = { on: boxes.filter((b) => b.checked).length, total: boxes.length };
        });
        this.$root.querySelectorAll('[data-rs-len]').forEach((t) => { len[t.dataset.rsLen] = t.value.length; });
        this.counts = counts; this.panels = panels; this.len = len;
    },
    countText(id) { const c = this.counts[id]; return c ? c.on + ' of ' + c.total + ' shown' : ''; },
    panelText(id) { const c = this.panels[id]; return c ? c.on + ' of ' + c.total + ' fields printed' : ''; },
    allOn(id) { const c = this.counts[id]; return !!c && c.on === c.total; },
    master(id) {
        const turnOn = !this.allOn(id);
        this.$root.querySelectorAll('[data-rs-group="' + id + '"] input[name="show[]"]').forEach((b) => { b.checked = turnOn; });
        this.touch();
    },
    freeOnFailure: saveGuard.freeOnFailure,
    touch() { this.recount(); this.dirty = this.base === null ? true : this.snapshot() !== this.base; },
    discard() { this.$root.reset(); this.base = this.snapshot(); this.announce = ''; this.recount(); this.dirty = false; },
});

// ---- unsaved changes: ask before a link or visit leaves a dirty form (a real reload or tab close still uses beforeunload in the forms) ----
const dirty = () => [...document.querySelectorAll(`#${FRAME} [x-data]`)].some((el) => {
    try { const d = window.Alpine.$data(el); return d.dirty === true && !d.saving; } catch (e) { return false; }
});
let allowedUntil = 0; // one answer covers the click and the visit it starts
const leave = (event) => {
    if (Date.now() < allowedUntil || !document.getElementById(FRAME) || !dirty()) return;
    if (window.confirm('Leave this page? Changes you have not saved will be lost.')) allowedUntil = Date.now() + 600;
    else event.preventDefault();
};
// A pane link is a frame fetch (turbo:click cannot stop it); the address change that follows, and any full visit, are before-visit.
document.addEventListener('turbo:before-fetch-request', (event) => { if (event.target.id === FRAME) leave(event); });
document.addEventListener('turbo:before-visit', leave);

// ---- after a pane swap: title, document title, scroll to top, focus, drop the old flash ----
document.addEventListener('turbo:frame-load', (event) => {
    const frame = event.target;
    if (frame.id !== FRAME) return;
    const open = frame.querySelector('.dh-subnav [aria-current="page"]');
    const label = open?.textContent.replace(/\s+/g, ' ').trim();
    if (label) {
        document.querySelectorAll('.ui-titleswitch-text, .ui-titleswitch [data-section-label]').forEach((n) => { n.textContent = label; });
        document.title = label;
    }
    document.querySelectorAll('.dh-flash').forEach((n) => n.remove());
    document.querySelector('.dh-scroll')?.scrollTo(0, 0);
    window.scrollTo(0, 0);
    // keep the user's place: the open link when the pills show, else the title switcher on phones
    [open, document.querySelector('.ui-titleswitch-btn')].find((n) => n && n.offsetParent !== null)?.focus({ preventScroll: true });
});
