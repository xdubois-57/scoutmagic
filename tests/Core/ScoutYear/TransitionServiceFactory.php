<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalRepository;
use Core\Badge\BadgeHolderService;
use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearTransitionService;
use Core\ScoutYear\ScoutYearTransitionStepRepository;
use Core\ScoutYear\Step\Check\ActivatePublicStepCheck;
use Core\ScoutYear\Step\Check\ActivateStaffStepCheck;
use Core\ScoutYear\Step\Check\BadgesStepCheck;
use Core\ScoutYear\Step\Check\ConfirmRegistrationsStepCheck;
use Core\ScoutYear\Step\Check\DeparturesStepCheck;
use Core\ScoutYear\Step\Check\DeskMembersStepCheck;
use Core\ScoutYear\Step\Check\DeskStaffStepCheck;
use Core\ScoutYear\Step\Check\EphemeridesStepCheck;
use Core\ScoutYear\Step\Check\FeesStepCheck;
use Core\ScoutYear\Step\Check\ImportStepCheck;
use Core\ScoutYear\Step\Check\PassageStepCheck;
use Core\ScoutYear\Step\Check\PreviewStepCheck;
use Core\ScoutYear\Step\Check\StaffPhotosStepCheck;
use Core\ScoutYear\Step\Check\TrombinoscopeStepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\ScoutYearEventCountProvider;
use Modules\Registration\Api\ScoutYearPreparationProvider;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;

/**
 * Builds the « Année scoute » engine with its fourteen step checks the way
 * public/index.php wires them, so the service test and the controller test
 * exercise the same assembly. $overrides replaces the check of one key —
 * how a test gives a step a check of its own. The module contracts are
 * null unless a test hands one, as on a site without those modules; the
 * Trombinoscope responsable hook is never wired here.
 */
final class TransitionServiceFactory
{
    /**
     * @param array<int, string> $enabledModuleIds
     * @param list<StepCheckProvider> $overrides
     */
    public static function create(
        \PDO $pdo,
        SettingService $settingService,
        ScoutYearAdminService $adminService,
        array $enabledModuleIds = [],
        ?ScoutYearPreparationProvider $preparation = null,
        ?ScoutYearEventCountProvider $eventCount = null,
        array $overrides = [],
        ?ScoutYearRegistrationReviewProvider $review = null
    ): ScoutYearTransitionService {
        $scoutYearService = new ScoutYearService($pdo);
        $users = self::users($pdo);
        $stepRepository = new ScoutYearTransitionStepRepository($pdo);
        $sections = self::sections($pdo);
        $badges = new BadgeRepository($pdo);

        $checks = [];
        foreach ([
            new DeparturesStepCheck($review, $stepRepository, $sections),
            new PassageStepCheck($preparation, $review, $sections),
            new ConfirmRegistrationsStepCheck($adminService, $review),
            new DeskStaffStepCheck($sections, null),
            new DeskMembersStepCheck($review, $sections),
            new ImportStepCheck($users),
            new FeesStepCheck(null),
            new PreviewStepCheck(),
            new ActivateStaffStepCheck($adminService),
            new EphemeridesStepCheck($eventCount, $sections),
            new BadgesStepCheck($sections, new BadgeHolderService($badges, new MemberBadgeRepository($pdo), $sections), $badges),
            new TrombinoscopeStepCheck($sections, new MemberPhotoRepository($pdo), $scoutYearService),
            new StaffPhotosStepCheck($sections, new SectionPhotoRepository($pdo)),
            new ActivatePublicStepCheck($adminService),
            ...$overrides,
        ] as $check) {
            $checks[$check->stepKey()] = $check;
        }

        return new ScoutYearTransitionService(
            $stepRepository,
            new ImportJournalRepository($pdo),
            new JournalRepository($pdo),
            $scoutYearService,
            $users,
            $settingService,
            array_values($checks),
            $enabledModuleIds
        );
    }

    public static function users(\PDO $pdo): UserAccountRepository
    {
        return new UserAccountRepository($pdo, self::encryption());
    }

    public static function sections(\PDO $pdo): SectionService
    {
        $connection = Connection::withPdo($pdo);

        return new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, self::encryption(), new MemberBadgeRepository($pdo))
        );
    }

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }
}
