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
        return $this->change($email, $actorId, true, true);
    }

    /** Restore login validity without changing group-mail consent. */
    public function reactivateLogin(string $email, ?int $actorId): int
    {
        return $this->change($email, $actorId, true, false);
    }

    /** Restore group-mail consent without changing login eligibility. */
    public function restoreGroupMail(string $email, ?int $actorId): int
    {
        return $this->change($email, $actorId, false, true);
    }

    public function isSuppressed(string $email): bool
    {
        return $this->mailing?->isSuppressed($email) ?? false;
    }

    private function change(string $email, ?int $actorId, bool $restoreInactive, bool $restoreMailing): int
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MemberEmailException('Adresse e-mail invalide.');
        }
        try {
            return $this->restore($email, $actorId, $restoreInactive, $restoreMailing);
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

    private function restore(string $email, ?int $actorId, bool $restoreInactive, bool $restoreMailing): int
    {
        return $this->members->atomically(function () use ($email, $actorId, $restoreInactive, $restoreMailing): int {
            // Same lock order as unsubscribe: suppression, contacts, members.
            $mailingChanges = $restoreMailing ? ($this->mailing?->reactivateAddress($email, $actorId) ?? 0) : 0;
            $rows = $restoreInactive ? $this->members->reactivateInactiveByEmail($email) : [];
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
