// tests/e2e/support/pdf-images.js (issue #1146): the number of images a PDF
// PAINTS, which is not the number of image objects it contains.
//
// A picture with transparency is written as two objects: the colour image,
// and a greyscale soft mask it points to with `/SMask N 0 R`. On a GD that
// writes alpha, a sheet of two labels holds four `/Subtype /Image` objects.
// These documents are modelled on the object dump of the failing run: 18
// (DeviceGray) is the mask of 19 (DeviceRGB), 22 the mask of 23.
import { describe, expect, it } from 'vitest';

import { paintedImageCount } from '../e2e/support/pdf-images.js';

/** @param {Array<{id: number, mask?: number}>} images */
function pdf(images) {
    const objects = images.map((image) => {
        const space = image.mask === undefined ? '/DeviceGray' : `/DeviceRGB /SMask ${image.mask} 0 R`;

        return `${image.id} 0 obj\n<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace ${space} `
            + '/BitsPerComponent 8 /Length 1 >>\nstream\n\u0000\nendstream\nendobj\n';
    });

    return Buffer.from(`%PDF-1.7\n${objects.join('')}%%EOF\n`, 'latin1');
}

const rawCount = (buffer) => (buffer.toString('latin1').match(/\/Subtype\s*\/Image/g) || []).length;

describe('paintedImageCount', () => {
    it('counts an image without a mask as one image', () => {
        expect(paintedImageCount(pdf([{ id: 10 }, { id: 11 }]))).toBe(2);
    });

    it('does not count a mask as an image of its own', () => {
        const document = pdf([{ id: 18 }, { id: 19, mask: 18 }, { id: 22 }, { id: 23, mask: 22 }]);

        // A raw count reads the masks too: four.
        expect(rawCount(document)).toBe(4);
        expect(paintedImageCount(document)).toBe(2);
    });

    it('does not depend on whether the mask comes before or after its image', () => {
        expect(paintedImageCount(pdf([
            { id: 19, mask: 18 }, { id: 18 }, { id: 23, mask: 22 }, { id: 22 },
        ]))).toBe(2);
    });

    // The count must still see a real extra label: a sheet that prints
    // three where two are owed is the failure this scenario is for.
    it('still counts a real extra image among masks', () => {
        const document = pdf([{ id: 18 }, { id: 19, mask: 18 }, { id: 22 }, { id: 23, mask: 22 }, { id: 30 }]);

        expect(paintedImageCount(document)).toBe(3);
    });

    it('counts an extra masked label as one more', () => {
        const document = pdf([
            { id: 18 }, { id: 19, mask: 18 }, { id: 22 }, { id: 23, mask: 22 }, { id: 26 }, { id: 27, mask: 26 },
        ]);

        expect(paintedImageCount(document)).toBe(3);
    });

    it('finds no image in a document without any', () => {
        expect(paintedImageCount(Buffer.from('%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n', 'latin1'))).toBe(0);
    });

    it('reads a document handed over as bytes, as the browser test receives it', () => {
        expect(paintedImageCount(Buffer.from(pdf([{ id: 1 }])))).toBe(1);
    });
});
