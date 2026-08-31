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
 * 3. **A name is resolved once, quietly.** The form offers no
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
        private MemberService $memberService
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
        ?int $answeredByUserAccountId = null
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
            $this->resolveWishes($friendNames, $currentScoutYearId, $memberId)
        );

        $saved = $this->repository->findAnswer($memberId, $targetScoutYearId);
        \assert($saved !== null);

        return $saved;
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
    public function resolveNames(array $friendNames, int $currentScoutYearId): array
    {
        return $this->resolveWishes($friendNames, $currentScoutYearId, 0);
    }

    /**
     * @param array<int, string> $friendNames
     * @return array<int, array{raw_name: string, matched_member_id: ?int, match_state: string}>
     */
    private function resolveWishes(array $friendNames, int $currentScoutYearId, int $selfMemberId): array
    {
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

        $directory = $this->memberService->findDirectoryForYear($currentScoutYearId);

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
     * Which members of the roster a typed name could mean.
     *
     * Deliberately forgiving on the shape and strict on the content: case
     * and accents are ignored (a parent types « leo », the roster holds
     * « Léo »), but nothing is guessed. « Léo » matching two Léos is two
     * matches — the honest answer — and the caller records that rather
     * than picking one.
     *
     * Three spellings are accepted, in this order, and the FIRST that
     * finds anybody wins: the full display name, « prénom nom », and the
     * first name or totem alone. Falling through to the loosest form only
     * when the tighter ones found nothing is what stops « Léo Martin »
     * from also matching every other Léo.
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

        $byForm = [[], [], []];
        foreach ($directory as $entry) {
            // A child naming themselves is not a wish; it would also make
            // the optimiser's "keep these two together" trivially true.
            if ($entry->memberId === $selfMemberId) {
                continue;
            }

            $forms = [
                self::normalise($entry->displayName . ' ' . $entry->lastName),
                self::normalise($entry->firstName . ' ' . $entry->lastName),
                self::normalise($entry->displayName),
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
     */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false) {
            // iconv writes « e' » or « 'e » for an accented letter depending
            // on the locale; both are noise for a comparison.
            $value = (string) preg_replace('/[^a-z0-9 ]/', '', $transliterated);
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
