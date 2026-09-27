<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\DnsMxLookup;
use PHPUnit\Framework\TestCase;

/**
 * The MX reading behind issue #422, without a network: the one call to
 * the resolver is replaced, and what is tested is what is made of its
 * answer.
 */
class DnsMxLookupTest extends TestCase
{
    /** @param array<int, array<string, mixed>>|false $answer */
    private function answering(array|false $answer): DnsMxLookup
    {
        return new class ($answer) extends DnsMxLookup {
            /** @param array<int, array<string, mixed>>|false $answer */
            public function __construct(private array|false $answer)
            {
            }

            protected function query(string $domain): array|false
            {
                return $this->answer;
            }
        };
    }

    public function testHostsComeBackMostPreferredFirstLowerCasedAndWithoutTheTrailingDot(): void
    {
        $hosts = $this->answering([
            ['type' => 'MX', 'pri' => 10, 'target' => 'ALT1.ASPMX.L.GOOGLE.COM.'],
            ['type' => 'MX', 'pri' => 1, 'target' => 'aspmx.l.google.com'],
            ['type' => 'MX', 'pri' => 10, 'target' => 'alt1.aspmx.l.google.com'],
        ])->hostsFor('famille.be');

        $this->assertSame(['aspmx.l.google.com', 'alt1.aspmx.l.google.com'], $hosts);
    }

    /** « No MX » is a fact about the domain; it must not read as a failure. */
    public function testADomainWithoutMxAnswersAnEmptyList(): void
    {
        $this->assertSame([], $this->answering([])->hostsFor('famille.be'));
    }

    /** « Could not ask » is a gap, and must never be stored as a fact. */
    public function testAResolverThatDidNotAnswerIsNull(): void
    {
        $this->assertNull($this->answering(false)->hostsFor('famille.be'));
    }
}
