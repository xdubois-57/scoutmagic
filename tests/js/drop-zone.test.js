// Isolated JavaScript unit test — jsdom-simulated DOM only. jsdom has no
// real drag-and-drop, so a test builds the DragEvent's `dataTransfer`
// itself; that is exactly the shape a browser hands over. Exercises the
// REAL implementation in public/assets/js/drop-zone.js (imported below,
// never reimplemented here).
//
// This is the toolbox that replaced three hand-written copies of the
// same four listeners (upload.js, gallery.js, finance-receipt-form.js).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const ZONE = `
    <div id="zone" class="drop-zone border border-2 border-dashed">
        <span>Glissez-déposez</span>
        <input type="file" class="visually-hidden" id="picker" multiple>
    </div>`;

function fileList(...names) {
    const files = names.map((name) => new File(['x'], name));
    files.item = (i) => files[i];
    return files;
}

function dragEventWith(type, files) {
    const event = new Event(type, { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', { value: { files } });
    return event;
}

describe('drop-zone.js', () => {
    let onFiles;

    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML = ZONE;
        onFiles = vi.fn();
        await import('../../public/assets/js/drop-zone.js');
    });

    const zone = () => document.getElementById('zone');
    const picker = () => document.getElementById('picker');
    const highlighted = () => zone().classList.contains('border-primary');
    const fire = (type) => zone().dispatchEvent(new Event(type, { bubbles: true, cancelable: true }));

    describe('guards', () => {
        it('does nothing, and throws nothing, without a zone', () => {
            expect(() => window.ScoutMagicDropZone.bind(null, onFiles)).not.toThrow();
        });

        it('does nothing without a callback — the zone never decides what happens to a file', () => {
            expect(() => window.ScoutMagicDropZone.bind(zone(), null)).not.toThrow();
            zone().dispatchEvent(dragEventWith('drop', fileList('a.pdf')));
            expect(highlighted()).toBe(false);
        });

        it('binds a zone once, however many times bind() is called', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker() });
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker() });
            zone().dispatchEvent(dragEventWith('drop', fileList('a.pdf')));

            expect(onFiles).toHaveBeenCalledTimes(1);
        });
    });

    describe('the highlight', () => {
        it('lights up on dragenter and on dragover', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);

            fire('dragenter');
            expect(highlighted()).toBe(true);
            zone().classList.remove('border-primary');
            fire('dragover');
            expect(highlighted()).toBe(true);
        });

        it('goes out when the file leaves, is dropped, or the drag ends elsewhere', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);

            ['dragleave', 'dragend'].forEach((name) => {
                fire('dragover');
                fire(name);
                expect(highlighted()).toBe(false);
            });

            fire('dragover');
            zone().dispatchEvent(dragEventWith('drop', fileList('a.pdf')));
            expect(highlighted()).toBe(false);
        });

        it('cancels dragover — without that the browser opens the file instead of dropping it', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);
            const event = new Event('dragover', { bubbles: true, cancelable: true });
            zone().dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
        });
    });

    describe('dropping', () => {
        it('hands every dropped file to the callback', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);
            const files = fileList('a.pdf', 'b.jpg');
            zone().dispatchEvent(dragEventWith('drop', files));

            expect(onFiles).toHaveBeenCalledWith(files);
        });

        it('says nothing on a drop that carries no file (a dragged link, say)', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);
            zone().dispatchEvent(dragEventWith('drop', fileList()));

            expect(onFiles).not.toHaveBeenCalled();
        });

        it('survives a drop with no dataTransfer at all', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);

            expect(() => fire('drop')).not.toThrow();
            expect(onFiles).not.toHaveBeenCalled();
        });
    });

    describe('the file picker', () => {
        it('hands the picked files to the same callback', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker() });
            Object.defineProperty(picker(), 'files', { value: fileList('c.png') });
            picker().dispatchEvent(new Event('change'));

            expect(onFiles).toHaveBeenCalledTimes(1);
        });

        it('says nothing when the picker was dismissed with no file', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker() });
            Object.defineProperty(picker(), 'files', { value: fileList() });
            picker().dispatchEvent(new Event('change'));

            expect(onFiles).not.toHaveBeenCalled();
        });

        it('opens the picker on a click anywhere in the zone', () => {
            const click = vi.spyOn(picker(), 'click').mockImplementation(() => {});
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker() });
            zone().querySelector('span').dispatchEvent(new Event('click', { bubbles: true }));

            expect(click).toHaveBeenCalledTimes(1);
        });

        it('does NOT re-open it on the click that came from the input itself', () => {
            const click = vi.spyOn(picker(), 'click').mockImplementation(() => {});
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker() });
            picker().dispatchEvent(new Event('click', { bubbles: true }));

            expect(click).not.toHaveBeenCalled();
        });

        it('leaves the click alone under pickOnClick:false — the zone\'s own labels open theirs', () => {
            const click = vi.spyOn(picker(), 'click').mockImplementation(() => {});
            window.ScoutMagicDropZone.bind(zone(), onFiles, { input: picker(), pickOnClick: false });
            zone().dispatchEvent(new Event('click', { bubbles: true }));

            expect(click).not.toHaveBeenCalled();
        });

        it('still drops without an input at all', () => {
            window.ScoutMagicDropZone.bind(zone(), onFiles);
            zone().dispatchEvent(dragEventWith('drop', fileList('a.pdf')));

            expect(onFiles).toHaveBeenCalled();
        });
    });
});
