// One way to search as you type: waits `delay` ms after the last keystroke, ignores queries shorter than `min` characters,
// cancels the request still in flight when a newer one starts, and remembers answers for the session so a repeated query costs nothing.
// Callers get results through onResult(query, data), failures through onError(query) and "nothing to show" through onIdle().
export function createLiveSearch({ url, delay = 300, min = 2, max = 60, maxCached = 50, fetcher = (...a) => globalThis.fetch(...a), onResult, onError = () => {}, onIdle = () => {}, onPending = () => {} }) {
    const cache = new Map();
    let timer = 0;
    let ctl = null;
    const stop = () => { clearTimeout(timer); timer = 0; ctl?.abort(); ctl = null; };
    return {
        query(raw) {
            stop();
            const q = String(raw ?? '').trim().slice(0, max);
            if (q.length < min) { onIdle(); return; }
            if (cache.has(q)) { onResult(q, cache.get(q)); return; }
            onPending(q);
            timer = setTimeout(() => {
                const mine = ctl = new AbortController();
                fetcher(url(q), { signal: mine.signal, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http ' + r.status))))
                    .then((data) => {
                        if (ctl !== mine) return;
                        if (cache.size >= maxCached) cache.delete(cache.keys().next().value);
                        cache.set(q, data);
                        onResult(q, data);
                    })
                    .catch((e) => { if (e?.name === 'AbortError' || ctl !== mine) return; onError(q); });
            }, delay);
        },
        cancel: stop,
    };
}
