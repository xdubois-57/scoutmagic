// Isolated JavaScript unit test — jsdom only, fetch mocked. Exercises the
// REAL implementation in public/assets/js/chunked-upload.js (audit M2):
// slicing a file into sequential chunks, the wire format each chunk
// carries, resuming from the server's reported size on a 409, and error
// propagation. The IIFE only assigns window.ScoutMagicChunkedUpload, so a
// single import is enough.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/chunked-upload.js');
    return window.ScoutMagicChunkedUpload;
}

function okResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

function errorResponse(status, data) {
    return Promise.resolve({ ok: false, status, json: () => Promise.resolve(data) });
}

/** The FormData sent by fetch call #i, as a plain object (file kept as-is). */
function sentForm(i) {
    const body = global.fetch.mock.calls[i][1].body;
    const out = {};
    for (const [key, value] of body.entries()) out[key] = value;
    return out;
}

beforeEach(() => {
    document.body.innerHTML = '';
    global.fetch = vi.fn(() => okResponse({ success: true, received: 0 }));
});

describe('chunked-upload.js: newUploadId', () => {
    it('produces 32 lowercase hex chars (the server-side id shape)', async () => {
        const chunker = await boot();
        const id = chunker.newUploadId();
        expect(id).toMatch(/^[0-9a-f]{32}$/);
        expect(chunker.newUploadId()).not.toBe(id);
    });
});

describe('chunked-upload.js: uploadInChunks', () => {
    it('sends sequential chunks with offset/last flags and resolves with the final response', async () => {
        const chunker = await boot();
        global.fetch = vi.fn()
            .mockReturnValueOnce(okResponse({ success: true, received: 4 }))
            .mockReturnValueOnce(okResponse({ success: true, received: 8 }))
            .mockReturnValueOnce(okResponse({ success: true, media_id: 7 }));

        const file = new File(['0123456789'], 'video.mp4');
        const result = await chunker.uploadInChunks(file, '/gallery/3/media', {
            chunkSize: 4,
            uploadId: 'a'.repeat(32),
            csrfToken: 'tok',
            lastFields: { name: 'video.mp4' },
        });

        expect(fetch).toHaveBeenCalledTimes(3);
        expect(fetch.mock.calls[0][0]).toBe('/gallery/3/media');

        const first = sentForm(0);
        expect(first.upload_id).toBe('a'.repeat(32));
        expect(first.chunk_offset).toBe('0');
        expect(first.last).toBe('0');
        expect(first._csrf_token).toBe('tok');
        expect(first.name).toBeUndefined(); // lastFields only on the last chunk
        expect(first.file.size).toBe(4);

        expect(sentForm(1).chunk_offset).toBe('4');
        expect(sentForm(1).last).toBe('0');

        const last = sentForm(2);
        expect(last.chunk_offset).toBe('8');
        expect(last.last).toBe('1');
        expect(last.name).toBe('video.mp4');
        expect(last.file.size).toBe(2);

        expect(result.uploadId).toBe('a'.repeat(32));
        expect(result.data.media_id).toBe(7);
    });

    it('appends `fields` on every chunk', async () => {
        const chunker = await boot();
        global.fetch = vi.fn()
            .mockReturnValueOnce(okResponse({ success: true }))
            .mockReturnValueOnce(okResponse({ success: true }));

        await chunker.uploadInChunks(new File(['abcdef'], 'a.zip'), '/u', {
            chunkSize: 4, fields: { kind: 'backup' },
        });

        expect(sentForm(0).kind).toBe('backup');
        expect(sentForm(1).kind).toBe('backup');
    });

    it('resumes from the server-reported size on a 409', async () => {
        const chunker = await boot();
        global.fetch = vi.fn()
            .mockReturnValueOnce(errorResponse(409, { success: false, error: 'Fragment hors séquence (reçu : 4 octets).', received: 4 }))
            .mockReturnValueOnce(okResponse({ success: true }))
            .mockReturnValueOnce(okResponse({ success: true, media_id: 1 }));

        await chunker.uploadInChunks(new File(['0123456789'], 'a.mp4'), '/u', { chunkSize: 4 });

        // First attempt at offset 0 conflicts; the retry starts at 4.
        expect(sentForm(0).chunk_offset).toBe('0');
        expect(sentForm(1).chunk_offset).toBe('4');
        expect(sentForm(2).chunk_offset).toBe('8');
    });

    it('gives up after repeated 409s instead of looping forever', async () => {
        const chunker = await boot();
        global.fetch = vi.fn(() => errorResponse(409, { success: false, error: 'hors séquence', received: 0 }));

        await expect(chunker.uploadInChunks(new File(['0123456789'], 'a.mp4'), '/u', { chunkSize: 4 }))
            .rejects.toThrow('hors séquence');
        expect(fetch.mock.calls.length).toBeLessThanOrEqual(5);
    });

    it('rejects with the server error message on a non-409 failure', async () => {
        const chunker = await boot();
        global.fetch = vi.fn(() => errorResponse(422, { success: false, error: 'Vous ne gérez pas cette section.' }));

        await expect(chunker.uploadInChunks(new File(['abc'], 'a.jpg'), '/u', { chunkSize: 4 }))
            .rejects.toThrow('Vous ne gérez pas cette section.');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('rejects on an unparseable / generic failure', async () => {
        const chunker = await boot();
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new Error('bad json')) }));

        await expect(chunker.uploadInChunks(new File(['abc'], 'a.jpg'), '/u', { chunkSize: 4 }))
            .rejects.toThrow('Erreur réseau.');
    });

    it('reports progress after each accepted chunk', async () => {
        const chunker = await boot();
        global.fetch = vi.fn()
            .mockReturnValueOnce(okResponse({ success: true }))
            .mockReturnValueOnce(okResponse({ success: true }));
        const progress = vi.fn();

        await chunker.uploadInChunks(new File(['abcdef'], 'a.zip'), '/u', { chunkSize: 4, onProgress: progress });

        expect(progress.mock.calls).toEqual([[4, 6], [6, 6]]);
    });
});
