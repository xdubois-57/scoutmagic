<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

use Core\Journal\JournalService;

/**
 * The personal signature of an account, as its owner manages it (issue
 * #926, ARCHITECTURE.md §8.125): whether there is one, since when, the
 * image itself, saving and deleting it — and erasing it when the account
 * is deactivated.
 *
 * **Every method takes the account that is asking, and only ever answers
 * about that account.** The caller establishes it from the authenticated
 * session (`AuthSession::getUserAccountId()`), never from anything a form
 * posted: there is no way through this class to read, replace or delete
 * the signature of anybody else, administrator included. What a module may
 * do with the signature when signing one of its documents is a separate
 * concern and a separate API (lot C1 of the chantier); the module's own
 * rights to sign stay the module's to decide.
 *
 * **The journal records the act and never the image**: a save, a deletion
 * or an erasure is an entry at `security` level carrying the account id
 * and nothing else — no pixel, no size, no encoded bytes.
 */
class PersonalSignatureService
{
    public function __construct(
        private PersonalSignatureRepository $repository,
        private JournalService $journal
    ) {
    }

    public function has(int $sessionAccountId): bool
    {
        return $this->repository->savedAt($sessionAccountId) !== null;
    }

    /** When the signature was saved, for « Mon compte » to say so; null when there is none. */
    public function savedAt(int $sessionAccountId): ?\DateTimeImmutable
    {
        return $this->repository->savedAt($sessionAccountId);
    }

    /** The image, for its owner's own eyes only; null when there is none. */
    public function ownImage(int $sessionAccountId): ?SignaturePng
    {
        return $this->repository->find($sessionAccountId);
    }

    /**
     * Keeps this signature for the account, replacing the one it had.
     *
     * Documents already signed are untouched: each carries the copy it was
     * signed with.
     */
    public function save(int $sessionAccountId, SignaturePng $png, \DateTimeImmutable $at): void
    {
        $this->repository->save($sessionAccountId, $png, $at);

        $this->journal->log(
            'core',
            'personal_signature_saved',
            'security',
            'Signature personnelle enregistrée',
            ['user_account_id' => $sessionAccountId],
            $sessionAccountId
        );
    }

    /**
     * Deletes the account's signature, whenever its owner asks. Returns
     * whether there was one; asking twice is not an error.
     */
    public function delete(int $sessionAccountId): bool
    {
        if (!$this->repository->delete($sessionAccountId)) {
            return false;
        }

        $this->journal->log(
            'core',
            'personal_signature_deleted',
            'security',
            'Signature personnelle supprimée',
            ['user_account_id' => $sessionAccountId],
            $sessionAccountId
        );

        return true;
    }

    /**
     * Erases the signature of an account whose access was just withdrawn
     * (`Core\Security\SuperAdminService::deactivate()`), decision Q4 of the
     * chantier: an account is never deleted, only deactivated, so without
     * this the foreign key's cascade would never run and the signature
     * would outlive the access it was kept for. Reactivating the account
     * does not bring it back.
     *
     * The one method here acting on an account that is not the caller's
     * own, which is why it takes the actor separately and is reached only
     * from the deactivation itself.
     */
    public function eraseOnDeactivation(int $deactivatedAccountId, ?int $actorAccountId): bool
    {
        if (!$this->repository->delete($deactivatedAccountId)) {
            return false;
        }

        $this->journal->log(
            'core',
            'personal_signature_erased',
            'security',
            'Signature personnelle effacée à la désactivation du compte',
            ['user_account_id' => $deactivatedAccountId],
            $actorAccountId
        );

        return true;
    }
}
