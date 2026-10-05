<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * A booking reference: `LOC-XXXXXX` (issue #720, step 9).
 *
 * Six characters drawn at random. A counter (`LOC-2027-0042`) told anyone
 * holding one reference where the others were: the next one, the one
 * before, roughly how many bookings the unit had taken (issue #231). Six
 * random characters make a neighbour no easier to find than any other
 * value. No year: the reference opens every subject line the module sends,
 * and four digits the renter already knows only pushed the subject itself
 * out of a phone's inbox.
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
 * **At least one digit and one letter.** Without a year, `LOC-` followed by
 * six letters is also how « loc-marche » reads, and six digits how a phone
 * number's tail does; a draw holding only one kind is drawn again, and the
 * pattern refuses it, so ordinary text is never taken for a reference. That
 * leaves about 740 million values.
 *
 * Uniqueness is the job of `uniq_rental_bookings_reference`, not of this
 * class: the caller draws again when the index refuses one.
 */
final class BookingReference
{
    /** 8 digits and 23 letters: no 0, 1, I, L or O. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const RANDOM_LENGTH = 6;

    /**
     * How many draws `draw()` makes before giving up. One draw in six holds a
     * single kind of character, so a working source needs a second draw now
     * and then and a twentieth never; a source stuck on one value would
     * otherwise loop for ever on the visitor's request.
     */
    private const MAX_DRAWS = 20;

    /**
     * A reference without its delimiters: six characters of the alphabet,
     * a digit and a letter among them. Case-insensitive — a reference typed
     * by hand arrives in whatever case the renter used. The lookaheads stay
     * inside the six characters because a seventh would break the word
     * boundary or the bracket the matcher puts around this.
     */
    public const PATTERN = 'LOC-(?=[2-9A-HJKMNP-Z]*[2-9])(?=[2-9A-HJKMNP-Z]*[A-HJKMNP-Z])[2-9A-HJKMNP-Z]{6}';

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
     * A fresh reference: six characters, drawn again until both kinds are in.
     *
     * @throws \RuntimeException when the source never yields one
     */
    public function draw(): string
    {
        for ($draw = 0; $draw < self::MAX_DRAWS; $draw++) {
            $reference = 'LOC-' . $this->characters();
            if (preg_match('/^' . self::PATTERN . '$/', $reference) === 1) {
                return $reference;
            }
        }

        throw new \RuntimeException('The random source never produced a usable booking reference.');
    }

    private function characters(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $characters = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $characters .= self::ALPHABET[($this->randomInt)(0, $last)];
        }

        return $characters;
    }
}
