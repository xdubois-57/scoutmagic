<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

use Core\Member\MemberAddress;
use Core\Security\EncryptionService;
use Core\Service\TextNormalizerService;

/**
 * Everything the household screen needs to draw a person and an address,
 * read in two queries whatever the unit's size.
 *
 * A household is identified by an address blind index, which is by design
 * unreadable — a card headed "foyer 3f9a…" would tell a chief nothing, so
 * one readable address per household is decrypted here. This repository is
 * the only place in the module that touches an encrypted column
 * (SECURITY.md §5); its callers get plain strings and never an
 * EncryptionService.
 */
class HouseholdDetailRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Name, encoded fee category and departure state, per member_year id.
     *
     * @param int[] $memberYearIds
     * @return array<int, array{member_id: int, first_name: string, last_name: string, totem: ?string, fee_category_id: ?int, leaving: bool, leaving_marked_at: ?string}>
     */
    public function findMembers(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, member_id, first_name_encrypted, last_name_encrypted, totem_encrypted,
                    fee_category_id, leaving, leaving_marked_at
             FROM member_years
             WHERE id IN ($placeholders)"
        );
        $stmt->execute($memberYearIds);

        $members = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $members[(int) $row['id']] = [
                'member_id' => (int) $row['member_id'],
                'first_name' => $this->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
                'last_name' => $this->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
                'totem' => $row['totem_encrypted'] === null
                    ? null
                    : ($this->decrypt($row['totem_encrypted'], 'member_years.totem') ?: null),
                'fee_category_id' => $row['fee_category_id'] === null ? null : (int) $row['fee_category_id'],
                'leaving' => (bool) $row['leaving'],
                'leaving_marked_at' => $row['leaving_marked_at'] === null ? null : (string) $row['leaving_marked_at'],
            ];
        }

        return $members;
    }

    /**
     * One readable address per blind index — the first one found, since
     * every row sharing an index is by construction the same address in
     * some encoding of it.
     *
     * @param string[] $addressBlindIndexes
     * @return array<string, string> blind index => "Rue de la Station 5, 1000 Bruxelles"
     */
    public function findAddressLabels(array $addressBlindIndexes): array
    {
        $addressBlindIndexes = array_values(array_unique(array_filter(
            $addressBlindIndexes,
            static fn(string $index): bool => $index !== ''
        )));
        if ($addressBlindIndexes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($addressBlindIndexes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT address_normalized_blind_index, address_type, street_encrypted, number_encrypted,
                    box_encrypted, postal_code_encrypted, city_encrypted
             FROM member_addresses
             WHERE address_normalized_blind_index IN ($placeholders)"
        );
        $stmt->execute($addressBlindIndexes);

        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $blindIndex = (string) $row['address_normalized_blind_index'];
            if (isset($labels[$blindIndex])) {
                continue;
            }

            // Composed by MemberAddress::format() and normalised by the
            // same TextNormalizerService the |normalize_address filter uses
            // everywhere else (§8.34) — a house number typed into the
            // street field reads the same here as on the member page.
            $address = new MemberAddress(
                type: (string) ($row['address_type'] ?? ''),
                street: $this->nullIfEmpty($this->decrypt($row['street_encrypted'], 'member_addresses.street')),
                number: $this->nullIfEmpty($this->decrypt($row['number_encrypted'], 'member_addresses.number')),
                box: $this->nullIfEmpty($this->decrypt($row['box_encrypted'], 'member_addresses.box')),
                complement: null,
                postalCode: $this->nullIfEmpty($this->decrypt($row['postal_code_encrypted'], 'member_addresses.postal_code')),
                city: $this->nullIfEmpty($this->decrypt($row['city_encrypted'], 'member_addresses.city')),
                country: null,
            );

            $label = TextNormalizerService::normalizeAddress($address->format());
            $labels[$blindIndex] = $label === '' ? 'Adresse inconnue' : $label;
        }

        return $labels;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function decrypt(mixed $value, string $context): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return $this->encryption->decrypt((string) $value, $context);
        } catch (\Throwable) {
            // A row this key cannot open is a row this screen cannot draw.
            // Refusing to render the whole page over one of them would make
            // the screen useless on exactly the installation that needs it.
            return '';
        }
    }
}
