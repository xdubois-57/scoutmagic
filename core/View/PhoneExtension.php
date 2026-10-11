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
 *
 * A value that is not one number — « 0475 12 34 56 ou 02 123 45 67 »,
 * « +33 6 12 34 56 78 (après 18h) », typed into a free field — is shown as
 * it was typed, without a link. PhoneNumber::parse() keeps only the digits,
 * so reading it would show, and offer to call, a number nobody wrote.
 */
final class PhoneExtension extends AbstractExtension
{
    /** The fewest digits of a real number, its country code included. */
    private const MIN_DIGITS = 8;

    /** The most digits E.164 allows, its country code included. */
    private const MAX_DIGITS = 15;

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
        $raw = trim((string) $raw);
        $number = PhoneNumber::parse($raw);
        if ($number === null) {
            return '';
        }
        if (!self::isOneNumber($raw, $number)) {
            return self::escape($raw);
        }

        return sprintf(
            '<a href="tel:%1$s" class="%2$s" data-phone="%1$s"'
                . ' data-phone-display="%3$s" data-phone-kind="%4$s">%3$s</a>',
            self::escape($number->international),
            self::escape(trim('phone-link ' . $class)),
            self::escape($number->display),
            $number->kind(),
        );
    }

    /**
     * Only what a number is written with — digits, spaces and + ( ) . / -
     * — and, once read, no more digits than E.164 allows (15) nor fewer
     * than any real number has (8, country code included): two numbers
     * typed one after the other read as one too long.
     */
    private static function isOneNumber(string $raw, PhoneNumber $number): bool
    {
        if (preg_match('#^[\d\s+()./-]+$#u', $raw) !== 1) {
            return false;
        }
        $digits = strlen($number->international) - 1;

        return $digits >= self::MIN_DIGITS && $digits <= self::MAX_DIGITS;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
