<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * A person's name typed into a public form (issue #1114).
 *
 * Such a name is later written into messages the unit sends in its own
 * name, DKIM-signed — the decisions and documents a manager sends, the
 * acknowledgement of an identified submission. It is a name, so it is one
 * line and carries no web address: a line break would let it lay out a
 * paragraph of its own in the message, and an address is the one thing a
 * phishing text needs. Neither belongs in a name, so refusing them costs a
 * genuine visitor nothing.
 *
 * The acknowledgement of an ANONYMOUS submission does not carry the name
 * at all (SECURITY.md §16); this rule is the bound on everything else.
 */
final class PublicFormName
{
    public const MESSAGE_LINE_BREAK = 'Un nom tient sur une seule ligne.';
    public const MESSAGE_WEB_ADDRESS = "Un nom ne peut pas contenir d'adresse web.";
    public const MESSAGE_INVISIBLE = 'Un nom ne peut pas contenir de caractère invisible.';

    /**
     * A control character (line breaks and tabs included) or a Unicode
     * line or paragraph separator.
     */
    private const LINE_BREAK = '/[\p{Cc}\p{Zl}\p{Zp}]/u';

    /**
     * An invisible format character (Unicode `Cf`: zero-width space,
     * U+FEFF, U+2060, the bidirectional overrides…). Refused rather than
     * stripped: one dropped inside `www.` or `https://` would otherwise
     * split the address the next check looks for, while the recipient's
     * mail client still shows it whole.
     */
    private const INVISIBLE = '/\p{Cf}/u';

    /**
     * A scheme (`https://`, `hxxp://`…), a bare `www.`, or a bare domain
     * on a common top-level domain (`exemple.com`, `evil.be/login`). The
     * domain list is what keeps initials and abbreviations (« J.-P. »,
     * « St.Gilles ») out of it: an address on an unlisted TLD still has to
     * spell its scheme or `www.` to be recognised.
     */
    private const WEB_ADDRESS = '~(?:[a-z][a-z0-9+.\-]*://|www\.'
        . '|(?<![\p{L}\p{N}\-])[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*'
        . '\.(?:com|net|org|info|biz|eu|be|fr|nl|lu|de|ch|uk|us|io|co|me|app|dev|xyz|top|site|online'
        . '|link|click|shop|store|live|ly|gl|to|cc|tk|ru|cn)(?![\p{L}\p{N}\-]))~iu';

    /**
     * The French sentence that refuses $name, or null when it is
     * acceptable. A string that is not valid UTF-8 is refused as a line
     * break: it cannot be read as one line of text.
     */
    public static function violation(string $name): ?string
    {
        $lineBreak = preg_match(self::LINE_BREAK, $name);
        if ($lineBreak !== 0) {
            return self::MESSAGE_LINE_BREAK;
        }

        if (preg_match(self::INVISIBLE, $name) === 1) {
            return self::MESSAGE_INVISIBLE;
        }

        if (preg_match(self::WEB_ADDRESS, $name) === 1) {
            return self::MESSAGE_WEB_ADDRESS;
        }

        return null;
    }
}
