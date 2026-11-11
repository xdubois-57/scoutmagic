<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\Repository\StaffedSectionRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\MassMail\Service\MassMailAccessService;

/**
 * The real MassMailAccessService over a test database — the core's
 * role-filtered staffing resolution (secondary addresses included) and the
 * authorization year, as public/index.php wires it (issue #1105).
 */
final class MassMailAccessFactory
{
    public static function create(
        \PDO $pdo,
        EncryptionService $encryption,
        SectionService $sectionService
    ): MassMailAccessService {
        return new MassMailAccessService(
            new SectionStaffAuthorizationService(
                new StaffedSectionRepository(
                    Connection::withPdo($pdo),
                    $encryption,
                    new MemberEmailRepository($pdo, $encryption)
                ),
                $sectionService
            ),
            new ScoutYearResolver(
                new ScoutYearService($pdo),
                new SettingService(new SettingRepository($pdo)),
                new MemberYearRepository($pdo)
            )
        );
    }
}
