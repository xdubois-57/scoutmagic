<?php

declare(strict_types=1);

namespace Tests\Core\Audit;

use Core\Audit\AuditAccessResolver;
use PHPUnit\Framework\TestCase;

class AuditAccessResolverTest extends TestCase
{
    public function testAnUnregisteredEntityTypeIsDenied(): void
    {
        $resolver = new AuditAccessResolver();

        // The whole point of the component: forgetting to register a
        // checker must fail closed. The ids in /api/audit/{type}/{id} are
        // sequential and guessable, so "unknown" can never mean "allowed".
        $this->assertFalse($resolver->canRead('camp_camp', 1));
        $this->assertFalse($resolver->isRegistered('camp_camp'));
    }

    public function testARegisteredCheckerDecides(): void
    {
        $resolver = new AuditAccessResolver();
        $resolver->register('camp_camp', fn(int $id): bool => $id === 42);

        $this->assertTrue($resolver->canRead('camp_camp', 42));
        $this->assertFalse($resolver->canRead('camp_camp', 43));
        $this->assertTrue($resolver->isRegistered('camp_camp'));
    }

    public function testACheckerAnswersOnlyForItsOwnEntityType(): void
    {
        $resolver = new AuditAccessResolver();
        $resolver->register('camp_camp', fn(int $id): bool => true);

        $this->assertTrue($resolver->canRead('camp_camp', 1));
        $this->assertFalse($resolver->canRead('camp_place', 1));
    }

    public function testTheCheckerReceivesTheEntityId(): void
    {
        $seen = [];
        $resolver = new AuditAccessResolver();
        $resolver->register('camp_camp', function (int $id) use (&$seen): bool {
            $seen[] = $id;
            return true;
        });

        $resolver->canRead('camp_camp', 7);
        $resolver->canRead('camp_camp', 9);

        $this->assertSame([7, 9], $seen);
    }

    public function testRegisteringTwiceKeepsTheLastChecker(): void
    {
        $resolver = new AuditAccessResolver();
        $resolver->register('camp_camp', fn(int $id): bool => true);
        $resolver->register('camp_camp', fn(int $id): bool => false);

        $this->assertFalse($resolver->canRead('camp_camp', 1));
    }
}
