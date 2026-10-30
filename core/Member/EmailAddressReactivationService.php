<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Core\Member;

use Core\Journal\JournalService;
use Modules\MassMail\Api\AddressReactivationInterface;

/** One transaction for the mailbox's consent, across every participating store. */
final class EmailAddressReactivationService
{
    public function __construct(
        private MemberEmailRepository $members,
        private JournalService $journal,
        private ?AddressReactivationInterface $mailing = null
    ) {
    }

    /**
     * Called only after the member-ownership or contact-administration guard.
     * Pending addresses remain unconfirmed; bounce blocks are never lifted here.
     */
    public function reactivate(string $email, ?int $actorId): int
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MemberEmailException('Adresse e-mail invalide.');
        }
        try {
            return $this->restore($email, $actorId);
        } catch (\PDOException $error) {
            try {
                $this->journal->log(
                    'core',
                    'email_address_reactivation_failed',
                    'warning',
                    "La réactivation d'une adresse e-mail a échoué.",
                    ['error_class' => $error::class],
                    $actorId
                );
            } catch (\PDOException) {
                // An unavailable journal must not hide the original failure or the retry message.
            }
            throw new MemberEmailException("L'adresse n'a pas pu être réactivée. Réessayez plus tard.", 0, $error);
        }
    }

    private function restore(string $email, ?int $actorId): int
    {
        return $this->members->atomically(function () use ($email, $actorId): int {
            // Same lock order as unsubscribe: suppression, contacts, members.
            $mailingChanges = $this->mailing?->reactivateAddress($email, $actorId) ?? 0;
            $rows = $this->members->reactivateInactiveByEmail($email);
            foreach ($rows as $row) {
                $this->journal->log(
                    'core',
                    'member_email_reactivated',
                    'info',
                    'Adresse email réactivée',
                    ['member_id' => $row['member_id'], 'member_email_id' => $row['id']],
                    $actorId
                );
            }
            return count($rows) + $mailingChanges;
        });
    }
}
