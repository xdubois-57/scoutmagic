<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Member\DepartureService;
use Modules\Registration\Repository\ReenrollmentAnswer;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * The one place where a family's answer about next year and the staff's
 * own « Départs » checkbox meet (roadmap IT-16, spec §11.8).
 *
 * There are now two ways for the site to learn that a child will not be
 * back: a chief ticking a box, and a parent saying so. They write the
 * SAME fact — `member_years.leaving` — and this class owns the three
 * rules that keep one truth out of two sources.
 *
 * **1. The link runs one way.** A family answer moves the box. A chief
 * moving the box never fabricates a family answer: an answer is
 * something a parent said, and inventing one would put words in their
 * mouth on a screen their own « Réinscription » page reads back to them.
 *
 * **2. The staff has the last word, and it lasts.** The automation owns
 * the box only for as long as the box still holds what the automation
 * last left there — `registration_reenrollments.applied_leaving`, or
 * `false` before it ever wrote. The moment the two differ, somebody on
 * the staff has moved it, and no later answer overwrites that: a chief
 * who knows the family is wrong (they told the section, not the site;
 * they changed their mind on the phone) does not have to keep winning
 * the same argument every time a parent re-opens the form.
 *
 * Storing the last applied value rather than comparing timestamps is
 * what makes this decidable at all. `leaving_marked_at` is NULL both for
 * "never marked" and for "a chief unticked it", so no ordering can be
 * read out of it — and a second history table for one boolean would be a
 * lot of machinery for a question that is really just "is the box still
 * where I put it?".
 *
 * **3. The family's words are never the staff's.** The family comment
 * lives in `registration_reenrollments`, read back to the family who
 * wrote it. `member_years.leaving_comment_encrypted` is the staff's
 * internal note about that departure. This class writes the flag alone
 * (`DepartureService::setLeavingFromFamilyAnswer()`) precisely so that
 * an answer can never erase the other.
 */
class ReenrollmentDepartureService
{
    public function __construct(
        private ReenrollmentRepository $repository,
        private MemberYearRepository $memberYearRepository,
        private DepartureService $departureService,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService
    ) {
    }

    /**
     * Push `$answer` into the departure box for the child's CURRENT year,
     * unless the staff has taken it over.
     *
     * Called from `ReenrollmentService::recordAnswer()` — the single
     * write point for an answer — so the consequence can never be
     * forgotten by a caller that records one.
     *
     * The box lives on the PUBLIC year's `member_years` row, not on the
     * target year's: "will not be back next year" is a fact about the
     * membership that exists today, which is also where the « Départs »
     * page, Passage and Prévisions all read it.
     */
    public function apply(ReenrollmentAnswer $answer, int $publicYearId, ?int $actingUserAccountId = null): void
    {
        $memberYear = $this->memberYearRepository->findByMemberAndYear($answer->memberId, $publicYearId);
        if ($memberYear === null) {
            // A child with no row for the public year has no box to tick.
            // Nothing is lost: the answer is stored, and the day an import
            // creates the row the divergence counter shows it as one.
            return;
        }

        $memberYearId = (int) $memberYear['id'];
        $status = $this->departureService->getStatus($memberYearId);
        if ($status === null) {
            return;
        }

        if ($status->leaving !== ($answer->appliedLeaving ?? false)) {
            // Somebody on the staff moved it. Their decision stands, and
            // the divergence is what the page reports rather than
            // something this class silently resolves.
            return;
        }

        $desired = $answer->meansLeaving();
        if ($status->leaving !== $desired) {
            $this->departureService->setLeavingFromFamilyAnswer($memberYearId, $desired, $actingUserAccountId);
        }

        $this->repository->markAppliedLeaving($answer->id, $desired);
    }

    /**
     * Add each row's family answer to what the « Départs » page already
     * knows, and count what a chief needs to see at the top.
     *
     * The answers are fetched in ONE query for the whole year and looked
     * up by member id, rather than one query per line: this runs on a
     * page that lists a whole section.
     *
     * `visible` is what the page uses to decide whether to show any of
     * this at all. A unit that has never run a reenrollment campaign has
     * no answers and no question, and a column of empty cells with
     * « 24 sans réponse » above it would be an alarm about a feature they
     * do not use. It is shown while a campaign is open — where "nobody
     * has answered yet" is exactly the number a chief wants — and
     * afterwards for as long as answers exist.
     *
     * @param array<int, array<string, mixed>> $rows each carrying at
     *        least `profile` (with a memberId) and `leaving`
     * @return array{rows: array<int, array<string, mixed>>, divergences: int, unanswered: int, visible: bool, target_year_label: ?string}
     */
    public function annotate(array $rows, string $publicYearLabel): array
    {
        $targetLabel = ScoutYearService::nextLabel($publicYearLabel);
        // findByLabel(), never ensureYear(): reading the « Départs » page
        // must not create a scout year as a side effect.
        $targetYear = $this->scoutYearService->findByLabel($targetLabel);

        $answers = $targetYear === null ? [] : $this->repository->findAnswersForYear((int) $targetYear['id']);

        $divergences = 0;
        $unanswered = 0;
        $annotated = [];

        foreach ($rows as $row) {
            $memberId = (int) $row['profile']->memberId;
            $answer = $answers[$memberId] ?? null;
            $diverges = $answer !== null && $answer->meansLeaving() !== (bool) $row['leaving'];

            if ($answer === null) {
                $unanswered++;
            } elseif ($diverges) {
                $divergences++;
            }

            $row['answer'] = $answer;
            $row['diverges'] = $diverges;
            $annotated[] = $row;
        }

        return [
            'rows' => $annotated,
            'divergences' => $divergences,
            'unanswered' => $unanswered,
            'visible' => $answers !== [] || $this->campaignOpen(),
            'target_year_label' => $targetYear === null ? null : (string) $targetYear['label'],
        ];
    }

    private function campaignOpen(): bool
    {
        return (string) $this->settingService->get(ReenrollmentCampaignService::SETTING_OPEN, 'registration', '0') === '1';
    }
}
