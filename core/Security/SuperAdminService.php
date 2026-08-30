<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Journal\JournalService;

/**
 * Granting, withdrawing, deactivating and reactivating the super-admin
 * right, with the journal entry each one owes and the refusals none of
 * them may skip.
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
        private JournalService $journalService,
        /**
         * Optional so a caller with no mail transport to hand — a test,
         * a CLI path — still gets the flag changes and the journal
         * entries, which are the parts that must never depend on a mail
         * server being reachable.
         */
        private ?SuperAdminMailer $mailer = null
    ) {
    }

    /**
     * Give the super-admin right to an address.
     *
     * The account is created when it does not exist yet — an address is
     * all it takes, and the first connection is a magic link like
     * everybody else's. There is no invitation token and no "pending"
     * state: clicking the emailed link IS the proof that the address
     * belongs to the person, so a separate acceptance step would prove
     * nothing the link does not already prove.
     *
     * Setting the flag is idempotent, so an address that is already a
     * super admin produces no duplicate and no error — it is already in
     * the state the caller asked for.
     *
     * @return array{account: UserAccount, created: bool, already: bool}
     * @throws SuperAdminException when the address is not usable
     */
    public function grant(string $email, ?int $actorId): array
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SuperAdminException("Cette adresse e-mail n'est pas valide.");
        }

        $existing = $this->userAccountRepo->findByEmail($email);

        if ($existing !== null && $existing->isSuperAdmin) {
            return ['account' => $existing, 'created' => false, 'already' => true];
        }

        if ($existing !== null) {
            $this->userAccountRepo->grantSuperAdmin($existing->id);
            $account = $this->userAccountRepo->findById($existing->id);
            \assert($account !== null);
            $created = false;
        } else {
            $account = $this->userAccountRepo->create($email, true);
            $created = true;
        }

        $this->journalService->log(
            'core',
            'super_admin_granted',
            'security',
            'Droit superadmin accordé',
            ['user_account_id' => $account->id, 'account_created' => $created],
            $actorId
        );

        $this->mailer?->sendGranted($account->email, $this->labelFor($actorId));

        return ['account' => $account, 'created' => $created, 'already' => false];
    }

    /**
     * Withdraw the super-admin right — the flag only, never the row.
     *
     * Two refusals, both here on the server. The JavaScript on the page
     * only greys a button out; a POST that arrives anyway has to be
     * refused by something that actually decides, and this is it.
     *
     * @throws SuperAdminException when refused
     */
    public function revoke(int $userAccountId, ?int $actorId): void
    {
        $this->refuseSelf($userAccountId, $actorId, 'super_admin_revoke_refused');
        $this->refuseLastOne($userAccountId, $actorId, 'super_admin_revoke_refused');

        // Read before the change: after it, this is still the same row,
        // but reading first keeps "who was emailed" and "who was changed"
        // provably the same account.
        $target = $this->userAccountRepo->findById($userAccountId);

        $this->userAccountRepo->revokeSuperAdmin($userAccountId);

        $this->journalService->log(
            'core',
            'super_admin_revoked',
            'security',
            'Droit superadmin retiré',
            ['user_account_id' => $userAccountId],
            $actorId
        );

        if ($target !== null) {
            $this->mailer?->sendRevoked($target->email);
        }
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
        $this->refuseSelf($userAccountId, $actorId, 'super_admin_deactivate_refused');
        $this->refuseLastOne($userAccountId, $actorId, 'super_admin_deactivate_refused');

        $target = $this->userAccountRepo->findById($userAccountId);

        $this->userAccountRepo->deactivate($userAccountId);

        $this->journalService->log(
            'core',
            'super_admin_deactivated',
            'security',
            'Compte superadmin désactivé',
            ['user_account_id' => $userAccountId],
            $actorId
        );

        if ($target !== null) {
            $this->mailer?->sendDeactivated($target->email);
        }
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

    /**
     * Whether « Retirer le droit » may be offered for this account — the
     * render-time twin of revoke()'s two refusals, for the same reason
     * canToggleActive() is the switch's: offering an action the server
     * will always refuse is not honest, and the POST re-checks anyway.
     */
    public function canRevoke(UserAccount $account, ?int $actorId): bool
    {
        if ($actorId !== null && $actorId === $account->id) {
            return false;
        }

        return $this->userAccountRepo->countUsableSuperAdminsExcept($account->id) > 0;
    }

    /**
     * Whether the actif/inactif switch may be offered for this account —
     * the render-time twin of the two refusals below, and deliberately
     * expressed in terms of the same rules rather than beside them.
     *
     * Not a substitute for them: deactivate() re-checks on every POST.
     * This only decides whether drawing a control the server would refuse
     * is honest, and it is not.
     *
     * Reactivation is always allowed — it can lock nobody out — so an
     * account that is already inactive keeps its switch even when it is
     * the only super admin left.
     */
    public function canToggleActive(UserAccount $account, ?int $actorId): bool
    {
        if ($actorId !== null && $actorId === $account->id) {
            return false;
        }

        if (!$account->isActive) {
            return true;
        }

        return $this->userAccountRepo->countUsableSuperAdminsExcept($account->id) > 0;
    }

    /**
     * Nobody withdraws their own access. Compared on the session's
     * account id rather than on the address: an address is a value that
     * can be re-typed, re-cased, or belong to a second row after a
     * re-key, and the identity of the person clicking is the session's,
     * not the string in the form.
     */
    private function refuseSelf(int $userAccountId, ?int $actorId, string $refusalType): void
    {
        if ($actorId !== null && $actorId === $userAccountId) {
            $this->journalRefusal($refusalType, $userAccountId, 'self', $actorId);

            throw new SuperAdminException(
                'Vous ne pouvez pas retirer ni désactiver votre propre accès superadmin. '
                . 'Demandez-le à un autre compte superadmin.'
            );
        }
    }

    /**
     * The site always keeps at least one super admin who can actually get
     * in. "The last one" is counted on the accounts that are both flagged
     * AND active, because a deactivated super admin is refused by every
     * login path — leaving only those behind would lock the unit out of
     * its own configuration.
     */
    private function refuseLastOne(int $userAccountId, ?int $actorId, string $refusalType): void
    {
        if ($this->userAccountRepo->countUsableSuperAdminsExcept($userAccountId) === 0) {
            $this->journalRefusal($refusalType, $userAccountId, 'last_super_admin', $actorId);

            throw new SuperAdminException(
                "Ce compte est le dernier accès superadmin actif du site. "
                . "Ajoutez d'abord un autre compte superadmin."
            );
        }
    }

    /**
     * A refused attempt is journaled too, at the same 'security' level as
     * the change it was refused. An attempt nobody recorded is one nobody
     * can notice repeating.
     */
    private function journalRefusal(string $type, int $userAccountId, string $reason, ?int $actorId): void
    {
        $this->journalService->log(
            'core',
            $type,
            'security',
            'Modification refusée sur un compte superadmin',
            ['user_account_id' => $userAccountId, 'reason' => $reason],
            $actorId
        );
    }

    /**
     * How the promotion mail names whoever granted the right — their
     * address, or null when nothing identified is behind the change, in
     * which case the mail falls back to an impersonal wording.
     *
     * This address goes into an EMAIL, never into a journal entry: the
     * journal bars personal data (SECURITY.md §11), a mail is by
     * definition addressed to someone and telling them who gave them an
     * administrative access is the one question they will actually have.
     */
    private function labelFor(?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        return $this->userAccountRepo->findById($actorId)?->email;
    }
}
