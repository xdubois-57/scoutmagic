<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

use Core\Config\SettingService;
use Core\Contact\Repository\ContactCardRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberProfile;
use Core\Service\DateInput;
use Core\Service\TextNormalizerService;

/**
 * Turns a member into a {@see ContactCard} — the one place that decides
 * what a contact card contains.
 *
 * Both channels go through here: the `.vcf` a chef d'unité downloads and
 * the QR code they hold up to a phone. {@see VCardVariant} is passed to
 * {@see VCardBuilder}, never used to select fields here, so the two can
 * never diverge on what a field holds.
 *
 * Values are normalised the way the interface normalises them
 * ({@see TextNormalizerService}): Desk stores « DUPONT » and « 0475/12
 * 34 56 », and a contact card is read by a human in their phone.
 */
class ContactCardService
{
    public function __construct(
        private ContactCardRepository $repository,
        private SettingService $settingService,
        private ?MemberEmailRepository $memberEmailRepository = null,
        private ?ContactPhotoResolver $photoResolver = null
    ) {
    }

    /**
     * @param int $scoutYearId The year the profile belongs to — the
     *                         member's own latest, not necessarily the
     *                         effective one; it decides which portrait is
     *                         carried.
     */
    public function build(MemberProfile $profile, VCardVariant $variant, int $scoutYearId): ContactCard
    {
        $mainFunction = $profile->getMainFunction();

        return new ContactCard(
            memberId: $profile->memberId,
            firstName: TextNormalizerService::normalizeName($profile->firstName),
            lastName: TextNormalizerService::normalizeName($profile->lastName),
            totem: $profile->totem !== null && $profile->totem !== ''
                ? TextNormalizerService::normalizeTotem($profile->totem)
                : null,
            unitName: (string) ($this->settingService->get('site_name') ?: 'Unité scoute'),
            sectionName: $mainFunction?->sectionName,
            title: $mainFunction?->functionLabel,
            emails: $this->emails($profile),
            phones: $this->phones($profile),
            addresses: array_values($profile->addresses),
            scoutYearLabel: $profile->scoutYearLabel,
            affiliations: $this->repository->findAffiliationsForMember($profile->memberId),
            revision: $this->revision($profile->memberId),
            photoJpeg: $variant->includesPhoto()
                ? $this->photoResolver?->jpegFor($profile->memberId, $scoutYearId)
                : null,
        );
    }

    /**
     * The Desk address first, then every address the member configured and
     * confirmed. Deduplicated on the exact string: a member who added
     * their Desk address as a secondary one would otherwise appear twice.
     *
     * A « pending » address — declared but never confirmed — is not
     * exported. It is not known to belong to anybody yet, which is the
     * same reason nothing else on the site treats it as an address.
     *
     * @return list<string>
     */
    private function emails(MemberProfile $profile): array
    {
        $emails = [];
        if ($profile->email !== null && trim($profile->email) !== '') {
            $emails[] = trim($profile->email);
        }

        foreach ($this->memberEmailRepository?->findValidByMember($profile->memberId) ?? [] as $row) {
            $emails[] = trim($row->email);
        }

        return array_values(array_unique(array_filter(
            $emails,
            static fn(string $email): bool => $email !== ''
        )));
    }

    /**
     * The landline then the mobile, unlabelled — see {@see VCardBuilder}
     * for why the card carries neither the screen's « Tél. parent 1 / 2 »
     * nor a TYPE parameter.
     *
     * @return list<string>
     */
    private function phones(MemberProfile $profile): array
    {
        $phones = [];
        foreach ([$profile->phone, $profile->mobile] as $raw) {
            if ($raw === null) {
                continue;
            }
            $normalized = TextNormalizerService::normalizePhone($raw);
            if ($normalized !== '' && !in_array($normalized, $phones, true)) {
                $phones[] = $normalized;
            }
        }

        return $phones;
    }

    /**
     * Falls back to "now" for a member the revision query knows nothing
     * about, or whose stored value is not a date at all — a card whose REV
     * is the moment it was produced is honest (it says "this is what the
     * site holds right now"), where a fixed epoch would tell a client the
     * card is older than one it already has and must not be replaced.
     *
     * Read through `DateInput::fromStorage()`, never the raw
     * `DateTimeImmutable` constructor: it throws on a malformed value and
     * silently answers *now* for an empty one, so one bad column would
     * 500 the download and another would pass unnoticed (SECURITY.md
     * §35).
     */
    private function revision(int $memberId): \DateTimeImmutable
    {
        return DateInput::fromStorage($this->repository->findRevisionsForMembers([$memberId])[$memberId] ?? null)
            ?? new \DateTimeImmutable();
    }
}
