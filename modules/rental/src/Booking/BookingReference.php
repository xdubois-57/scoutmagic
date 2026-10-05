<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * A booking reference: `LOC-YYYY-XXXXXX` (issue #720, step 9).
 *
 * The year the request was MADE, then six characters drawn at random. A
 * counter (`LOC-2027-0042`) told anyone holding one reference where the
 * others were: the next one, the one before, roughly how many bookings the
 * unit had taken (issue #231). Six random characters out of 31 make a
 * neighbour no easier to find than any other value.
 *
 * **Not a secret, and never presented as one.** The reference is printed in
 * every subject line the module sends, so a renter's reply carries it back;
 * it is quoted on the phone and written on invoices. What protects a booking
 * is the tracking token, not this. A random reference only stops the
 * reference itself from being the easiest thing to guess.
 *
 * **An alphabet made to be dictated.** Neither `0`/`O` nor `1`/`I`/`L`: a
 * renter reading their reference out over the phone, or retyping it from a
 * printed invoice, cannot confuse two characters that are not both in it.
 * Upper case only on the way out; {@see self::PATTERN} accepts either case
 * on the way in, because a renter typing it by hand will not respect it.
 *
 * Uniqueness is the job of `uniq_rental_bookings_reference`, not of this
 * class: a collision is one chance in 887 million per year and pair, and the
 * caller draws again when the index refuses one.
 */
final class BookingReference
{
    /** 8 digits and 23 letters: no 0, 1, I, L or O. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const RANDOM_LENGTH = 6;

    /**
     * Either format, without its delimiters: the random one issued now, and
     * the sequential one (`LOC-2027-0042`) a booking made before this change
     * still carries and a renter may still quote. Case-insensitive — a
     * reference typed by hand arrives in whatever case the renter used.
     */
    public const PATTERN = 'LOC-\d{4}-(?:[2-9A-HJKMNP-Z]{6}|\d{1,6})';

    /**
     * @param \Closure(int, int): int $randomInt the draw, `random_int` unless
     *     a test needs a known sequence; it must be cryptographically secure
     */
    public function __construct(private readonly \Closure $randomInt)
    {
    }

    public static function secure(): self
    {
        return new self(static fn(int $min, int $max): int => random_int($min, $max));
    }

    /**
     * Whether a reference is one of the random ones — something only its
     * booking's correspondents can know — rather than a sequential one
     * (`LOC-2026-0042`) a stranger can enumerate (#231).
     *
     * A random suffix that happens to hold digits only (one draw in about
     * 3 000) is indistinguishable from a sequential number and is counted
     * as guessable: the cautious answer costs that booking nothing but the
     * model's help with a reference quoted from an unknown address.
     */
    public static function isUnguessable(string $reference): bool
    {
        return preg_match('/^LOC-\d{4}-([2-9A-HJKMNP-Z]{6})$/i', $reference, $m) === 1
            && preg_match('/[A-Z]/i', $m[1]) === 1;
    }

    /** A fresh reference for a request made at $now. */
    public function draw(\DateTimeImmutable $now): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $characters = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $characters .= self::ALPHABET[($this->randomInt)(0, $last)];
        }

        return sprintf('LOC-%04d-%s', (int) $now->format('Y'), $characters);
    }
}
