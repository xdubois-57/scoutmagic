<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * The one way this site draws a person: a circle, holding their photo if
 * one is known and their initials if not.
 *
 * The markup lives here rather than in each template that wants one
 * because there are already five surfaces showing the same thing — the
 * member entries in the mobile menu, the connected person in the header
 * and in that menu, the author of a message in a discussion group, the
 * member page's own header — and each had grown its own version: two
 * letters of a display name in one place, two of an email address in
 * another, a filled primary circle here and a subtle one there. Reading
 * two of them side by side, it was not obvious they meant the same
 * thing.
 *
 * The no-photo look is the one the mobile menu already used for a
 * member: `bg-primary-subtle` with `text-primary`, which reads as an
 * identity rather than as a missing image.
 *
 * Rendered as markup by a Twig function (`person_avatar()`,
 * Core\View\TwigFactory) — this class only builds the string, so the
 * initials rule and the shape can be tested without a Twig environment.
 */
final class PersonAvatar
{
    /**
     * @param string $name what the initials come from, and the accessible
     *        name of the image — a person's name, never their email
     * @param int|null $fileId the photo to show, already resolved by the
     *        caller (a member's photo for the year, or an account's own)
     * @param int $size the circle's diameter in pixels
     * @param array{context: string, key: string}|null $editable when the
     *        viewer may replace this photo: the upload context and key
     *        Core\Http\Controller\UploadController re-authorises on its
     *        own — this flag decides what is OFFERED, never what is
     *        allowed
     */
    public static function render(
        string $name,
        ?int $fileId = null,
        int $size = 40,
        ?array $editable = null,
        string $extraClass = ''
    ): string {
        $size = max(16, min(256, $size));
        $classes = trim('person-avatar rounded-circle ' . $extraClass);
        $dimensions = 'width:' . $size . 'px;height:' . $size . 'px;';

        if ($fileId !== null) {
            $inner = '<img src="/files/' . $fileId . '/thumb"'
                . ' alt="' . htmlspecialchars($name, ENT_QUOTES) . '"'
                . ' class="' . htmlspecialchars($classes, ENT_QUOTES) . '"'
                . ' style="' . $dimensions . 'object-fit:cover;flex-shrink:0;">';
        } else {
            // aria-hidden with a title: the initials are a decoration of
            // a name that is written next to them in every one of this
            // component's call sites, so reading them out would announce
            // the same person twice.
            $inner = '<span class="' . htmlspecialchars($classes, ENT_QUOTES) . ' person-avatar-initials"'
                . ' style="' . $dimensions . 'font-size:' . self::fontSize($size) . 'rem;"'
                . ' title="' . htmlspecialchars($name, ENT_QUOTES) . '" aria-hidden="true">'
                . htmlspecialchars(self::initials($name), ENT_QUOTES)
                . '</span>';
        }

        if ($editable === null) {
            return $inner;
        }

        // The same wrapper member_photo()/section_photo() already use, so
        // public/assets/js/editable.js drives this one with no change of
        // its own: it reads data-context/data-key off the wrapper and
        // sends the visitor to /upload.
        return '<span class="editable-image d-inline-flex" style="' . $dimensions . '"'
            . ' data-context="' . htmlspecialchars($editable['context'], ENT_QUOTES) . '"'
            . ' data-key="' . htmlspecialchars($editable['key'], ENT_QUOTES) . '">'
            . '<span class="editable-overlay"><button type="button" class="btn btn-sm btn-outline-primary editable-edit-btn">'
            . '<i class="bi bi-camera"></i></button></span>'
            . $inner
            . '</span>';
    }

    /**
     * Two letters, from a first name and a surname when the name carries
     * both ("Marie Dupont" → "MD"), from the single word otherwise
     * ("Akéla" → "AK"). Never from an email address: every call site
     * passes a name, because "XD" is a person and "xd" is a mailbox.
     *
     * Multibyte throughout — a totem or a surname with an accent is the
     * normal case here, and `strtoupper()` would mangle "Éclaireur" into
     * something nobody writes.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
    }

    /**
     * Scaled with the circle so the two letters fill it the same way at
     * 24px and at 96px — a fixed size looks lost in a page header and
     * overflows a menu row.
     */
    private static function fontSize(int $size): string
    {
        return number_format($size * 0.4 / 16, 2, '.', '');
    }
}
