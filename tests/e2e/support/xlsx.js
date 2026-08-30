// Shared end-to-end helper: build the smallest real .xlsx a scenario can
// hand to a file input.
//
// WHY THIS EXISTS
// ----------------------------------------------------------------------------
// One of this application's uploads accepts nothing else. A finance
// campaign is created from a spreadsheet and only from a spreadsheet
// (Modules\Finance\Service\CampaignImportService reads it with
// PhpSpreadsheet's Xlsx reader and refuses anything it cannot open as
// one), and a campaign is in turn the ONLY thing in the whole codebase
// that produces a receivable attached to a MEMBER — which is what the
// home page's "reste à payer" band reads. No CSV, no fixture file
// committed to the repository, and no shortcut around the upload can
// stand in for it.
//
// So the bytes are built here rather than pulled in as a dependency:
// `npm run e2e` must keep working with the packages package.json already
// declares, and a spreadsheet library added for one upload would be a
// dependency the whole suite carries. An .xlsx is a ZIP of five small XML
// parts, and Node has both halves of what that needs in its standard
// library (zlib.crc32, Buffer), so the writer below is about sixty lines
// and has no configuration.
//
// Deliberately minimal, and it is not a spreadsheet library: every value
// is written as an inline string, there are no formulas, no number
// formats, no second sheet, and entries are STORED rather than deflated
// (a few hundred bytes — compressing them would only add a failure mode).
// Cells arrive at the reader as text, which is exactly what the importer
// wants: it parses the amount itself ("42,50") and matches the identifier
// as a string.
import { crc32 } from 'node:zlib';

const XML_HEADER = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

/**
 * @param {string} value
 * @returns {string}
 */
function escapeXml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * A1-style reference for a zero-based column index. Twenty-six columns is
 * far more than any scenario here writes, and a spreadsheet that needs
 * "AA" needs a spreadsheet library instead.
 *
 * @param {number} index
 * @returns {string}
 */
function columnName(index) {
    if (index >= 26) {
        throw new Error('xlsxBuffer(): more than 26 columns needs a real spreadsheet library.');
    }

    return String.fromCharCode(65 + index);
}

/**
 * @param {string[][]} rows the first row being the header row
 * @returns {string}
 */
function sheetXml(rows) {
    const body = rows.map((cells, rowIndex) => {
        const columns = cells.map((value, columnIndex) => (
            `<c r="${columnName(columnIndex)}${rowIndex + 1}" t="inlineStr">`
            + `<is><t xml:space="preserve">${escapeXml(value)}</t></is></c>`
        )).join('');

        return `<row r="${rowIndex + 1}">${columns}</row>`;
    }).join('');

    return `${XML_HEADER}<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"`
        + ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        + `<sheetData>${body}</sheetData></worksheet>`;
}

/**
 * The five parts an .xlsx is made of, plus the stylesheet PhpSpreadsheet
 * expects a workbook to declare.
 *
 * @param {string[][]} rows
 * @returns {Array<{name: string, content: string}>}
 */
function parts(rows) {
    const packageRelationships = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const officeRelationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    return [
        {
            name: '[Content_Types].xml',
            content: `${XML_HEADER}<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">`
                + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                + '<Default Extension="xml" ContentType="application/xml"/>'
                + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                + '</Types>',
        },
        {
            name: '_rels/.rels',
            content: `${XML_HEADER}<Relationships xmlns="${packageRelationships}">`
                + `<Relationship Id="rId1" Type="${officeRelationships}/officeDocument" Target="xl/workbook.xml"/>`
                + '</Relationships>',
        },
        {
            name: 'xl/workbook.xml',
            content: `${XML_HEADER}<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"`
                + ` xmlns:r="${officeRelationships}">`
                + '<sheets><sheet name="Campagne" sheetId="1" r:id="rId1"/></sheets></workbook>',
        },
        {
            name: 'xl/_rels/workbook.xml.rels',
            content: `${XML_HEADER}<Relationships xmlns="${packageRelationships}">`
                + `<Relationship Id="rId1" Type="${officeRelationships}/worksheet" Target="worksheets/sheet1.xml"/>`
                + `<Relationship Id="rId2" Type="${officeRelationships}/styles" Target="styles.xml"/>`
                + '</Relationships>',
        },
        {
            name: 'xl/styles.xml',
            content: `${XML_HEADER}<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">`
                + '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                + '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                + '<borders count="1"><border/></borders>'
                + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                + '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
                + '</styleSheet>',
        },
        { name: 'xl/worksheets/sheet1.xml', content: sheetXml(rows) },
    ];
}

/**
 * One .xlsx workbook, one sheet, every cell a string.
 *
 * @param {string[][]} rows the header row first, then one array per line
 * @returns {Buffer} the file's bytes, ready for setInputFiles()
 */
export function xlsxBuffer(rows) {
    /** @type {Buffer[]} */
    const local = [];
    /** @type {Buffer[]} */
    const central = [];
    let offset = 0;

    for (const part of parts(rows)) {
        const name = Buffer.from(part.name, 'utf8');
        const data = Buffer.from(part.content, 'utf8');
        const checksum = crc32(data);

        const header = Buffer.alloc(30);
        header.writeUInt32LE(0x04034b50, 0);
        header.writeUInt16LE(20, 4); // version needed
        header.writeUInt16LE(0x0800, 6); // UTF-8 names
        header.writeUInt16LE(0, 8); // stored, not deflated
        header.writeUInt32LE(0, 10); // modification time and date
        header.writeUInt32LE(checksum, 14);
        header.writeUInt32LE(data.length, 18);
        header.writeUInt32LE(data.length, 22);
        header.writeUInt16LE(name.length, 26);
        header.writeUInt16LE(0, 28);

        const entry = Buffer.alloc(46);
        entry.writeUInt32LE(0x02014b50, 0);
        entry.writeUInt16LE(20, 4); // version made by
        entry.writeUInt16LE(20, 6); // version needed
        entry.writeUInt16LE(0x0800, 8);
        entry.writeUInt16LE(0, 10);
        entry.writeUInt32LE(0, 12);
        entry.writeUInt32LE(checksum, 16);
        entry.writeUInt32LE(data.length, 20);
        entry.writeUInt32LE(data.length, 24);
        entry.writeUInt16LE(name.length, 28);
        entry.writeUInt32LE(0, 30); // extra, comment
        entry.writeUInt32LE(0, 34); // disk number, internal attributes
        entry.writeUInt32LE(0, 38); // external attributes
        entry.writeUInt32LE(offset, 42);

        local.push(header, name, data);
        central.push(entry, name);
        offset += header.length + name.length + data.length;
    }

    const directory = Buffer.concat(central);
    const end = Buffer.alloc(22);
    const count = parts(rows).length;
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(0, 4);
    end.writeUInt16LE(0, 6);
    end.writeUInt16LE(count, 8);
    end.writeUInt16LE(count, 10);
    end.writeUInt32LE(directory.length, 12);
    end.writeUInt32LE(offset, 16);
    end.writeUInt16LE(0, 20);

    return Buffer.concat([...local, directory, end]);
}
