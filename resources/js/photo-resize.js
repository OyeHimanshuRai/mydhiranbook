// Shrinks a phone photo in the browser before it is uploaded: longest side to MAX_SIDE, re-encoded as JPEG q0.85.
// The server re-encodes every photo anyway and stays the authority; this only saves bytes on slow data.
// Anything that cannot be decoded here (HEIC, damaged files, no canvas) is returned untouched for the server to judge.
export const MAX_SIDE = 2400;
export const QUALITY = 0.85;
const SKIP_BELOW = 400 * 1024; // a small file is not worth a second lossy pass

export function fitSize(width, height, maxSide = MAX_SIDE) {
    const scale = Math.min(1, maxSide / Math.max(width, height));
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

// Browser decode: createImageBitmap applies the EXIF orientation, so the canvas is already upright.
const browserDecode = (file) => createImageBitmap(file, { imageOrientation: 'from-image' });
function browserEncode(bitmap, { width, height }, quality) {
    const canvas = document.createElement('canvas');
    canvas.width = width; canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; // PNG transparency would turn black in a JPEG
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(bitmap, 0, 0, width, height);
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

/** Returns a File no larger than `file`: the resized copy, or `file` itself when resizing does not help or is impossible. */
export async function resizeImage(file, { maxSide = MAX_SIDE, quality = QUALITY, maxBytes = Infinity, decode = browserDecode, encode = browserEncode } = {}) {
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) return file;
    let bitmap;
    try {
        bitmap = await decode(file);
        const size = fitSize(bitmap.width, bitmap.height, maxSide);
        const shrinks = size.width < bitmap.width || size.height < bitmap.height;
        if (!shrinks && file.size <= Math.min(SKIP_BELOW, maxBytes)) return file;
        let blob = await encode(bitmap, size, quality);
        if (blob && blob.size > maxBytes) blob = await encode(bitmap, size, 0.7); // one more try before giving up
        if (!blob || blob.size >= file.size) return file;
        const name = file.name.replace(/\.[^.]*$/, '') + '.jpg';
        return new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    } finally {
        bitmap?.close?.();
    }
}
