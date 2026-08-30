<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Journal\JournalService;

/**
 * Deactivating and reactivating a super-admin account, with the journal
 * entry each one owes.
 *
 * The repository writes the columns; this is where the act is recorded.
 * It exists as a service rather than as controller code because a
 * Controller holds no business logic (ARCHITECTURE.md's layering), and
 * because the journal entry has to happen wherever the change does — a
 * withdrawal of access that leaves no trace is the one kind an operator
 * cannot audit afterwards.
 *
 * The entries carry the `user_account_id` and nothing else. Never the
 * address: an email is personal data and the journal is not where it
 * belongs (AGENTS.md § Security checklist #4, SECURITY.md §11).
 */
class SuperAdminService
{
    public function __construct(
        private UserAccountRepository $userAccountRepo,
        private JournalService $journalService
    ) {
    }

    /**
     * Withdraw access. The repository's deactivate() both clears
     * is_active and stamps sessions_valid_from, so a session already open
     * falls on its next request.
     *
     * $actorId is the account that performed it, for the journal entry's
     * user column — null when nothing identified is behind the change.
     */
    public function deactivate(int $userAccountId, ?int $actorId): void
    {
        $this->userAccountRepo->deactivate($userAccountId);

        $this->journalService->log(
            'core',
            'super_admin_deactivated',
            'security',
            'Compte superadmin désactivé',
            ['user_account_id' => $userAccountId],
            $actorId
        );
    }

    /**
     * Give access back. Sessions revoked by the deactivation stay revoked
     * — see UserAccountRepository::reactivate().
     */
    public function reactivate(int $userAccountId, ?int $actorId): void
    {
        $this->userAccountRepo->reactivate($userAccountId);

        $this->journalService->log(
            'core',
            'super_admin_reactivated',
            'security',
            'Compte superadmin réactivé',
            ['user_account_id' => $userAccountId],
            $actorId
        );
    }
}
