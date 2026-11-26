import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// The app is English only and has no /translations endpoint: a page load must not ask for one.
test('app.js does not fetch a translations file', () => {
    const src = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.ok(!src.includes('/translations/'));
});
