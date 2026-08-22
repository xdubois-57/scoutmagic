// Shared end-to-end helper: build a real PNG of any size, in memory.
//
// Several scenarios need an image whose DIMENSIONS matter — the client-side
// downscale in public/assets/js/upload.js only wakes up past 2400px, and a
// gallery thumbnail chain needs something visibly picture-shaped — while a
// committed binary fixture would be both opaque and fixed. This builds a
// minimal, valid PNG (8-bit RGBA, one deflate-compressed IDAT of
// filter-0 scanlines) with Node's own zlib; nothing else is required.
import { randomFillSync } from 'node:crypto';
import { deflateSync } from 'node:zlib';

const CRC_TABLE = (() => {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n += 1) {
        let c = n;
        for (let k = 0; k < 8; k += 1) {
            c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
        }
        table[n] = c;
    }
    return table;
})();

/** @param {Buffer} buffer */
function crc32(buffer) {
    let crc = 0xffffffff;
    for (const byte of buffer) {
        crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ 0xffffffff) >>> 0;
}

/**
 * @param {string} type four-character chunk type
 * @param {Buffer} data
 */
function chunk(type, data) {
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length);
    const body = Buffer.concat([Buffer.from(type, 'latin1'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(body));
    return Buffer.concat([length, body, crc]);
}

/**
 * A valid PNG of the given size, filled with one colour. A gradient would
 * compress worse for no benefit — what the consumers of this helper assert
 * on is dimensions and decodability, never content.
 *
 * @param {number} width
 * @param {number} height
 * @param {[number, number, number, number]} [rgba]
 * @returns {Buffer}
 */
export function pngBuffer(width, height, rgba = [46, 91, 138, 255]) {
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8; // bit depth
    ihdr[9] = 6; // colour type: RGBA
    // compression 0, filter 0, interlace 0 — already zeroed.

    const row = Buffer.alloc(1 + width * 4); // leading filter byte 0
    for (let x = 0; x < width; x += 1) {
        row[1 + x * 4] = rgba[0];
        row[2 + x * 4] = rgba[1];
        row[3 + x * 4] = rgba[2];
        row[4 + x * 4] = rgba[3];
    }
    const raw = Buffer.concat(Array.from({ length: height }, () => row));

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', deflateSync(raw)),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

/**
 * A valid PNG whose FILE SIZE is what matters: random pixels compress to
 * roughly their raw size, so the result weighs close to width × height ×
 * 4 bytes on disk. What forces a large file through an upload path — the
 * chunked-upload threshold is a byte count, and a solid-colour image of
 * any dimension deflates to almost nothing.
 *
 * @param {number} width
 * @param {number} height
 * @returns {Buffer}
 */
export function noisePngBuffer(width, height) {
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;
    ihdr[9] = 6;

    const raw = Buffer.alloc(height * (1 + width * 4));
    for (let y = 0; y < height; y += 1) {
        const rowStart = y * (1 + width * 4);
        // Filter byte 0, then random RGBA for the row.
        randomFillSync(raw, rowStart + 1, width * 4);
    }

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        // Level 0 (store): compressing 25 MB of noise gains nothing and
        // costs seconds per run.
        chunk('IDAT', deflateSync(raw, { level: 0 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}
