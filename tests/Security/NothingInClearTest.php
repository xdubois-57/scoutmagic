<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\NothingInClear;

/**
 * The shared reader that says « this is not stored in clear anywhere », read
 * for once instead of trusted.
 *
 * It is used by the encryption-at-rest tests of two modules, and the thing
 * that makes it worth its own class is the direction of its failures: every
 * one of them is a test going GREEN. A reader that loses a value, or that
 * finds nothing at all, satisfies every absence asked of it and says so in
 * exactly the same words as a reader that checked properly.
 *
 * **In `tests/Security/` rather than beside the class.** `tests/` itself is
 * not one of the directories `phpunit.xml` lists, so a file put next to
 * `NothingInClear.php` would never run — which is the failure mode this
 * class exists to guard against, arriving through the door.
 *
 * Carries `database` because one test builds an in-memory SQLite connection
 * — `DatabaseBackedTestsCarryTheGroupTest` said so, and it was right: the
 * group is what `--group=database` selects, and a class missing from that
 * selection is a class it silently does not check.
 */
#[Group('database')]
final class NothingInClearTest extends TestCase
{
    /**
     * **Two leaves can spell the same path, and both must survive.**
     *
     * A reviewer found this: the flattening merged on the path, so
     * `['a.b' => 'safe']` beside `['a' => ['b' => 'secret']]` kept one and
     * dropped the other. `assertAbsent('secret')` then passed because the
     * leaf carrying the secret had been thrown away on the way in — the
     * worst shape of false pass there is, since nothing about the test
     * looks wrong.
     */
    public function testALeafIsNotLostToAnotherThatSpellsTheSamePath(): void
    {
        $colliding = NothingInClear::inValuesOf([
            'a.b' => 'safe',
            'a' => ['b' => 'secret'],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('secret');

        $colliding->assertAbsent('secret');
    }

    /** And the same store still reports the leaf that was already found. */
    public function testTheOtherLeafIsStillReported(): void
    {
        $colliding = NothingInClear::inValuesOf([
            'a.b' => 'safe',
            'a' => ['b' => 'secret'],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('safe');

        $colliding->assertAbsent('safe');
    }

    /**
     * An empty store fails rather than answering « absent » for free.
     *
     * The distinction this class draws between `assertAbsent()` and
     * `assertEverythingIsGone()` rests entirely on this: in an empty store
     * every absence is true, so a test that means « it still holds things,
     * none of them this » must not be satisfied by an accident that emptied
     * it.
     */
    public function testAnEmptyStoreIsARefusalRatherThanAnAbsence(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('nothing was read back');

        NothingInClear::inValuesOf([])->assertAbsent('anything');
    }

    /** And where empty IS the claim, it is a different method. */
    public function testEmptyIsAClaimOfItsOwn(): void
    {
        NothingInClear::inValuesOf([])->assertEverythingIsGone();
    }

    /** Nested values are reached, and the failure names where. */
    public function testTheFailureNamesThePlaceItWasFoundIn(): void
    {
        $store = NothingInClear::inValuesOf(['offer' => ['driver' => ['phone' => '0478123456']]]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('offer.driver.phone');

        $store->assertAbsent('0478');
    }

    /** A table name that is not an identifier stops here, not in the database. */
    public function testATableNameThatIsNotAnIdentifierIsRefused(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->expectException(\InvalidArgumentException::class);

        NothingInClear::inTables($pdo, 'members; DROP TABLE members');
    }
}
