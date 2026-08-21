<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Calendar;

use Modules\Rental\Booking\RentalBooking;

/**
 * From which point in a booking's life its occupancy appears on the
 * calendar (§6.30).
 *
 * Neither answer is right for everyone, which is why it is a per-asset
 * choice rather than a rule. A unit that plans around its own hall wants to
 * see pencilled-in dates as soon as they are held; a unit whose calendar is
 * read by animés wants only what is actually let, so a request that comes
 * to nothing never appears at all.
 */
enum PublishFrom: string
{
    case CONFIRMATION = 'confirmation';
    case HOLD = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMATION => 'La confirmation',
            self::HOLD => 'Le blocage des dates',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::CONFIRMATION => "Seules les locations confirmées apparaissent. Une demande sans suite ne s'affiche jamais.",
            self::HOLD => 'Les dates apparaissent dès qu\'elles sont retenues, même avant confirmation.',
        };
    }

    /**
     * Whether this booking is far enough along to be published.
     *
     * A confirmed booking always is, whatever the setting: it is the case
     * both options agree on.
     */
    public function covers(RentalBooking $booking, \DateTimeImmutable $now): bool
    {
        if ($booking->status->firmlyOccupiesTheAsset()) {
            return true;
        }

        return $this === self::HOLD && $booking->holdIsActive($now);
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }
}
