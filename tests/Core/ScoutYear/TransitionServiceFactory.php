<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
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

/**
 * Builds the « Année scoute » engine with its fourteen step checks the way
 * public/index.php wires them, so the service test and the controller test
 * exercise the same assembly. $overrides replaces the check of one key —
 * how a test gives a step a refusal to mark before lot E writes one.
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
        array $overrides = []
    ): ScoutYearTransitionService {
        $scoutYearService = new ScoutYearService($pdo);
        $users = self::users($pdo);
        $resolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($pdo));

        $checks = [];
        foreach ([
            new DeparturesStepCheck(),
            new PassageStepCheck($preparation),
            new ConfirmRegistrationsStepCheck($adminService),
            new DeskStaffStepCheck(),
            new DeskMembersStepCheck(),
            new ImportStepCheck($users),
            new FeesStepCheck(),
            new PreviewStepCheck(),
            new ActivateStaffStepCheck($adminService),
            new EphemeridesStepCheck($eventCount),
            new BadgesStepCheck(),
            new TrombinoscopeStepCheck(),
            new StaffPhotosStepCheck($resolver, new SectionPhotoRepository($pdo)),
            new ActivatePublicStepCheck($adminService),
            ...$overrides,
        ] as $check) {
            $checks[$check->stepKey()] = $check;
        }

        return new ScoutYearTransitionService(
            new ScoutYearTransitionStepRepository($pdo),
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
        return new UserAccountRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
    }
}
