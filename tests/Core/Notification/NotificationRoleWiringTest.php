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

    /**
     * The same §8.17 failure mode, one collaborator later. A dispatch has
     * no session, so nothing has resolved a scout year for the people it
     * is about: the role_min re-check asks over the authorization set
     * (ARCHITECTURE.md §4 « Scout year »). Wired into one entry point and
     * not the other, an animateur recruited for the year being prepared
     * would receive the notifications the site raises on a page view and
     * none of those the real crontab raises — a difference nobody would
     * ever trace back to a missing constructor argument.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsGiveItTheAuthorizationYearSet(string $file): void
    {
        $this->assertStringContainsString(
            '$authorizationYearService',
            self::notificationServiceConstruction($file),
            $file . ' must pass an AuthorizationYearService, or dispatch() judges every recipient in one year'
            . ' and silently drops the staff of the year being prepared.'
        );
    }

    /**
     * The same §8.17 failure mode, one collaborator later again — and
     * this one degrades in the direction nobody would notice.
     *
     * Without the factory, dispatch() cannot send an e-mail during the
     * call and falls back to the queue, which is correct for every type
     * but the one that declares itself immediate: an operational alert
     * (issue #296) would go back to waiting behind the cron it is
     * reporting dead. The entry point missing it would keep working
     * perfectly in every other respect, and the symptom would be an
     * e-mail that arrives late on some alerts and not others depending
     * on whether a page view or the crontab raised them.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsGiveItTheMailerFactory(string $file): void
    {
        $this->assertStringContainsString(
            'NotificationMailerFactory',
            self::notificationServiceConstruction($file),
            $file . ' must pass a NotificationMailerFactory, or an alert that declares immediate delivery'
            . ' silently queues its e-mail behind the scheduler it exists to report on.'
        );
    }

    /**
     * The service exists even when push does not (issue #318).
     *
     * `public/cron.php` used to construct it INSIDE
     * `if (VapidKeyPairFactory::isValid(...))`, so an installation whose
     * VAPID keys were missing or invalid handed `null` to every scheduled
     * handler — and `OperationalAlertService::notify()` returns on its
     * first line when its NotificationService is null. What was lost was
     * not the push: it was the e-mail and the `notifications` row too, for
     * every notification a cron pass raises, on the one kind of
     * installation least able to notice (a fresh one, or one whose key
     * generation had just failed).
     *
     * `NotificationService` takes `?WebPush` precisely so a missing push
     * transport degrades to "no push, everything else still runs" —
     * `public/index.php` has always done it that way. This pins that the
     * two entry points agree, which is the §8.17 failure mode in its
     * quietest form: the entry point that drifts keeps working perfectly
     * in every respect anybody would think to check.
     *
     * Asserted against the byte offsets rather than the prose, because the
     * prose around this block is exactly what a later edit rewrites.
     */
    public function testCronBuildsTheServiceOutsideTheVapidGuard(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/public/cron.php');

        $guard = strpos($contents, 'if (VapidKeyPairFactory::isValid(');
        $construction = strpos($contents, 'new NotificationService(');

        $this->assertNotFalse($guard, 'cron.php no longer guards the push transport on the VAPID keys.');
        $this->assertNotFalse($construction, 'cron.php must construct a NotificationService.');

        $guardEnd = strpos($contents, "\n}\n", (int) $guard);
        $this->assertNotFalse($guardEnd, 'The VAPID guard block in cron.php no longer ends at column zero.');

        $this->assertGreaterThan(
            (int) $guardEnd,
            (int) $construction,
            'cron.php constructs its NotificationService inside the VAPID guard. An installation with no '
            . 'valid VAPID key then loses every notification a scheduled task raises — the e-mail and the '
            . 'in-app row, not just the push — because TaskContext::$notifications is null and '
            . 'OperationalAlertService::notify() returns at once. Build the WebPush conditionally and the '
            . 'service unconditionally, as public/index.php does.'
        );
    }
}
