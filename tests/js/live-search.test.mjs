import test from 'node:test';
import assert from 'node:assert/strict';
import { createLiveSearch } from '../../resources/js/live-search.js';

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
function rig(extra = {}) {
    const calls = [], results = [], state = { idle: 0, errors: 0 };
    const search = createLiveSearch({
        url: (q) => '/s?q=' + encodeURIComponent(q), delay: 20,
        fetcher: (u, o) => { calls.push({ u, signal: o.signal }); return Promise.resolve({ ok: true, json: async () => ['r:' + u] }); },
        onResult: (q, d) => results.push([q, d]), onIdle: () => state.idle++, onError: () => state.errors++, ...extra,
    });
    return { search, calls, results, state };
}

test('rapid typing sends one request', async () => {
    const { search, calls, results } = rig();
    for (const v of ['ar', 'aru', 'arun', 'arun ', 'arun k']) { search.query(v); await wait(3); }
    await wait(60);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].u, '/s?q=arun%20k');
    assert.equal(results.length, 1);
});

test('queries under 2 characters send nothing', async () => {
    const { search, calls, state } = rig();
    search.query('a'); search.query('  '); search.query('');
    await wait(50);
    assert.equal(calls.length, 0);
    assert.equal(state.idle, 3);
});

test('a newer request aborts the one in flight and its answer is dropped', async () => {
    const pending = [];
    const { search, results } = rig({ fetcher: (u, o) => new Promise((res, rej) => { pending.push({ res, o }); o.signal.addEventListener('abort', () => rej(Object.assign(new Error('x'), { name: 'AbortError' }))); }) });
    search.query('aa'); await wait(40);
    search.query('bb'); await wait(40);
    assert.equal(pending[0].o.signal.aborted, true);
    pending[1].res({ ok: true, json: async () => ['bb'] });
    await wait(10);
    assert.deepEqual(results, [['bb', ['bb']]]);
});

test('a repeated query is answered from the session cache', async () => {
    const { search, calls, results } = rig();
    search.query('arun'); await wait(60);
    search.query('arun'); // same answer, no wait, no request
    assert.equal(calls.length, 1);
    assert.equal(results.length, 2);
});

test('a failed request reports once and is not cached', async () => {
    let n = 0;
    const { search, state, calls } = rig({ fetcher: () => (++n === 1 ? Promise.resolve({ ok: false, status: 500 }) : Promise.resolve({ ok: true, json: async () => [] })) });
    search.query('arun'); await wait(60);
    assert.equal(state.errors, 1);
    search.query('arun'); await wait(60);
    assert.equal(n, 2);
});
