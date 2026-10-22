<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Security\UserAccountRepository;

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
 * do with the signature when signing one of its documents is the second
 * half of this class (lot C1, « Signing a document » below); the module's
 * own rights to sign stay the module's to decide.
 *
 * **The journal records the act and never the image**: a save, a deletion
 * or an erasure is an entry at `security` level carrying the account id
 * and nothing else — no pixel, no size, no encoded bytes.
 */
class PersonalSignatureService
{
    public function __construct(
        private PersonalSignatureRepository $repository,
        private JournalService $journal,
        /**
         * Reads the account's own name, printed under its signature on a
         * document. Null signs without a name, which the module then
         * supplies its own way.
         */
        private ?UserAccountRepository $accounts = null
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

    // ── Signing a document (lot C1) ─────────────────────────────────────
    //
    // A module offering to sign one of its documents opens the shared
    // dialog (partials/signature_dialog.html.twig), reads what it posted
    // (SignatureSubmission::fromRequest()) and calls, in this order:
    //
    //   1. forSigning() — BEFORE any lock of its own: decoding an image is
    //      CPU work, and done under a lock it would hold every other
    //      manager waiting;
    //   2. rememberIfAsked() — INSIDE its own transaction, beside the
    //      writes that file the signed document, so that a signing that
    //      fails leaves nothing remembered: « sign and remember » is one
    //      act, or nothing;
    //   3. recordSigning() — once that transaction has committed.

    /** Said when the dialog posted neither a drawing nor an image. */
    public const NOTHING_TO_SIGN_WITH = "Tracez votre signature ou importez-en l'image.";

    /** Said when the account's signature was deleted between the dialog opening and its confirmation. */
    public const STORED_SIGNATURE_GONE = "Votre signature enregistrée n'existe plus : rien n'a été signé. "
        . 'Rechargez la page pour tracer ou importer une signature.';

    /** The longest name a witness may type under their signature. */
    public const MAX_WITNESS_NAME_LENGTH = 120;

    /** The field the dialog's witness mode posts the typed name in. */
    public const WITNESS_NAME_FIELD = 'signer_name';

    /**
     * The signature the session's account signs this document with: the
     * one it keeps, when the dialog said so, or the one traced or imported
     * there, decoded and re-encoded now.
     *
     * Nothing is written: a new signature is kept only by
     * `rememberIfAsked()`, inside the module's own transaction.
     *
     * @throws SignatureException when there is nothing usable to sign with
     */
    public function forSigning(int $sessionAccountId, SignatureSubmission $submission): AccountSignature
    {
        if ($submission->useStored) {
            $png = $this->repository->find($sessionAccountId);
            if ($png === null) {
                throw new SignatureException(self::STORED_SIGNATURE_GONE);
            }

            return new AccountSignature(
                $sessionAccountId,
                $png,
                SignatureOrigin::Stored,
                false,
                $this->nameOf($sessionAccountId)
            );
        }

        if ($submission->drawing !== null) {
            $png = SignatureImage::fromDataUrl($submission->drawing);
            $origin = SignatureOrigin::DrawnNow;
        } elseif ($submission->uploadedPath !== null) {
            $png = SignatureImage::fromUploadedFile($submission->uploadedPath);
            $origin = SignatureOrigin::ImportedNow;
        } else {
            throw new SignatureException(self::NOTHING_TO_SIGN_WITH);
        }

        return new AccountSignature(
            $sessionAccountId,
            $png,
            $origin,
            $submission->rememberRequested,
            $this->nameOf($sessionAccountId)
        );
    }

    /**
     * Keeps the signature in the account when, and only when, its owner
     * ticked « Mémoriser » for a signature given in the dialog. Returns
     * whether it did.
     *
     * One upsert, and **no transaction of its own**: the module runs this
     * inside the transaction that files the signed document, so a signing
     * that fails rolls the remembering back with it. The journal line
     * that a save writes is written in that same transaction, and goes
     * with it.
     */
    public function rememberIfAsked(AccountSignature $signature, \DateTimeImmutable $at): bool
    {
        if (!$signature->willBeRemembered()) {
            return false;
        }

        $this->save($signature->accountId, $signature->png, $at);

        return true;
    }

    /**
     * The signature of somebody without an account, traced on the spot
     * (ticket §6a): a drawing — never an import, never the signature an
     * account keeps — and the name they typed, 1 to 120 characters.
     * Nothing is kept.
     *
     * @throws SignatureException
     */
    public function witness(SignatureSubmission $submission, string $typedName): WitnessSignature
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $typedName));
        if ($name === '') {
            throw new SignatureException('Indiquez le nom de la personne qui signe.');
        }
        if (mb_strlen($name) > self::MAX_WITNESS_NAME_LENGTH) {
            throw new SignatureException(
                'Le nom de la personne qui signe ne peut pas dépasser ' . self::MAX_WITNESS_NAME_LENGTH . ' caractères.'
            );
        }
        if ($submission->useStored) {
            throw new SignatureException('Cette signature se trace sur place : une signature enregistrée ne convient pas.');
        }
        if ($submission->uploadedPath !== null) {
            throw new SignatureException('Cette signature se trace sur place : une image importée ne convient pas.');
        }
        if ($submission->drawing === null) {
            throw new SignatureException('Tracez la signature dans le cadre.');
        }

        return new WitnessSignature(SignatureImage::fromDataUrl($submission->drawing), $name);
    }

    /**
     * The witness dialog, read and turned into a signature in one call
     * (ticket §6a; the brick #989 signs the inventory with): the drawing
     * and the name posted as `signer_name` by the dialog's witness mode
     * (`partials/signature_dialog.html.twig`, `witness: true`). A file, the
     * stored mode and the « Mémoriser » box are not read as a choice —
     * an import or the stored mode is refused by {@see witness()} — and
     * no account id is read at all: a witness has none. Called before
     * the module takes any lock, like {@see forSigning()}; it writes
     * nothing, to the database or the journal.
     *
     * @throws SignatureException
     */
    public function witnessFromRequest(Request $request): WitnessSignature
    {
        return $this->witness(
            SignatureSubmission::fromRequest($request, true, false),
            (string) $request->getBody(self::WITNESS_NAME_FIELD, '')
        );
    }

    /**
     * The act of signing, in the journal: which document, of which module,
     * by which account, when, and whether the signature was the account's
     * or given for it — **never a pixel**, nor the image's size. Called
     * once the module's transaction has committed, so it records a
     * document that exists.
     *
     * **A failure here never fails the signing.** The document is already
     * signed and committed by then: letting the journal's error escape
     * would show the signer an error for a signature that stands, and a
     * retry would find nothing left to sign. It goes to `error_log()`
     * instead, with the same ids and no pixel, so the missing line is
     * still visible to whoever reads the server's log.
     *
     * The account is the signature's, i.e. the session's.
     */
    public function recordSigning(
        AccountSignature $signature,
        string $module,
        string $documentType,
        int $documentId,
        ?int $ownerEntityId,
        \DateTimeImmutable $at
    ): void {
        try {
            $this->journal->log(
                'core',
                'personal_signature_document_signed',
                'security',
                'Document signé avec la signature personnelle du compte',
                [
                    'module' => $module,
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'owner_entity_id' => $ownerEntityId,
                    'origin' => $signature->origin->value,
                    'remembered' => $signature->willBeRemembered(),
                    'signed_at' => $at->format('Y-m-d H:i:s'),
                ],
                $signature->accountId
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'ScoutMagic: the journal line of a signed document was not written'
                    . ' (module %s, %s #%d, account #%d): %s',
                $module,
                $documentType,
                $documentId,
                $signature->accountId,
                $e::class
            ));
        }
    }

    /** The account's own first and last name, or null when it has none. */
    private function nameOf(int $accountId): ?string
    {
        $names = $this->accounts?->findNamesByIds([$accountId])[$accountId] ?? null;
        if ($names === null) {
            return null;
        }

        $full = trim(trim((string) $names['first_name']) . ' ' . trim((string) $names['last_name']));

        return $full === '' ? null : $full;
    }
}
