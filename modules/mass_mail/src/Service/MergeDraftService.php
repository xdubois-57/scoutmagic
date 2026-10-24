<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\Role;
use Core\View\SectionPickerHelper;
use Modules\MassMail\Api\MassMailDraftInterface;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Email;

/**
 * Builds a mail-merge audience from rows another module already has, then
 * a draft that references it (ARCHITECTURE.md §8.71).
 *
 * Everything here already existed and was tested — an audience, a
 * mail_merge draft, the composer. What was missing was a way in: the only
 * one was importing an .xlsx by hand, so a chief who wanted to write to a
 * form's respondents exported to Excel, opened the mail merge, re-imported
 * the file they had just downloaded, and only then started writing.
 *
 * The audience is stored exactly as an imported one, on purpose. It is the
 * same table, the same encryption, the same composer, the same
 * `Task\PurgeMergeAudiencesHandler` retention (18 months after the last
 * send, 7 days for an orphan) — a second mechanism alongside it would be a
 * second thing to get wrong, and a second place to forget when the
 * retention rules change.
 */
class MergeDraftService implements MassMailDraftInterface
{
    private const SHEET_NAME = 'Réponses';

    public function __construct(
        private MassMailService $massMailService,
        private AudienceRepository $audienceRepository,
        private MassMailAccessService $accessService,
        private MemberService $memberService,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * @param string[] $columns
     * @param list<array{email?: string, member_id?: ?int, values: array<string, string>}> $rows
     * @throws MassMailException
     */
    public function createMergeDraft(
        string $label,
        string $subject,
        array $columns,
        array $rows,
        string $actorRole,
        string $actorEmail,
        ?int $actorAccountId,
        ?string $bodyHtml = null
    ): string {
        $rows = $this->keepAddressableRows($rows);
        if ($rows === []) {
            throw new MassMailException('Personne à qui écrire : aucune ligne ne porte un membre ou une adresse.');
        }

        $authorization = $this->buildAuthorization($actorRole, $actorEmail);
        $senderSectionId = $this->resolveSenderSection($actorEmail);
        if ($senderSectionId === null) {
            throw new MassMailException(
                "Aucune section n'est configurée : impossible de choisir une section expéditrice."
            );
        }

        $audienceId = $this->audienceRepository->createAudience(
            $label,
            self::SHEET_NAME,
            $columns,
            count($rows),
            $actorAccountId
        );

        $rowIndex = 2; // an imported audience numbers from the first data line
        foreach ($rows as $row) {
            // member_id is written only when the CALLER gave one: it is
            // never looked up from the address. A form's respondent
            // answered with one precise address, the one they expect to
            // hear back on, and resolving a member from it would send to
            // every address that member has ever registered.
            $this->audienceRepository->createRow(
                $audienceId,
                $rowIndex,
                $row['member_id'],
                $row['email'],
                $row['values']
            );
            $rowIndex++;
        }

        // createDraft() re-checks the authorization built above — this
        // service asserts nothing itself, so there is exactly one place
        // that decides who may send from where.
        $email = $this->massMailService->createDraft(
            $subject,
            $bodyHtml ?? '',
            $senderSectionId,
            Email::LIST_TYPE_MAIL_MERGE,
            null,
            null,
            [],
            $actorAccountId,
            $authorization,
            $audienceId
        );

        // The draft's edit URL, and it really is one now: `/mass-mail/{id}`
        // is the composition PAGE (Controller\MassMailController::show()).
        // It used to be a JSON endpoint, so this contract — « the draft's
        // edit URL » — sent a chief straight to a raw payload. The feature
        // had been written against a page that did not exist.
        return '/mass-mail/' . $email->id;
    }

    /**
     * The rows that can reach somebody, in the caller's order, and none of
     * them merged with another: one row is one communication, so two
     * answers from one family address are two messages, each with its own
     * values (ARCHITECTURE.md §8.71).
     *
     * A row with a member keeps no address of its own — exactly as an
     * imported row with a Tiers, whose Email cell is ignored for delivery
     * — so the freeze has one meaning for both. A row with neither is
     * dropped rather than mailed.
     *
     * @param list<array{email?: string, member_id?: ?int, values: array<string, string>}> $rows
     * @return list<array{email: ?string, member_id: ?int, values: array<string, string>}>
     */
    private function keepAddressableRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $memberId = $row['member_id'] ?? null;
            if ($memberId !== null && $memberId > 0) {
                $result[] = ['email' => null, 'member_id' => $memberId, 'values' => $row['values']];
                continue;
            }
            $address = trim($row['email'] ?? '');
            if ($address === '') {
                continue;
            }
            $result[] = ['email' => $address, 'member_id' => null, 'values' => $row['values']];
        }

        return $result;
    }

    /**
     * The same resolution Controller\MassMailController performs for
     * somebody composing by hand — restated here rather than shared,
     * because the controller's copy reads the session and this one is
     * handed the actor. Both produce the same object, and
     * MassMailService's own assertions are what actually enforce it.
     */
    private function buildAuthorization(string $actorRole, string $actorEmail): SenderAuthorization
    {
        if (Role::fromString($actorRole)->hasAccess(Role::ADMIN)) {
            return new SenderAuthorization(true, [], null);
        }

        $currentYearId = $this->scoutYearService->getCurrentYear()['id'];

        return new SenderAuthorization(
            false,
            $this->accessService->getUserSectionIds($actorEmail, $currentYearId),
            $this->resolveSenderSection($actorEmail)
        );
    }

    /**
     * The actor's own section — the one a non-admin is locked to, and a
     * sensible default for an admin, who may change it in the composer.
     */
    private function resolveSenderSection(string $actorEmail): ?int
    {
        $currentYearId = $this->scoutYearService->getCurrentYear()['id'];

        return SectionPickerHelper::resolveDefault(
            null,
            $this->memberService->getLinkedMembers($actorEmail, $currentYearId),
            $this->sectionService->getAllWithBranches()
        );
    }
}
