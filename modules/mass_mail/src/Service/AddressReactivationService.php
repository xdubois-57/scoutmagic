<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Journal\JournalService;
use Modules\MassMail\Api\AddressReactivationInterface;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;

final class AddressReactivationService implements AddressReactivationInterface
{
    public function __construct(
        private \PDO $pdo,
        private ContactRepository $contacts,
        private SuppressedAddressRepository $suppressed,
        private JournalService $journal
    ) {
    }

    public function reactivateAddress(string $email, ?int $actorId): int
    {
        // A miswired connection must fail before any non-atomic write.
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Address reactivation requires the shared transaction.');
        }
        $suppression = $this->suppressed->reactivate($email);
        $contacts = $this->contacts->reactivateEverywhere($email);
        if ($contacts > 0 || $suppression) {
            $this->journal->log(
                'mass_mail', 'mailing_address_reactivated', 'info',
                'Adresse réinscrite aux e-mails groupés',
                ['contacts' => $contacts, 'suppression_lifted' => $suppression], $actorId
            );
        }
        return $contacts + ($suppression ? 1 : 0);
    }
}
