<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\ScoutYearService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionMembershipRepository;
use Core\Member\UnitStaffSectionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Repository\RentalAssetManagerRepository;

/**
 * Who hears about an asset (#708, IT-05): ONE rule for every message meant
 * for the people who run it — a new request and every reminder alike.
 *
 * **The asset's active managers who have an account.** The Staff d'U are
 * implicit managers of every asset, but not recipients by default: a unit
 * with six halls would otherwise hear about all six. **When nobody on the
 * asset can be reached** — no active manager, or none with an account — the
 * Staff d'U members of the current year who have an account are told
 * instead, and the journal says so; a request must never land nowhere.
 *
 * The journal carries ids and counts only, never a name or an address.
 *
 * Its own class rather than a method of RentalManagerService: the latter
 * is built in half a dozen places that have no account or section
 * repository to give it, and a rule half of its callers could not run
 * would not be one rule.
 */
final class ManagerRecipientResolver
{
    public const REASON_NO_ACCOUNT = "n'a pas de compte sur le site";
    public const REASON_INACTIVE = 'est inactif';

    /**
     * @param (\Closure(): list<int>)|null $unitStaffMemberIds the Staff d'U
     *   members of the current year; null means no fallback exists (a test,
     *   a site without the section), which is journaled like nobody found.
     */
    public function __construct(
        private RentalAssetManagerRepository $managerRepository,
        private MemberYearRepository $memberYearRepository,
        private UserAccountRepository $userAccountRepository,
        private JournalService $journal,
        private ?\Closure $unitStaffMemberIds = null
    ) {
    }

    /**
     * The production fallback: the members of the Staff d'U section in the
     * current scout year.
     *
     * @return \Closure(): list<int>
     */
    public static function unitStaffOfTheCurrentYear(
        SectionRepository $sections,
        SectionMembershipRepository $memberships,
        ScoutYearService $scoutYears
    ): \Closure {
        return static function () use ($sections, $memberships, $scoutYears): array {
            $section = $sections->findByDeskCode(UnitStaffSectionService::DESK_CODE);
            if ($section === null) {
                return [];
            }

            return array_values($memberships->findMemberIdsForSections(
                [(int) $section['id']],
                (int) $scoutYears->getCurrentYear()['id']
            ));
        };
    }

    /**
     * Who to notify about this asset, falling back on the Staff d'U.
     *
     * @param string $about what the message is, for the journal only
     *   (« nouvelle demande », a reminder kind)
     * @return list<array{userAccountId: int, memberId: ?int}>
     */
    public function recipientsFor(int $assetId, string $about): array
    {
        $managers = $this->reachableManagers($assetId);
        if ($managers !== []) {
            return $managers;
        }

        $staff = $this->accountsOf($this->unitStaffMemberIds !== null ? ($this->unitStaffMemberIds)() : []);
        if ($staff === []) {
            $this->journal->log(
                'rental',
                'rental_nobody_reachable',
                'warning',
                'Personne à prévenir pour un bien : ni gestionnaire joignable, ni membre du Staff d\'U avec un compte.',
                ['asset_id' => $assetId, 'about' => $about]
            );

            return [];
        }

        $this->journal->log(
            'rental',
            'rental_staff_fallback',
            'info',
            'Aucun gestionnaire joignable pour ce bien : le Staff d\'U est prévenu à sa place.',
            ['asset_id' => $assetId, 'about' => $about, 'recipients' => count($staff)]
        );

        return $staff;
    }

    /**
     * Whether at least one manager of this asset can be told anything.
     */
    public function hasReachableManager(int $assetId): bool
    {
        return $this->reachableManagers($assetId) !== [];
    }

    /**
     * Why each manager of this asset cannot be told anything — keyed by
     * member id, absent when they can. Where a unit corrects it, rather
     * than learning it the day a request goes nowhere.
     *
     * @return array<int, string>
     */
    public function unreachableManagers(int $assetId): array
    {
        $reasons = [];
        foreach ($this->managerRepository->findAllByAsset($assetId, false) as $manager) {
            if (!$manager->isActive) {
                $reasons[$manager->memberId] = self::REASON_INACTIVE;
            } elseif ($this->accountIdOf($manager->memberId) === null) {
                $reasons[$manager->memberId] = self::REASON_NO_ACCOUNT;
            }
        }

        return $reasons;
    }

    /**
     * @return list<array{userAccountId: int, memberId: ?int}>
     */
    private function reachableManagers(int $assetId): array
    {
        return $this->accountsOf(array_map(
            static fn($manager): int => $manager->memberId,
            $this->managerRepository->findAllByAsset($assetId, true)
        ));
    }

    /**
     * A member with no account is skipped rather than guessed at: there is
     * no notification to deliver to somebody who has never logged in.
     *
     * @param int[] $memberIds
     * @return list<array{userAccountId: int, memberId: ?int}>
     */
    private function accountsOf(array $memberIds): array
    {
        $recipients = [];
        foreach ($memberIds as $memberId) {
            $accountId = $this->accountIdOf($memberId);
            if ($accountId !== null) {
                $recipients[$accountId] ??= ['userAccountId' => $accountId, 'memberId' => $memberId];
            }
        }

        return array_values($recipients);
    }

    private function accountIdOf(int $memberId): ?int
    {
        $blindIndex = $this->memberYearRepository->findMostRecentEmailBlindIndexForMember($memberId);
        if ($blindIndex === null || $blindIndex === '') {
            return null;
        }

        return $this->userAccountRepository->findByBlindIndex($blindIndex)?->id;
    }
}
