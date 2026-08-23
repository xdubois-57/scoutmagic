<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Exception;

use Core\Exception\UserFacingException;
use Core\Exception\UserFacingMessage;
use PHPUnit\Framework\TestCase;

/**
 * The gate every caught exception passes through before its message is
 * shown to a visitor.
 *
 * Fixtures rather than production classes on purpose: this file pins the
 * RULE. Which real classes are on which side of it is pinned separately by
 * UserFacingExceptionInventoryTest, so a mistake there cannot quietly
 * rewrite what the rule itself means.
 */
final class UserFacingMessageTest extends TestCase
{
    public function testAMarkedExceptionSpeaksForItself(): void
    {
        $e = new FixtureUserFacingException('Ce badge est déjà attribué à un membre.');

        self::assertSame(
            'Ce badge est déjà attribué à un membre.',
            UserFacingMessage::from($e, 'Le badge n\'a pas pu être supprimé.')
        );
    }

    public function testAnUnmarkedExceptionNeverReachesTheVisitor(): void
    {
        // The three real shapes this exists to stop: a filesystem path, a
        // SQL error, and a library's own English.
        $leaks = [
            new \RuntimeException('Module manifest not found: /var/www/html/modules/x/module.json'),
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'foo'"),
            new \Exception('SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki'),
        ];

        foreach ($leaks as $e) {
            self::assertSame(
                'Le module n\'a pas pu être activé.',
                UserFacingMessage::from($e, 'Le module n\'a pas pu être activé.'),
                'A message no one wrote for a visitor must not reach one'
            );
        }
    }

    public function testAMarkedExceptionWithNothingToSayStillGetsTheFallback(): void
    {
        foreach (['', '   ', "\n\t "] as $blank) {
            self::assertSame(
                'L\'enregistrement a échoué.',
                UserFacingMessage::from(
                    new FixtureUserFacingException($blank),
                    'L\'enregistrement a échoué.'
                ),
                'A blank message would render as an empty flash — a failure that looks like a success'
            );
        }
    }

    public function testTheMarkerIsInheritedBySubclasses(): void
    {
        self::assertSame(
            'Ce board est clôturé.',
            UserFacingMessage::from(
                new FixtureUserFacingSubclass('Ce board est clôturé.'),
                'Action impossible.'
            )
        );
    }

    /**
     * The wrapping trap the interface's docblock warns about, stated as a
     * test so it is not only prose: marking a class does not sanitise what
     * that class is constructed with. `new X($e->getMessage())` re-labels a
     * technical message as user-facing, and nothing downstream can tell.
     * The defence lives at the wrap site, and
     * UserFacingExceptionInventoryTest scans for it.
     */
    public function testMarkingDoesNotSanitiseALaunderedMessage(): void
    {
        $technical = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $laundered = new FixtureUserFacingException($technical->getMessage(), 0, $technical);

        self::assertSame(
            'SQLSTATE[HY000] [2002] Connection refused',
            UserFacingMessage::from($laundered, 'La réservation n\'a pas pu être enregistrée.'),
            'Documented behaviour: the rule trusts the class, so the wrap site is where the leak must be stopped'
        );
    }
}

final class FixtureUserFacingException extends \RuntimeException implements UserFacingException
{
}

class FixtureUserFacingBase extends \RuntimeException implements UserFacingException
{
}

final class FixtureUserFacingSubclass extends FixtureUserFacingBase
{
}
