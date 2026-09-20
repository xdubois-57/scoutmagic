<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

use Core\Member\MemberAddress;

/**
 * Everything a contact card says about one member, and nothing else.
 *
 * This object is the whole definition of the card's contents. Both
 * payloads — the QR code and the downloaded `.vcf` — are rendered from it
 * by {@see VCardBuilder}, so a field cannot be present in one and missing
 * from the other; {@see VCardVariant} is the only thing that makes them
 * differ, and it can only drop the portrait and shorten the history.
 *
 * **What is deliberately absent, and must stay absent.** A member's record
 * also holds their handicap, their supplementary insurance, their gender,
 * their date of birth, their Desk identifier, their patrol, their
 * formation level and their two communication consents. None of them is a
 * property of this class, which is why none of them can reach a card by
 * accident: adding one would be a visible, deliberate edit here rather
 * than a field slipping through a generic serialisation.
 *
 * The **handicap is health data** (GDPR special category, stored encrypted
 * for that reason — `schema/core.sql`), it sits one line away from the
 * phone numbers on the same screen and inside the same
 * `Core\Member\MemberProfile`, and it is the single easiest mistake to
 * make in this whole feature. `Tests\Core\Contact\VCardBuilderTest`
 * asserts that none of those values appears in a rendered card.
 */
final class ContactCard
{
    /**
     * @param list<string>              $emails    Desk address first, then every active address the member configured.
     * @param list<string>              $phones    The Desk landline and mobile, in that order, unlabelled on purpose.
     * @param list<MemberAddress>       $addresses Main address first, then the secondary ones.
     * @param list<ContactAffiliation>  $affiliations Most recent scout year first.
     * @param ?string                   $photoJpeg Raw JPEG bytes, or null — never set for VCardVariant::Qr.
     */
    public function __construct(
        public readonly int $memberId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly string $unitName,
        public readonly ?string $sectionName,
        public readonly ?string $title,
        public readonly array $emails,
        public readonly array $phones,
        public readonly array $addresses,
        public readonly string $scoutYearLabel,
        public readonly array $affiliations,
        public readonly \DateTimeImmutable $revision,
        public readonly ?string $photoJpeg = null
    ) {
    }

    /**
     * The full name, always — never the totem on its own and never
     * `MemberProfile::getDisplayName()`, which answers the totem when
     * there is one. A contact card whose FN is « Loutre Agile » is a
     * contact nobody finds by searching their address book for the name
     * the rest of the world uses.
     */
    public function formattedName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * A stable, opaque identity for this card, derived from the member's
     * PERSISTENT id (`members.id`) and therefore identical in both
     * variants and across scout years. A client that honours UID updates
     * the card it already holds instead of filing a second one.
     */
    public function uid(): string
    {
        return 'scoutmagic-member-' . $this->memberId;
    }
}
