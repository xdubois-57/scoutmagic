// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/news-form-builder.js (imported below, never
// reimplemented here) via the namespaced
// `globalThis.ScoutMagicNewsFormBuilderInternals` test seam that file
// exposes for exactly this purpose — see the comment at the bottom of it.
//
// The focus is the client-side HTML sanitizer. Per that file's own comment
// it is a mirror of Core\Security\HtmlSanitizer's allowlist, and it exists
// because the rich-text editor round-trips whatever contenteditable
// produces (paste, drag-drop) straight back into innerHTML on every
// re-render, closing a DOM-to-DOM loop the server-side pass alone does not
// see. A regression here is a stored-XSS path, so the cases below are
// written as an allowlist specification rather than as a tour of the
// implementation.
import { beforeEach, describe, expect, it } from 'vitest';
// The real site-wide toolboxes, loaded by base.html.twig before every page
// script — same order here (static side-effect imports run in order).
import '../../public/assets/js/api.js';
import '../../public/assets/js/toast.js';
import '../../public/assets/js/news-form-builder.js';

const nfb = globalThis.ScoutMagicNewsFormBuilderInternals;
const { sanitizeHtml, isSafeUrlScheme } = nfb;

describe('news-form-builder.js: isSafeUrlScheme()', () => {
    it('allows a URL with no scheme at all', () => {
        ['/local/page', '#fragment', '?q=1', 'relative.html', ''].forEach((v) => {
            expect(isSafeUrlScheme(v)).toBe(true);
        });
    });

    it('allows every scheme on the allowlist', () => {
        expect(nfb.URL_SCHEME_ALLOWLIST).toEqual(['http', 'https', 'mailto', 'tel']);
        ['http://x.test', 'https://x.test', 'mailto:a@b.test', 'tel:+3212345'].forEach((v) => {
            expect(isSafeUrlScheme(v)).toBe(true);
        });
    });

    it('rejects javascript:', () => {
        expect(isSafeUrlScheme('javascript:alert(1)')).toBe(false);
    });

    it('rejects javascript: regardless of case', () => {
        ['JavaScript:alert(1)', 'JAVASCRIPT:alert(1)', 'jAvAsCrIpT:alert(1)'].forEach((v) => {
            expect(isSafeUrlScheme(v)).toBe(false);
        });
    });

    it('rejects javascript: with embedded tabs, newlines or carriage returns', () => {
        ['java\tscript:alert(1)', 'java\nscript:alert(1)', 'java\rscript:alert(1)',
         'jav\ta\nscri\rpt:alert(1)'].forEach((v) => {
            expect(isSafeUrlScheme(v)).toBe(false);
        });
    });

    it('rejects javascript: behind leading whitespace', () => {
        expect(isSafeUrlScheme('   javascript:alert(1)')).toBe(false);
        expect(isSafeUrlScheme('\n\t javascript:alert(1)')).toBe(false);
    });

    // The allowlist's whole point: a denylist would have to enumerate these.
    it('rejects every other scheme, not just the well-known dangerous ones', () => {
        ['data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)',
         'file:///etc/passwd', 'blob:https://x.test/abc', 'ftp://x.test',
         'ws://x.test', 'about:blank', 'chrome://settings',
         'x-custom-scheme:whatever'].forEach((v) => {
            expect(isSafeUrlScheme(v)).toBe(false);
        });
    });

    it('coerces a non-string argument instead of throwing', () => {
        expect(() => isSafeUrlScheme(null)).not.toThrow();
        expect(() => isSafeUrlScheme(undefined)).not.toThrow();
        expect(() => isSafeUrlScheme(42)).not.toThrow();
    });
});

describe('news-form-builder.js: sanitizeHtml() — tags stripped with their content', () => {
    it('declares the expected strip list', () => {
        expect(nfb.HTML_SANITIZER_STRIP_WITH_CONTENT)
            .toEqual(['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select']);
    });

    it('removes a script element and its content entirely', () => {
        expect(sanitizeHtml('<script>alert(1)</script>')).toBe('');
        expect(sanitizeHtml('before<script>alert(1)</script>after')).toBe('beforeafter');
    });

    it('removes style, iframe, object, embed, form, textarea and select with content', () => {
        expect(sanitizeHtml('<style>body{display:none}</style>')).toBe('');
        expect(sanitizeHtml('<iframe src="https://evil.test"></iframe>')).toBe('');
        expect(sanitizeHtml('<object data="x.swf"></object>')).toBe('');
        expect(sanitizeHtml('<embed src="x.swf">')).toBe('');
        expect(sanitizeHtml('<textarea>hidden</textarea>')).toBe('');
        expect(sanitizeHtml('<select><option>a</option></select>')).toBe('');
    });

    it('removes a nested script inside otherwise allowed markup', () => {
        expect(sanitizeHtml('<p>keep<script>alert(1)</script></p>')).toBe('<p>keep</p>');
    });
});

describe('news-form-builder.js: sanitizeHtml() — non-allowlisted tags unwrapped', () => {
    it('declares the expected tag allowlist', () => {
        expect(Object.keys(nfb.HTML_SANITIZER_ALLOWED_TAGS).sort()).toEqual(
            ['a', 'b', 'blockquote', 'br', 'em', 'h2', 'h3', 'h4', 'i', 'img', 'li', 'ol', 'p', 'strong', 'u', 'ul'].sort());
    });

    it('drops a div but keeps its children', () => {
        expect(sanitizeHtml('<div><p>keep</p></div>')).toBe('<p>keep</p>');
    });

    it('drops a span but keeps its text', () => {
        expect(sanitizeHtml('<span>text</span>')).toBe('text');
    });

    it('recursively unwraps nested non-allowlisted tags', () => {
        expect(sanitizeHtml('<div><section><article><b>deep</b></article></section></div>'))
            .toBe('<b>deep</b>');
    });

    // The unwrap branch moves children before the node, then rewinds the walk
    // to the first moved child so the moved subtree is itself sanitized. That
    // pointer manipulation is easy to break in a refactor.
    it('still sanitizes content that was moved up by an unwrap', () => {
        expect(sanitizeHtml('<div><script>alert(1)</script></div>')).toBe('');
        expect(sanitizeHtml('<div><span onclick="alert(1)">x</span></div>')).toBe('x');
        expect(sanitizeHtml('<div><a href="javascript:alert(1)">x</a></div>')).toBe('<a>x</a>');
    });

    it('unwraps an empty non-allowlisted tag to nothing', () => {
        expect(sanitizeHtml('<div></div>')).toBe('');
    });

    it('preserves sibling order across an unwrap', () => {
        expect(sanitizeHtml('<b>1</b><div>2</div><i>3</i>')).toBe('<b>1</b>2<i>3</i>');
    });

    it('keeps every allowlisted tag as-is', () => {
        const html = '<h2>t</h2><h3>t</h3><h4>t</h4><p>t</p><strong>t</strong><em>t</em>'
            + '<u>t</u><b>t</b><i>t</i><ul><li>t</li></ul><ol><li>t</li></ol><blockquote>t</blockquote>';
        expect(sanitizeHtml(html)).toBe(html);
    });
});

describe('news-form-builder.js: sanitizeHtml() — attributes', () => {
    it('strips every on* event handler', () => {
        expect(sanitizeHtml('<p onclick="alert(1)">x</p>')).toBe('<p>x</p>');
        expect(sanitizeHtml('<p onmouseover="alert(1)" onfocus="alert(1)">x</p>')).toBe('<p>x</p>');
        expect(sanitizeHtml('<p ONCLICK="alert(1)">x</p>')).toBe('<p>x</p>');
    });

    // Mutation-checked: removing the `name.indexOf('on') === 0` guard from
    // sanitizeHtmlAttributes() breaks NO black-box test, because no allowlist
    // currently contains an attribute starting with "on", so the per-tag
    // allowlist rejects every handler on its own. The guard is therefore
    // defence-in-depth against a future allowlist edit — and untestable
    // through sanitizeHtml() alone. This test drives sanitizeHtmlAttributes()
    // directly against a doctored allowlist so that layer is pinned on its
    // own merits and cannot be deleted as "redundant" without a failure.
    it('strips an on* handler even when an allowlist would admit it', () => {
        const allowed = nfb.HTML_SANITIZER_ALLOWED_TAGS;
        const original = allowed.p;
        allowed.p = ['onclick', 'title'];
        try {
            const el = document.createElement('p');
            el.setAttribute('onclick', 'alert(1)');
            el.setAttribute('title', 't');

            nfb.sanitizeHtmlAttributes(el, 'p');

            expect(el.hasAttribute('onclick')).toBe(false);
            expect(el.getAttribute('title')).toBe('t');
        } finally {
            allowed.p = original;
        }
    });

    it('strips attributes that are not allowlisted for that tag', () => {
        expect(sanitizeHtml('<p class="c" id="i" style="color:red">x</p>')).toBe('<p>x</p>');
        // href is allowed on <a>, never on <p>.
        expect(sanitizeHtml('<p href="https://x.test">x</p>')).toBe('<p>x</p>');
    });

    it('keeps the allowlisted attributes on <a>', () => {
        expect(sanitizeHtml('<a href="https://x.test" title="t">x</a>'))
            .toBe('<a href="https://x.test" title="t">x</a>');
    });

    it('drops an unsafe href but keeps the anchor and its text', () => {
        expect(sanitizeHtml('<a href="javascript:alert(1)">click</a>')).toBe('<a>click</a>');
        expect(sanitizeHtml('<a href="data:text/html,<script>alert(1)</script>">click</a>'))
            .toBe('<a>click</a>');
    });

    it('forces rel="noopener noreferrer" on target="_blank"', () => {
        const out = sanitizeHtml('<a href="https://x.test" target="_blank">x</a>');
        expect(out).toContain('rel="noopener noreferrer"');
        expect(out).toContain('target="_blank"');
    });

    it('overwrites an attacker-supplied rel on target="_blank"', () => {
        const out = sanitizeHtml('<a href="https://x.test" target="_blank" rel="opener">x</a>');
        expect(out).toContain('rel="noopener noreferrer"');
        expect(out).not.toContain('rel="opener"');
    });

    it('does not add rel when target is not _blank', () => {
        expect(sanitizeHtml('<a href="https://x.test">x</a>')).not.toContain('rel=');
    });
});

describe('news-form-builder.js: sanitizeHtml() — comments and edge inputs', () => {
    it('removes comment nodes', () => {
        expect(sanitizeHtml('<!-- secret -->text')).toBe('text');
        expect(sanitizeHtml('<p><!-- c -->x</p>')).toBe('<p>x</p>');
    });

    it('returns an empty string for empty, null and undefined input', () => {
        expect(sanitizeHtml('')).toBe('');
        expect(sanitizeHtml(null)).toBe('');
        expect(sanitizeHtml(undefined)).toBe('');
    });

    it('escapes bare text rather than dropping it', () => {
        expect(sanitizeHtml('plain text')).toBe('plain text');
        expect(sanitizeHtml('a &amp; b')).toBe('a &amp; b');
    });

    it('is idempotent — sanitizing its own output changes nothing', () => {
        const inputs = [
            '<p onclick="alert(1)">x</p>',
            '<div><a href="javascript:alert(1)" target="_blank">x</a></div>',
            '<span>a<script>b</script>c</span>',
            '<!-- c --><h2>t</h2>',
        ];
        inputs.forEach((html) => {
            const once = sanitizeHtml(html);
            expect(sanitizeHtml(once)).toBe(once);
        });
    });

    it('does not execute or load anything while sanitizing (DOMParser has no browsing context)', () => {
        // An <img onerror> would fire on a live element; a script would run.
        // Both must be inert here. <img> is now allowed (with a safe src), so
        // the image survives — but its onerror handler and the script must be
        // gone from the output.
        const out = sanitizeHtml('<img src="x" onerror="globalThis.__pwned = true"><script>globalThis.__pwned = true</script>');
        expect(globalThis.__pwned).toBeUndefined();
        expect(out).not.toContain('onerror');
        expect(out).not.toContain('script');
        expect(out).toContain('<img');
        expect(out).toContain('src="x"');
    });
});

describe('news-form-builder.js: visibility and AI-button predicates', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('treats a missing visibility control as public', () => {
        expect(nfb.isPublicAccess()).toBe(true);
    });

    it('treats public and direct_link as public access, everything else as not', () => {
        const cases = { public: true, direct_link: true, identified: false, members: false };
        Object.entries(cases).forEach(([value, expected]) => {
            document.body.innerHTML = `<input type="radio" name="visibility" value="${value}" checked>`;
            expect(nfb.isPublicAccess()).toBe(expected);
        });
    });

    it('reports no title or content on an empty form', () => {
        expect(nfb.hasTitleOrContent()).toBe(false);
    });

    it('reports content once the title has a non-whitespace value', () => {
        document.body.innerHTML = '<input name="title" value="   ">';
        expect(nfb.hasTitleOrContent()).toBe(false);

        document.querySelector('input[name="title"]').value = 'A title';
        expect(nfb.hasTitleOrContent()).toBe(true);
    });
});
