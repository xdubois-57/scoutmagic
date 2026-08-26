<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use PHPUnit\Framework\TestCase;

/**
 * NotificationService::dispatch() re-checks every recipient against the
 * type's role_min — but only when it was handed a RoleResolver and a
 * ScoutYearService. Without them it degrades to "allow everybody", which
 * is harmless for a task answering the one person who asked for something
 * and actively wrong for one ANNOUNCING something to an audience defined
 * by role: an automatic update installed by the real crontab would reach
 * every account on the site instead of the superadmins its type is
 * declared for (Core\Maintenance\Task\InstallUpdateHandler).
 *
 * cron.php genuinely shipped without them. That is the §8.17 failure mode
 * this codebase keeps re-learning — a collaborator wired into one entry
 * point and not the other — so both are pinned here rather than trusted
 * to review.
 */
class NotificationRoleWiringTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        return [
            'web' => ['index.php'],
            'cron' => ['cron.php'],
        ];
    }

    private static function notificationServiceConstruction(string $file): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/public/' . $file);
        self::assertNotFalse($contents);

        $start = strpos($contents, 'new NotificationService(');
        self::assertNotFalse($start, $file . ' must construct a NotificationService.');

        $end = strpos($contents, ');', $start);
        self::assertNotFalse($end);

        return substr($contents, $start, $end - $start);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsGiveTheNotificationServiceItsRoleResolver(string $file): void
    {
        $construction = self::notificationServiceConstruction($file);

        $this->assertStringContainsString(
            '$roleResolver',
            $construction,
            $file . ' must pass a RoleResolver, or dispatch() skips every role_min check it makes.'
        );
        $this->assertStringContainsString(
            '$scoutYearService',
            $construction,
            $file . ' must pass a ScoutYearService — dispatch() needs the current year to resolve a role at all.'
        );
    }
}
