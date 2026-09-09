<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\MassMail\Repository\ListAddress;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;

/**
 * The addresses a custom list carries of its own — the commune, the curé,
 * the owner of the camp ground, a former member Desk never knew.
 *
 * The addresses belong to the LIST. There is no global address book and no
 * contact entity shared between lists: editing an address changes it here
 * and nowhere else. **The unsubscribe, by contrast, is global** — it flags
 * every row holding the same address, in every list, because somebody who
 * asks that the unit stop writing to them is addressing the unit rather
 * than a list.
 */
class ListAddressService
{
    public const SETTING_MAX_ADDRESSES = 'mass_mail_list_addresses_max';
    private const DEFAULT_MAX_ADDRESSES = 2000;

    /**
     * $suppressedAddressRepository is what lets a NEW row be born already
     * unsubscribed — see markIfAlreadyUnsubscribed(). Nullable so a test
     * that never writes an address does not have to build it.
     */
    public function __construct(
        private ListAddressRepository $addressRepository,
        private MailingListRepository $listRepository,
        private SettingService $settingService,
        private JournalService $journal,
        private ?SuppressedAddressRepository $suppressedAddressRepository = null
    ) {
    }

    /**
     * @return array{total: int, unsubscribed: int}
     */
    public function countForList(int $listId): array
    {
        return $this->addressRepository->countForList($listId);
    }

    /**
     * @return ListAddress[]
     */
    public function findForList(int $listId): array
    {
        return $this->addressRepository->findForList($listId);
    }

    public function findById(int $id): ?ListAddress
    {
        return $this->addressRepository->findById($id);
    }

    /**
     * @return ListAddress[] the addresses of this list that may be written to
     */
    public function findActiveForList(int $listId): array
    {
        return $this->addressRepository->findActiveForList($listId);
    }

    /**
     * @throws MailingListException on an unknown list, an invalid address, a duplicate, or the cap
     */
    public function add(int $listId, ?string $name, string $email): ListAddress
    {
        $this->requireList($listId);
        $email = $this->normalizeEmail($email);
        $name = $this->normalizeName($name);

        if ($this->addressRepository->existsInList($listId, $email)) {
            throw new MailingListException('Cette adresse figure déjà dans la liste.');
        }
        $this->assertRoomFor($listId, 1);

        $id = $this->addressRepository->create($listId, $name, $email);
        $this->journal->log(
            'mass_mail',
            'list_address_added',
            'info',
            'Adresse ajoutée à une liste de diffusion',
            // Identifiers only — an address is personal data and never
            // reaches a journal entry (SECURITY.md §11).
            ['list_id' => $listId, 'address_id' => $id]
        );

        $this->markIfAlreadyUnsubscribed($email);

        $address = $this->addressRepository->findById($id);
        \assert($address !== null);

        return $address;
    }

    /**
     * @throws MailingListException on an unknown address, an invalid address, a duplicate, or an unsubscribed row
     */
    public function edit(int $id, ?string $name, string $email): ListAddress
    {
        $address = $this->requireAddress($id);
        $email = $this->normalizeEmail($email);
        $name = $this->normalizeName($name);

        if ($this->addressRepository->existsInList($address->listId, $email, $id)) {
            throw new MailingListException('Cette adresse figure déjà dans la liste.');
        }

        $this->addressRepository->update($id, $name, $email);
        $this->journal->log(
            'mass_mail',
            'list_address_updated',
            'info',
            'Adresse de liste de diffusion modifiée',
            ['list_id' => $address->listId, 'address_id' => $id]
        );

        $this->markIfAlreadyUnsubscribed($email);

        $updated = $this->addressRepository->findById($id);
        \assert($updated !== null);

        return $updated;
    }

    /**
     * A row written for an address that already asked the unit to stop
     * writing to it is born unsubscribed.
     *
     * Refusing the write instead would be worse: the chief typing the
     * address is not the person who unsubscribed, would be told « non »
     * with no way to see why, and would try again from Excel. Accepting
     * it silently would be worse still — the screen would count an
     * address the send then refuses, which is « 312 adresses » followed
     * by « 311 envoyés » all over again. So the row exists, greyed, with
     * its mention, and says what happened.
     *
     * Idempotent by construction: the repository's own statement only
     * touches rows that are not already flagged.
     */
    private function markIfAlreadyUnsubscribed(string $email): void
    {
        if ($this->suppressedAddressRepository?->isSuppressed($email) === true) {
            $this->addressRepository->unsubscribeEverywhere($email);
        }
    }

    /**
     * @throws MailingListException on an unknown address or an unsubscribed row
     */
    public function remove(int $id): void
    {
        $address = $this->requireAddress($id);

        $this->addressRepository->delete($id);
        $this->journal->log(
            'mass_mail',
            'list_address_deleted',
            'info',
            'Adresse retirée d\'une liste de diffusion',
            ['list_id' => $address->listId, 'address_id' => $id]
        );
    }

    /**
     * The unsubscribe, applied to every list at once (the module's own
     * standing decision, and the only reason the blind index carries an
     * index of its own).
     *
     * @return int how many rows were newly unsubscribed
     */
    public function unsubscribeEverywhere(string $email): int
    {
        $count = $this->addressRepository->unsubscribeEverywhere($email);
        if ($count > 0) {
            $this->journal->log(
                'mass_mail',
                'list_address_unsubscribed',
                'info',
                'Désinscription d\'une adresse de liste de diffusion',
                ['rows' => $count]
            );
        }

        return $count;
    }

    /**
     * The cap, and the two reasons it exists — both of which the setting's
     * own description states in French, because a number nobody can
     * explain is a number somebody raises.
     */
    public function maxAddresses(): int
    {
        $value = (int) $this->settingService->get(
            self::SETTING_MAX_ADDRESSES,
            'mass_mail',
            (string) self::DEFAULT_MAX_ADDRESSES
        );

        return $value > 0 ? $value : self::DEFAULT_MAX_ADDRESSES;
    }

    /**
     * Refuses BEFORE anything is written — a cap discovered halfway
     * through a write is a list half replaced.
     *
     * @throws MailingListException
     */
    public function assertRoomFor(int $listId, int $incoming): void
    {
        $max = $this->maxAddresses();
        $current = $this->addressRepository->countForList($listId)['total'];

        if ($current + $incoming > $max) {
            throw new MailingListException(
                "Cette liste ne peut pas dépasser {$max} adresses (réglage « Adresses par liste de diffusion "
                . '(maximum) »).'
            );
        }
    }

    /**
     * @throws MailingListException
     */
    private function requireList(int $listId): void
    {
        if ($this->listRepository->findById($listId) === null) {
            throw new MailingListException('Liste introuvable.');
        }
    }

    /**
     * An unsubscribed row is not editable and not deletable: it stays
     * visible and greyed out, and it survives everything. Without that,
     * « 312 contacts » followed by « 304 envoyés » reads as a breakdown.
     *
     * @throws MailingListException
     */
    private function requireAddress(int $id): ListAddress
    {
        $address = $this->addressRepository->findById($id);
        if ($address === null) {
            throw new MailingListException('Adresse introuvable.');
        }
        if ($address->isUnsubscribed()) {
            throw new MailingListException(
                'Cette adresse est désinscrite : elle ne peut être ni modifiée ni supprimée.'
            );
        }

        return $address;
    }

    /**
     * @throws MailingListException
     */
    private function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailingListException('Adresse email invalide.');
        }

        return $email;
    }

    private function normalizeName(?string $name): ?string
    {
        $name = $name !== null ? trim($name) : '';

        return $name !== '' ? mb_substr($name, 0, 150) : null;
    }
}
