<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Member\MemberDirectoryEntry;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Service\TextNormalizerService;
use Modules\Registration\Repository\FriendWish;
use Modules\Registration\Repository\ReenrollmentAnswer;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * Reading and writing one family's answer about next scout year.
 *
 * No interface of its own yet, by design (roadmap IT-13): the data model
 * and the rules land first, the campaign and the form follow.
 *
 * Three rules live here rather than in whatever will call it:
 *
 * 1. **The wish cap is revalidated server-side, and silently.** A form
 *    that posts more entries than the setting allows has the extra ones
 *    dropped — never refused with an error. A parent who typed four names
 *    into a form that should have offered three has not done anything
 *    wrong, and an error page at that moment costs the answer entirely.
 * 2. **Lowering the cap destroys nothing.** It is applied when writing
 *    what was just submitted and when reading what to use, never as a
 *    delete. A unit that goes from three to two stops using the third and
 *    gets it back by raising the setting again.
 * 3. **The departure box follows the answer, once.** « quitte » ticks it
 *    and « réinscrit » unticks it — but only while the staff have not
 *    taken it over, which is ReenrollmentDepartureService's rule rather
 *    than this class's. Recording an answer and moving the box are one
 *    gesture here so that no caller can do the first without the second.
 * 4. **A name is resolved once, quietly.** The form offers no
 *    autocompletion and gives no feedback about who was found (module
 *    decision), so matching happens here, on the way in, and its outcome
 *    is recorded rather than reported: 'unique' when exactly one member of
 *    the target year carries that name, 'ambiguous' when several do,
 *    'none' otherwise. Ambiguous and none are ordinary answers to a free
 *    text field, not failures.
 */
class ReenrollmentService
{
    public const SETTING_FRIEND_WISH_LIMIT = 'registration_friend_wish_limit';
    public const SETTING_OPEN = 'registration_reenrollment_open';
    public const SETTING_OPEN_AT = 'registration_reenrollment_open_at';
    public const SETTING_CLOSE_AT = 'registration_reenrollment_close_at';

    private const DEFAULT_FRIEND_WISH_LIMIT = 3;

    public function __construct(
        private ReenrollmentRepository $repository,
        private SettingService $settingService,
        private MemberService $memberService,
        private ReenrollmentDepartureService $departureLink,
        private ProjectedPopulationService $projectedPopulation,
        private SectionService $sectionService
    ) {
    }

    /**
     * How many friends a family may name today.
     *
     * Never negative and never absurd: a setting somebody typed a word
     * into falls back to the shipped default rather than to zero, which
     * would silently switch the whole question off.
     */
    public function friendWishLimit(): int
    {
        $raw = $this->settingService->get(self::SETTING_FRIEND_WISH_LIMIT, 'registration');
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_FRIEND_WISH_LIMIT;

        return max(0, $limit);
    }

    /**
     * Record a family's answer for `$targetScoutYearId`.
     *
     * @param array<int, string> $friendNames raw, as typed, in the family's
     *        own order — extra entries beyond the cap are dropped here
     * @param int $currentScoutYearId the year the friends are looked for
     *        in: a child names somebody they know NOW, not somebody who
     *        will exist in a year nobody has imported yet
     */
    public function recordAnswer(
        int $memberId,
        int $targetScoutYearId,
        string $decision,
        ?int $preferredSectionId,
        ?string $familyComment,
        array $friendNames,
        int $currentScoutYearId,
        ?int $answeredByUserAccountId = null,
        ?int $arrivalBranchId = null
    ): ReenrollmentAnswer {
        $decision = $decision === ReenrollmentAnswer::DECISION_LEAVING
            ? ReenrollmentAnswer::DECISION_LEAVING
            : ReenrollmentAnswer::DECISION_REENROLLED;

        // A family who is leaving is not asked where they would like to go
        // or with whom, and a form that posted either anyway is answering
        // a question nobody asked.
        if ($decision === ReenrollmentAnswer::DECISION_LEAVING) {
            $preferredSectionId = null;
            $friendNames = [];
        }

        $this->repository->saveAnswer(
            $memberId,
            $targetScoutYearId,
            $decision,
            $preferredSectionId,
            $familyComment,
            $answeredByUserAccountId,
            $this->resolveWishes($friendNames, $currentScoutYearId, $memberId, $arrivalBranchId, $targetScoutYearId)
        );

        $saved = $this->repository->findAnswer($memberId, $targetScoutYearId);
        \assert($saved !== null);

        // The « Départs » box follows from here and from nowhere else
        // (roadmap IT-16). Inside recordAnswer() rather than beside its
        // callers: an answer recorded without its consequence would be a
        // family telling the site their child is leaving and the site
        // going on projecting them into next year.
        $this->departureLink->apply($saved, $currentScoutYearId, $answeredByUserAccountId);

        $reloaded = $this->repository->findAnswer($memberId, $targetScoutYearId);

        return $reloaded ?? $saved;
    }

    public function findAnswer(int $memberId, int $targetScoutYearId): ?ReenrollmentAnswer
    {
        return $this->repository->findAnswer($memberId, $targetScoutYearId);
    }

    /**
     * The wishes of one answer that count today — the cap applied on read,
     * so a setting that moved never lost anything.
     *
     * @return array<int, FriendWish>
     */
    public function usableWishes(ReenrollmentAnswer $answer): array
    {
        return $answer->friendWishesWithin($this->friendWishLimit());
    }

    /**
     * The same resolution, for a caller that has no member of its own to
     * exclude — the public registration form, where the child being
     * registered is not a member yet.
     *
     * Public because the two forms ask the identical question and must
     * answer it identically: a second matcher would be a second set of
     * rules about who « Léo » is.
     *
     * @param array<int, string> $friendNames
     * @return array<int, array{raw_name: string, matched_member_id: ?int, match_state: string}>
     */
    public function resolveNames(
        array $friendNames,
        int $currentScoutYearId,
        ?int $arrivalBranchId = null,
        ?int $targetScoutYearId = null
    ): array {
        return $this->resolveWishes($friendNames, $currentScoutYearId, 0, $arrivalBranchId, $targetScoutYearId);
    }

    /**
     * Who a still-ambiguous typed name could be, for the chief who has to
     * decide (roadmap IT-17).
     *
     * The same matcher, run again rather than a stored list: an ambiguity
     * is a question about today's roster, and a list frozen at submission
     * time would offer a chief the name of a child who has since left.
     *
     * @return array<int, array{member_id: int, label: string}>
     */
    public function candidatesFor(
        string $rawName,
        int $currentScoutYearId,
        ?int $arrivalBranchId,
        ?int $targetScoutYearId,
        int $selfMemberId = 0
    ): array {
        $directory = $this->candidates($currentScoutYearId, $arrivalBranchId, $targetScoutYearId);
        $byId = [];
        foreach ($directory as $entry) {
            $byId[$entry->memberId] = $entry;
        }

        $candidates = [];
        foreach ($this->matchName($rawName, $directory, $selfMemberId) as $memberId) {
            if (isset($byId[$memberId])) {
                $candidates[] = ['member_id' => $memberId, 'label' => $byId[$memberId]->label()];
            }
        }

        return $candidates;
    }

    /**
     * How a matched member reads on the Passage page — the roster's own
     * label, never a name this module formats itself.
     */
    public function labelForMember(int $memberId, int $currentScoutYearId): ?string
    {
        foreach ($this->memberService->findDirectoryForYear($currentScoutYearId) as $entry) {
            if ($entry->memberId === $memberId) {
                return $entry->label();
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $friendNames
     * @return array<int, array{raw_name: string, matched_member_id: ?int, match_state: string}>
     */
    private function resolveWishes(
        array $friendNames,
        int $currentScoutYearId,
        int $selfMemberId,
        ?int $arrivalBranchId = null,
        ?int $targetScoutYearId = null
    ): array {
        $names = [];
        foreach ($friendNames as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        // Applied BEFORE the directory is loaded: a form that posted fifty
        // names must not cost fifty matches.
        $names = array_slice($names, 0, $this->friendWishLimit());
        if ($names === []) {
            return [];
        }

        $directory = $this->candidates($currentScoutYearId, $arrivalBranchId, $targetScoutYearId);

        $wishes = [];
        foreach ($names as $name) {
            $matches = $this->matchName($name, $directory, $selfMemberId);

            $wishes[] = [
                'raw_name' => $name,
                'matched_member_id' => count($matches) === 1 ? $matches[0] : null,
                'match_state' => match (count($matches)) {
                    0 => FriendWish::MATCH_NONE,
                    1 => FriendWish::MATCH_UNIQUE,
                    default => FriendWish::MATCH_AMBIGUOUS,
                },
            ];
        }

        return $wishes;
    }

    /**
     * Who a typed name may be looked for among.
     *
     * With an arrival branch, the PROJECTED population of that branch for
     * next year (roadmap IT-17) — which is who the child will actually be
     * placed with, and is what makes « Léo » unambiguous in a unit that
     * has three of them spread over three branches. Without one (a caller
     * that does not know the branch), the whole roster, as before.
     *
     * The projection is the module's own ProjectedPopulationService, the
     * same instance the Passage statistics box reads: two projections
     * would be two answers to « who will be in Éclaireurs next year ».
     * Only projected people who already have a member id are candidates —
     * an accepted registration nobody has encoded yet is a real future
     * member, but `matched_member_id` is a member id, and a second
     * half-empty column for the other half is exactly what
     * registration_request_friend_wishes exists to avoid.
     *
     * @return array<int, MemberDirectoryEntry>
     */
    private function candidates(int $currentScoutYearId, ?int $arrivalBranchId, ?int $targetScoutYearId): array
    {
        $directory = $this->memberService->findDirectoryForYear($currentScoutYearId);
        if ($arrivalBranchId === null || $targetScoutYearId === null) {
            return $directory;
        }

        $sectionsInBranch = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if ((int) $section['age_branch_id'] === $arrivalBranchId) {
                $sectionsInBranch[(int) $section['id']] = true;
            }
        }
        if ($sectionsInBranch === []) {
            return $directory;
        }

        $inBranch = [];
        foreach ($this->projectedPopulation->projectedPopulation($targetScoutYearId) as $person) {
            if (
                $person->memberId !== null
                && $person->sectionId !== null
                && isset($sectionsInBranch[$person->sectionId])
            ) {
                $inBranch[$person->memberId] = true;
            }
        }

        return array_values(array_filter(
            $directory,
            static fn(MemberDirectoryEntry $entry): bool => isset($inBranch[$entry->memberId])
        ));
    }

    /**
     * Which members of the candidate set a typed name could mean.
     *
     * Deliberately forgiving on the shape and strict on the content: case
     * and accents are ignored (a parent types « leo », the roster holds
     * « Léo »), but nothing is guessed. « Léo » matching two Léos is two
     * matches — the honest answer — and the caller records that rather
     * than picking one.
     *
     * Four spellings are accepted, in this order, and the FIRST that finds
     * anybody wins: « prénom nom », « totem nom », the totem alone, then
     * the first name alone. Falling through to a bare form only when the
     * full ones found nothing is what stops « Léo Martin » from also
     * matching every other Léo — and putting the totem before the first
     * name is §4's own convention, a totem being the name a scout is
     * actually called by.
     *
     * @param array<int, MemberDirectoryEntry> $directory
     * @return array<int, int> matching member ids
     */
    private function matchName(string $name, array $directory, int $selfMemberId): array
    {
        $needle = self::normalise($name);
        if ($needle === '') {
            return [];
        }

        $byForm = [[], [], [], []];
        foreach ($directory as $entry) {
            // A child naming themselves is not a wish; it would also make
            // the optimiser's "keep these two together" trivially true.
            if ($entry->memberId === $selfMemberId) {
                continue;
            }

            $forms = [
                self::normalise($entry->firstName . ' ' . $entry->lastName),
                self::normalise(($entry->totem ?? '') . ' ' . $entry->lastName),
                self::normalise($entry->totem ?? ''),
                self::normalise($entry->firstName),
            ];

            foreach ($forms as $index => $form) {
                if ($form !== '' && $form === $needle) {
                    $byForm[$index][] = $entry->memberId;
                }
            }
        }

        foreach ($byForm as $matches) {
            if ($matches !== []) {
                return array_values(array_unique($matches));
            }
        }

        return [];
    }

    /**
     * Lower-cased, accent-stripped, whitespace-collapsed — so « Léo   MARTIN »
     * and « leo martin » are the same string to compare.
     *
     * Core\Service\TextNormalizerService::fold() rather than a fold of our
     * own: this one used iconv('ASCII//TRANSLIT'), whose output depends on
     * the C library and the locale — the same « é » comes back as `e` on
     * glibc and as `'e` on musl, so two installations disagreed about
     * whether « Léo » and « leo » were the same name (roadmap IT-17, and
     * that function's own docblock, which says exactly this).
     */
    private static function normalise(string $value): string
    {
        return TextNormalizerService::fold($value);
    }
}
