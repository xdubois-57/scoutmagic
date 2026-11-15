// Shared end-to-end helper: how many images a PDF paints (issue #1146).
//
// An image OBJECT is not an image on the page. A picture with transparency
// is written as two objects: the colour image, and a greyscale soft mask it
// points to with `/SMask N 0 R`. On a GD that writes alpha, a sheet of two
// labels holds four `/Subtype /Image` objects, and counting them says four.
// The masks are a valid representation — the generator is not at fault and
// is not changed to please a counter — so the count leaves out every image
// object that another one names as its mask.
//
// Reads the document as bytes, like the counter it replaces: the image
// dictionaries are written uncompressed, unlike the content streams. An
// object stored inside a compressed object stream would not be seen, which
// is the limit of the raw reading and was already the limit of the raw count.
// The PHP twin is Tests\Support\PdfImages.

/**
 * @param {Buffer} pdf
 * @returns {number}
 */
export function paintedImageCount(pdf) {
    const text = pdf.toString('latin1');

    const masks = new Set();
    for (const found of text.matchAll(/\/SMask\s+(\d+)\s+\d+\s+R/g)) {
        masks.add(found[1]);
    }

    let count = 0;
    for (const object of text.matchAll(/(?<![\d.])(\d+)\s+\d+\s+obj\b([\s\S]*?)\bendobj/g)) {
        if (/\/Subtype\s*\/Image/.test(object[2]) && !masks.has(object[1])) {
            count += 1;
        }
    }

    return count;
}
