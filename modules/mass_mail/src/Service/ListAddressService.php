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
 * contact entity shared between lists: an address written here is written
 * here and nowhere else. **The unsubscribe, by contrast, is global** — it
 * flags every row holding the same address, in every list, because
 * somebody who asks that the unit stop writing to them is addressing the
 * unit rather than a list.
 *
 * **An address is added and removed, never corrected in place.** One row
 * carries a name and an address and nothing else, so a typo is one delete
 * and one add — two clicks, against a per-row editor that has to be
 * opened, saved or cancelled. Anything at scale goes through the Excel
 * round trip, which does correct names in place
 * (`ListAddressRepository::replaceForList()`), because there a file of
 * three hundred rows is being reconciled rather than one row fixed.
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
     * The cap, asked of a wholesale REPLACEMENT rather than of an
     * addition: what the file brings is what the list will hold, plus the
     * unsubscribed rows a replacement never removes — **and only those
     * the file does not carry itself**.
     *
     * That last clause is the whole point. `export()` writes the
     * unsubscribed rows into the file, so an untouched export re-imported
     * carries them back, and `replaceForList()` counts such a row as
     * unchanged rather than as an addition. Adding every unsubscribed row
     * on top of the file's own count would therefore count them twice and
     * refuse the round trip of a list sitting near the cap — which is
     * exactly the size of list this feature exists for.
     *
     * Asked before anything is written, twice — once on the analysis so
     * the refusal arrives before the confirmation is offered, and once on
     * the confirmation, which arrives in a request of its own and cannot
     * trust what an earlier one checked.
     *
     * @param array<int, array{name: ?string, email: string}> $addresses
     * @throws MailingListException
     */
    public function assertRoomForReplacement(int $listId, array $addresses): void
    {
        $max = $this->maxAddresses();
        $incoming = count($addresses);
        $surviving = $this->addressRepository->countUnsubscribedNotIn(
            $listId,
            array_map(static fn(array $a): string => $a['email'], $addresses)
        );

        if ($incoming + $surviving > $max) {
            throw new MailingListException(
                "Ce fichier porterait la liste à " . ($incoming + $surviving) . " adresses, au-delà du maximum de "
                . "{$max} (réglage « Adresses par liste de diffusion (maximum) »). Rien n'a été modifié."
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
