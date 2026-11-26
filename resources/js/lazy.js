// Page-specific code is fetched only on pages whose markup needs it (customer photos, camera, date pickers, drafts).
// `load` runs once, after the first turbo:load (or now) at which `selector` matches; modules start themselves when imported late.
export function loadWhen(selector, load) {
    let started = false;
    const check = () => { if (!started && document.querySelector(selector)) { started = true; load(); } };
    document.addEventListener('turbo:load', check);
    check();
}
