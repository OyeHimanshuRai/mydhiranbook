import test from 'node:test';
import assert from 'node:assert/strict';
import { fitSize, resizeImage } from '../../resources/js/photo-resize.js';

const file = (size, type = 'image/jpeg', name = 'IMG_1.jpeg') => new File([new Uint8Array(size)], name, { type });
const bitmap = (width, height) => ({ width, height, close() { this.closed = true; } });
const enc = (bytes) => async () => new Blob([new Uint8Array(bytes)]);

test('fitSize keeps the ratio and never upscales', () => {
    assert.deepEqual(fitSize(4000, 3000), { width: 2400, height: 1800 });
    assert.deepEqual(fitSize(3000, 4000), { width: 1800, height: 2400 });
    assert.deepEqual(fitSize(800, 600), { width: 800, height: 600 });
    assert.deepEqual(fitSize(12000, 1), { width: 2400, height: 1 });
});

test('a 6 MB 4000x3000 phone photo becomes a smaller 2400x1800 JPEG', async () => {
    let seen;
    const original = file(6 * 1024 * 1024);
    const out = await resizeImage(original, { decode: async () => bitmap(4000, 3000), encode: async (b, size) => { seen = size; return new Blob([new Uint8Array(700 * 1024)]); } });
    assert.deepEqual(seen, { width: 2400, height: 1800 });
    assert.equal(out.type, 'image/jpeg');
    assert.equal(out.name, 'IMG_1.jpg');
    assert.equal(out.size, 700 * 1024);
});

test('a small image already within the limit is left alone', async () => {
    const original = file(200 * 1024);
    assert.equal(await resizeImage(original, { decode: async () => bitmap(1200, 900), encode: enc(1) }), original);
});

test('the original is kept when the re-encode would not be smaller', async () => {
    const original = file(900 * 1024);
    assert.equal(await resizeImage(original, { decode: async () => bitmap(2000, 1500), encode: enc(950 * 1024) }), original);
});

test('a result over maxBytes is retried at lower quality', async () => {
    const qualities = [];
    const out = await resizeImage(file(9 * 1024 * 1024), { maxBytes: 8 * 1024 * 1024, decode: async () => bitmap(6000, 4000),
        encode: async (b, s, q) => { qualities.push(q); return new Blob([new Uint8Array(q > 0.8 ? 9e6 : 3e6)]); } });
    assert.deepEqual(qualities, [0.85, 0.7]);
    assert.equal(out.size, 3e6);
});

test('undecodable files (HEIC, damaged) and PDFs go through untouched', async () => {
    const heic = file(5e6, 'image/heic', 'a.heic');
    assert.equal(await resizeImage(heic, { decode: async () => { throw new Error('no'); }, encode: enc(1) }), heic);
    const jpg = file(5e6);
    assert.equal(await resizeImage(jpg, { decode: async () => { throw new Error('no'); }, encode: enc(1) }), jpg);
    const pdf = file(5e6, 'application/pdf', 'a.pdf');
    assert.equal(await resizeImage(pdf, { decode: async () => assert.fail('decoded a pdf') }), pdf);
});

test('the decoded bitmap is released', async () => {
    const b = bitmap(4000, 3000);
    await resizeImage(file(5e6), { decode: async () => b, encode: enc(1e5) });
    assert.equal(b.closed, true);
});
