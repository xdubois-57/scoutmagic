<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Journal\JournalService;

/**
 * Dated staff notes about a member — the « Notes internes » block of the
 * admin member page (`/admin/members/{id}`, `role_min: admin`).
 *
 * **Where this lives is the decision.** On the unit's own admin page, so
 * only the Staff d'Unité and the superadmin reach it, and the router's
 * RbacGuard is the whole guarantee — there is no per-section
 * compartmenting to apply here, so `SectionStaffAuthorizationService`
 * has no part in it. The cost is real and accepted: a chef de section
 * who wants to write something down about one of their own animés has
 * nowhere to do it. That is the price of keeping these notes at the
 * unit's level.
 *
 * **Dated entries, not one field.** A registration request lives a few
 * weeks; a member stays ten years and passes through several staffs. A
 * single field overwrites — the 2026 Baladins chief would silently
 * replace what the Louveteaux chief wrote in 2023, and nobody would know
 * anything had gone.
 *
 * **Any reader may edit or delete any entry.** Everyone who can read
 * these is a chef d'unité, so restricting a delete to its author buys
 * nothing and costs something real: a note written on the wrong person
 * has to be able to disappear, or somebody works around it by appending
 * "ignorer la note ci-dessus". The author and the date stay on screen —
 * that is what gives the history its meaning.
 *
 * **This is probably the most sensitive free text on the site.** None of
 * it may reach the journal: every entry below records the member id and
 * the note id and nothing else. Nor may it reach an error message, a
 * trace, an export (`MemberSearchController::export()` must never grow
 * this column), or the member and their parents — on their page, in a
 * mail-merge field, anywhere.
 */
class MemberNoteService
{
    /**
     * Long enough for a real observation, short enough that the field
     * stays a note rather than becoming a file.
     */
    public const MAX_LENGTH = 2000;

    public function __construct(
        private MemberNoteRepository $repository,
        private JournalService $journalService
    ) {
    }

    /**
     * @return MemberNote[]
     */
    public function listForMember(int $memberId): array
    {
        return $this->repository->findForMember($memberId);
    }

    /**
     * @throws MemberNoteException on an empty or over-long body
     */
    public function add(int $memberId, string $body, ?int $actorId): MemberNote
    {
        $body = $this->normalize($body);
        $id = $this->repository->create($memberId, $body, $actorId);

        // Identifiers only. The body is the thing this whole feature
        // exists to protect.
        $this->journalService->log(
            'core',
            'member_note_added',
            'info',
            'Note interne ajoutée sur un membre',
            ['member_id' => $memberId, 'note_id' => $id],
            $actorId
        );

        $note = $this->repository->findById($id);
        if ($note === null) {
            throw new MemberNoteException('La note n\'a pas pu être enregistrée.');
        }

        return $note;
    }

    /**
     * @throws MemberNoteException when the note does not exist, does not
     *         belong to this member, or the body is empty/over-long
     */
    public function update(int $memberId, int $noteId, string $body, ?int $actorId): MemberNote
    {
        $note = $this->requireOwnNote($memberId, $noteId);
        $body = $this->normalize($body);

        $this->repository->update($note->id, $body);

        $this->journalService->log(
            'core',
            'member_note_updated',
            'info',
            'Note interne modifiée sur un membre',
            ['member_id' => $memberId, 'note_id' => $note->id],
            $actorId
        );

        $updated = $this->repository->findById($note->id);
        if ($updated === null) {
            throw new MemberNoteException('La note n\'a pas pu être enregistrée.');
        }

        return $updated;
    }

    /**
     * @throws MemberNoteException when the note does not exist or does not
     *         belong to this member
     */
    public function delete(int $memberId, int $noteId, ?int $actorId): void
    {
        $note = $this->requireOwnNote($memberId, $noteId);

        $this->repository->delete($note->id);

        $this->journalService->log(
            'core',
            'member_note_deleted',
            'info',
            'Note interne supprimée sur un membre',
            ['member_id' => $memberId, 'note_id' => $note->id],
            $actorId
        );
    }

    /**
     * A note id from the URL is not a claim about which member it belongs
     * to. Re-reading it and comparing is what stops `/admin/members/12`
     * editing a note about member 40 — both readers are chefs d'unité, so
     * this is not a privilege boundary, but writing on the wrong person's
     * record is exactly the mistake the delete control exists to undo.
     *
     * @throws MemberNoteException
     */
    private function requireOwnNote(int $memberId, int $noteId): MemberNote
    {
        $note = $this->repository->findById($noteId);
        if ($note === null || $note->memberId !== $memberId) {
            throw new MemberNoteException('Cette note est introuvable.');
        }

        return $note;
    }

    /**
     * @throws MemberNoteException
     */
    private function normalize(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw new MemberNoteException('Une note ne peut pas être vide.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new MemberNoteException('Une note ne peut pas dépasser ' . self::MAX_LENGTH . ' caractères.');
        }

        return $body;
    }
}
