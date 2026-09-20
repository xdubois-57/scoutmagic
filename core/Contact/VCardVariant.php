<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

/**
 * Which of the two payload variants of a contact card to produce.
 *
 * There is ONE definition of what a contact card contains
 * ({@see ContactCardService}) and one renderer ({@see VCardBuilder}); this
 * enum is the only thing that makes the two payloads differ, so a field can
 * never drift between the QR code and the downloaded file.
 *
 * The differences are exactly two, and both exist because a QR code is a
 * few hundred bytes of budget rather than a file:
 *
 * - the portrait is left out of {@see self::Qr} (a JPEG would not fit in a
 *   scannable symbol at all);
 * - the affiliation history is cut to {@see self::QR_AFFILIATION_LIMIT}
 *   lines in {@see self::Qr}, and complete everywhere else.
 *
 * The QR code does NOT say that it truncated the history. That is a
 * decision of the requester, not an oversight: the card is a way to get
 * somebody into a phone, and a « historique tronqué » line in a contact's
 * notes would outlive the scan and read as a fact about the person.
 */
enum VCardVariant
{
    /** Scanned from a screen: no portrait, history capped. */
    case Qr;

    /** Downloaded as a file or served over CardDAV: portrait, full history. */
    case Full;

    /** How many affiliation lines {@see self::Qr} keeps. */
    public const QR_AFFILIATION_LIMIT = 5;

    public function includesPhoto(): bool
    {
        return $this === self::Full;
    }

    /** Null means "every affiliation". */
    public function affiliationLimit(): ?int
    {
        return $this === self::Qr ? self::QR_AFFILIATION_LIMIT : null;
    }
}
