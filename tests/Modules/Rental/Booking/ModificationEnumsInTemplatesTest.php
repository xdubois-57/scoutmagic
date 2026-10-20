<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\ModificationStage;
use Modules\Rental\Booking\ModificationStatus;
use Modules\Rental\Stay\IncidentDecision;
use PHPUnit\Framework\TestCase;

/**
 * **A template never calls a method its enum lacks.** Twig is not strict
 * here, so `modification.status.badgeStatus` on an enum without that
 * method renders null and the badge is grey forever, with nothing to say
 * so (#976). Every `booking.status.x`, `modification.status.x`,
 * `progress.stage.x`, `replacement.stage.x` and `incident.decision.x` in
 * the module's templates is checked against the enum it names.
 */
final class ModificationEnumsInTemplatesTest extends TestCase
{
    private const RECEIVERS = [
        'booking.status' => BookingStatus::class,
        'modification.status' => ModificationStatus::class,
        'progress.stage' => ModificationStage::class,
        'replacement.stage' => ModificationStage::class,
        'incident.decision' => IncidentDecision::class,
    ];

    public function testEveryEnumMemberATemplateReadsExists(): void
    {
        $views = dirname(__DIR__, 4) . '/modules/rental/views';
        $missing = [];
        $seen = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.twig')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            preg_match_all(
                '/\b(booking\.status|modification\.status|progress\.stage|replacement\.stage'
                    . '|incident\.decision)\.(\w+)/',
                $source,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as [, $receiver, $member]) {
                $seen++;
                $class = self::RECEIVERS[$receiver];
                if ($member === 'value' || $member === 'name' || method_exists($class, $member)) {
                    continue;
                }
                $missing[] = substr((string) $file, strlen($views) + 1) . ': ' . $receiver . '.' . $member;
            }
        }

        $this->assertGreaterThan(0, $seen, 'the scan found nothing to check');
        $this->assertSame([], $missing, 'a template reads a member its enum does not have');
    }

    public function testEveryModificationStatusHasATone(): void
    {
        $this->assertSame('draft', ModificationStatus::DRAFT->badgeStatus());
        $this->assertSame('pending', ModificationStatus::SENT->badgeStatus());
        $this->assertSame('done', ModificationStatus::APPLIED->badgeStatus());
        $this->assertSame('done', ModificationStatus::APPLIED_DIRECTLY->badgeStatus());
        $this->assertSame('cancelled', ModificationStatus::CANCELLED->badgeStatus());
    }
}
