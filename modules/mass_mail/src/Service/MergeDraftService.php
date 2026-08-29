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
     * @param list<array{email: string, values: array<string, string>}> $rows
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
        $rows = $this->deduplicateByAddress($rows);
        if ($rows === []) {
            throw new MassMailException('Aucune adresse à qui écrire.');
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
            // member_id stays null even when the address belongs to a
            // member we know. Setting it would send to EVERY address that
            // member has ever registered, and the person answered with one
            // precise address — the one they expect to hear back on. The
            // consequence is deliberate and documented: unsubscribing goes
            // through mass_mail_suppressed_addresses rather than through
            // their member_emails row.
            $this->audienceRepository->createRow($audienceId, $rowIndex, null, $row['email'], $row['values']);
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

        return '/mass-mail/' . $email->id;
    }

    /**
     * Two people who answered with the same address are one recipient.
     * The first row wins: on a form, the earliest answer is the one whose
     * values the rest of the row was read from.
     *
     * Compared on the lowercased, trimmed address — the same normalisation
     * the rest of the site indexes addresses with, so "A@b.be" and
     * "a@b.be " do not become two mails to one person.
     *
     * @param list<array{email: string, values: array<string, string>}> $rows
     * @return list<array{email: string, values: array<string, string>}>
     */
    private function deduplicateByAddress(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $address = trim($row['email']);
            if ($address === '') {
                continue;
            }
            $key = strtolower($address);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['email' => $address, 'values' => $row['values']];
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
