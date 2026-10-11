<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Phone\PhoneNumber;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ phone(member.mobile) }}` — the one way a page shows a phone number.
 *
 * It renders a real `tel:` link, so the number can be called without any
 * JavaScript (D9 of docs/chantiers/CHANTIER-telephone.md). What the number
 * is travels with it in `data-*` attributes, read by
 * public/assets/js/phone-link.js: on a touch screen a tap opens the sheet
 * offering a call, a text, WhatsApp, Signal and a copy; elsewhere the link
 * becomes plain text with a « Copier » button.
 *
 * {@see PhoneNumber} decides everything about the number. The script never
 * reads a number again: it only assembles the link each target expects
 * from the international form given here.
 *
 * A number the page has decided not to show is never passed in, and an
 * empty one renders nothing at all — no link around nothing.
 */
final class PhoneExtension extends AbstractExtension
{
    /**
     * @return array<int, TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('phone', self::render(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param string $class extra classes for the link, as a template needs
     *                      them (`text-decoration-none` in a list)
     */
    public static function render(?string $raw, string $class = ''): string
    {
        $number = PhoneNumber::parse((string) $raw);
        if ($number === null) {
            return '';
        }

        return sprintf(
            '<a href="tel:%1$s" class="%2$s" data-phone="%1$s" data-phone-display="%3$s" data-phone-kind="%4$s">%3$s</a>',
            self::escape($number->international),
            self::escape(trim('phone-link ' . $class)),
            self::escape($number->display),
            $number->kind(),
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
