// Shared end-to-end helper: read the mail the application really sent.
//
// The throwaway instance runs in local mail mode and scripts/e2e.sh
// points PHP's sendmail_path at scripts/e2e-maildrop.php, so every
// message Core\Mail\MailService produces is written whole — headers, MIME
// parts, DKIM signature and all — into the directory named by
// E2E_MAILDROP. This module reads that directory back.
//
// Nothing here is a mail server or a parser of general MIME: it decodes
// exactly what PHPMailer produces for this application's own templates (a
// multipart/alternative of one text and one HTML part, either 8-bit,
// quoted-printable or base64) and gives a scenario the two things it ever
// needs — who the message went to, and what URL it invites them to open.
//
// Why a scenario needs this at all: a magic link, a password reset and a
// form's confirmation are each finished on the other side of an email.
// Nothing below the browser can prove that half — PHPUnit asserts against
// a MailService test double, and Vitest has no server at all — and before
// this the E2E instance pointed at an SMTP server that did not exist, so
// every one of those sends failed and no scenario could go past it.
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

/**
 * The directory scripts/e2e.sh drops messages into. Read on each call
 * rather than captured at import time so the failure below names the real
 * cause when a scenario is run outside the harness.
 *
 * @returns {string}
 */
function maildropDirectory() {
    const directory = process.env.E2E_MAILDROP;

    if (!directory) {
        throw new Error(
            'E2E_MAILDROP is not set. Run the end-to-end tests via `npm run e2e` '
            + '(scripts/e2e.sh), which creates the run\'s mailbox and exports it.',
        );
    }

    return directory;
}

/**
 * Undo a Content-Transfer-Encoding. PHPMailer picks quoted-printable for
 * anything with an accent in it — which every French template has — and
 * quoted-printable soft-wraps long lines, so a URL in the body arrives
 * split across two lines by a trailing `=`. Decoding is therefore not
 * cosmetic: without it, half of every link in this application is
 * unreadable.
 *
 * @param {string} body
 * @param {string} encoding
 * @returns {string}
 */
function decodeBody(body, encoding) {
    const normalized = encoding.trim().toLowerCase();

    if (normalized === 'base64') {
        return Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8');
    }

    if (normalized !== 'quoted-printable') {
        return body;
    }

    // Soft line breaks first (`=` at end of line — the line never
    // happened), then the =XX escapes. Decoded through a Buffer rather
    // than String.fromCharCode: a single accented character is several
    // =XX bytes in UTF-8, and decoding them one by one produces mojibake.
    const unwrapped = body.replace(/=\r?\n/g, '');
    const bytes = [];
    for (let index = 0; index < unwrapped.length; index += 1) {
        if (unwrapped[index] === '=' && /^[0-9A-Fa-f]{2}$/.test(unwrapped.slice(index + 1, index + 3))) {
            bytes.push(parseInt(unwrapped.slice(index + 1, index + 3), 16));
            index += 2;
            continue;
        }
        bytes.push(...Buffer.from(unwrapped[index], 'utf8'));
    }

    return Buffer.from(bytes).toString('utf8');
}

/**
 * Decode one RFC 2047 encoded-word run, as PHPMailer writes any header
 * carrying an accent (`Subject: =?UTF-8?B?...?=`). Left as-is when the
 * header is plain ASCII, which is most of them.
 *
 * @param {string} value
 * @returns {string}
 */
function decodeHeader(value) {
    // RFC 2047: whitespace BETWEEN two adjacent encoded-words is folding,
    // not content, and must disappear with them. Without this a subject
    // long enough to be split mid-word ("Confirmation — Week-end de
    // rentr" + "ée …", which any real article title is) comes back with a
    // space wedged into the word.
    return value.replace(/\?=[ \t]+=\?/g, '?==?')
        .replace(/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g, (_match, _charset, encoding, text) => (
            encoding.toUpperCase() === 'B'
                ? Buffer.from(text, 'base64').toString('utf8')
                : decodeBody(text.replace(/_/g, ' '), 'quoted-printable')
        ));
}

/**
 * Split one raw message into its headers and its decoded text.
 *
 * `text` is every body part concatenated and decoded, deliberately: a
 * scenario looking for a link does not care whether the template put it
 * in the plain-text alternative or the HTML one, and requiring it to know
 * would make the helper a mirror of the templates it is checking.
 *
 * @param {string} raw
 * @returns {{ to: string, subject: string, text: string, raw: string }}
 */
function parseMessage(raw) {
    const separator = raw.indexOf('\r\n\r\n') !== -1 ? '\r\n\r\n' : '\n\n';
    const boundaryIndex = raw.indexOf(separator);
    const headerBlock = boundaryIndex === -1 ? raw : raw.slice(0, boundaryIndex);

    // Unfold continuation lines (a header value indented on the next line
    // is one value) before reading anything out of them.
    const headers = new Map();
    for (const line of headerBlock.replace(/\r?\n[ \t]+/g, ' ').split(/\r?\n/)) {
        const colon = line.indexOf(':');
        if (colon > 0) {
            headers.set(line.slice(0, colon).trim().toLowerCase(), line.slice(colon + 1).trim());
        }
    }

    // Every part's payload, decoded with that part's own encoding. A
    // single-part message has no boundary and is simply its own one part.
    const body = boundaryIndex === -1 ? '' : raw.slice(boundaryIndex + separator.length);
    const boundaryMatch = /boundary="?([^";\r\n]+)"?/i.exec(headers.get('content-type') ?? '');

    const parts = boundaryMatch
        ? body.split(`--${boundaryMatch[1]}`).slice(1, -1)
        : [`Content-Transfer-Encoding: ${headers.get('content-transfer-encoding') ?? '8bit'}\n\n${body}`];

    const text = parts.map((part) => {
        const partSeparator = part.indexOf('\r\n\r\n') !== -1 ? '\r\n\r\n' : '\n\n';
        const partBoundary = part.indexOf(partSeparator);
        if (partBoundary === -1) {
            return part;
        }
        const encoding = /content-transfer-encoding:\s*([^\r\n]+)/i.exec(part.slice(0, partBoundary));

        return decodeBody(part.slice(partBoundary + partSeparator.length), encoding ? encoding[1] : '8bit');
    }).join('\n');

    return {
        to: decodeHeader(headers.get('to') ?? ''),
        subject: decodeHeader(headers.get('subject') ?? ''),
        text,
        raw,
    };
}

/**
 * Every message the run has sent so far, oldest first.
 *
 * scripts/e2e-maildrop.php names each file after a monotonic clock
 * reading, so sorting the names is sorting by send time.
 *
 * @returns {Array<{ to: string, subject: string, text: string, raw: string }>}
 */
export function readMailbox() {
    const directory = maildropDirectory();

    return readdirSync(directory)
        .filter((name) => name.endsWith('.eml'))
        .sort()
        .map((name) => parseMessage(readFileSync(path.join(directory, name), 'utf8')));
}

/**
 * Wait for a message matching `predicate` and return it.
 *
 * Polled rather than awaited on a signal, because there is none: the mail
 * is written by a separate process (PHP's sendmail child) some moments
 * after the request that triggered it answered, and no event reaches the
 * browser when it lands. The interval is short and the ceiling finite, so
 * a message that never comes fails as a timeout with a diagnostic rather
 * than as a puzzling assertion further down.
 *
 * @param {(message: {to: string, subject: string, text: string, raw: string}) => boolean} predicate
 * @param {{ timeout?: number, description?: string }} [options]
 * @returns {Promise<{ to: string, subject: string, text: string, raw: string }>}
 */
export async function waitForMail(predicate, options = {}) {
    const timeout = options.timeout ?? 10_000;
    const description = options.description ?? 'a matching message';
    const deadline = Date.now() + timeout;

    for (;;) {
        const messages = readMailbox();
        const found = messages.find(predicate);
        if (found) {
            return found;
        }

        if (Date.now() >= deadline) {
            throw new Error(
                `Timed out after ${timeout} ms waiting for ${description} in the E2E mailbox. `
                + `${messages.length} message(s) present: `
                + `${messages.map((message) => `${message.to} / ${message.subject}`).join(' | ') || '(none)'}`,
            );
        }

        await new Promise((resolve) => { setTimeout(resolve, 100); });
    }
}

/**
 * The first URL in a message whose path starts with `pathPrefix` — the
 * link the recipient is being asked to open.
 *
 * The trailing character class stops at whatever HTML or punctuation
 * follows the link, so the same call works on the text alternative and on
 * the `href` of the HTML one — and `&amp;` is turned back into `&`
 * because a magic link carries two query parameters and Twig escapes the
 * separator in the HTML alternative. Non-ASCII stops it too: a URL is
 * ASCII by construction (RFC 3986), while the plain-text alternative of a
 * French template can butt an accented word right up against the link
 * ("…79c2À tout moment…"), which the class would otherwise swallow into
 * the URL.
 *
 * @param {{ text: string }} message
 * @param {string} pathPrefix e.g. '/auth/verify'
 * @returns {string}
 */
export function linkFromMail(message, pathPrefix) {
    const match = new RegExp(`https?://[^\\s"'<>\\u0080-\\uffff]*${pathPrefix}[^\\s"'<>\\u0080-\\uffff]*`).exec(message.text);

    if (match === null) {
        throw new Error(`No ${pathPrefix} link in the message. Body was:\n${message.text}`);
    }

    return match[0].replace(/&amp;/g, '&');
}
