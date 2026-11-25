<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\RosterMembershipFacts;
use Modules\Fees\Service\GuestTariffRule;
use Modules\Fees\Value\GuestEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestTariffRuleTest extends TestCase
{
    /** @return iterable<string, array{?string, ?bool, ?bool, ?array, ?bool, ?string}> */
    public static function cases(): iterable
    {
        $period = ['2027-06-01', '2027-08-31'];
        yield 'renewal despite missing dates and policy' => [null, null, true, null, false, null];
        yield 'known early function despite another missing' => ['2027-05-31', false, false, $period, false, null];
        yield 'inclusive start' => ['2027-06-01', true, false, $period, true, null];
        yield 'inclusive end at night' => ['2027-08-31 23:59:59', true, false, $period, true, null];
        yield 'before start' => ['2027-05-31', true, false, $period, false, null];
        yield 'after end' => ['2027-09-01', true, false, $period, false, null];
        yield 'starting civil summer is not ending summer' => ['2026-06-01', true, false, $period, false, null];
        yield 'late known function does not exclude unknown earlier function' => [
            '2027-09-01', false, false, $period, null, GuestEligibility::UNKNOWN_STARTS
        ];
        yield 'one start missing inside period' => [
            '2027-06-01', false, false, $period, null, GuestEligibility::UNKNOWN_STARTS
        ];
        yield 'no starts' => [null, false, false, $period, null, GuestEligibility::UNKNOWN_STARTS];
        yield 'legacy unknown' => [null, null, null, $period, null, GuestEligibility::UNKNOWN_STARTS];
        yield 'previous year unproven' => [
            '2027-06-01', true, null, $period, null, GuestEligibility::UNKNOWN_PREVIOUS_YEAR
        ];
        yield 'previous inactive presence excludes' => ['2027-06-01', true, true, $period, false, null];
        yield 'policy unconfirmed' => ['2027-06-01', true, false, null, null, GuestEligibility::UNKNOWN_PERIOD];
        yield 'previous year excludes despite unknown period' => ['2027-06-01', true, true, null, false, null];
        yield 'before every annual policy even with incomplete starts' => [
            '2026-12-31', false, null, null, false, null
        ];
        yield 'after every policy with complete dates' => ['2028-01-01', true, false, null, false, null];
        yield 'after year but incomplete dates' => [
            '2028-01-01', false, false, null, null, GuestEligibility::UNKNOWN_STARTS
        ];
        yield 'custom start included' => ['2027-05-15', true, false, ['2027-05-15', '2027-09-15'], true, null];
        yield 'custom end included' => ['2027-09-15', true, false, ['2027-05-15', '2027-09-15'], true, null];
        yield 'before custom period' => ['2027-05-14', true, false, ['2027-05-15', '2027-09-15'], false, null];
        yield 'after custom period' => ['2027-09-16', true, false, ['2027-05-15', '2027-09-15'], false, null];
    }

    #[DataProvider('cases')]
    public function testTheDecisionUsesOnlyProvenMembershipFacts(
        ?string $first,
        ?bool $complete,
        ?bool $previous,
        ?array $bounds,
        ?bool $qualifies,
        ?string $reason
    ): void {
        $period = $bounds === null ? null : [
            'from' => new \DateTimeImmutable($bounds[0]), 'until' => new \DateTimeImmutable($bounds[1]),
        ];
        $actual = GuestTariffRule::evaluate(new RosterMembershipFacts(
            $first === null ? null : new \DateTimeImmutable($first), $complete, $previous
        ), '2026-2027', $period);
        self::assertSame([$qualifies, $reason], [$actual->qualifies, $actual->reason]);
    }

    public function testMalformedYearCannotInventAnAnnualEnvelope(): void
    {
        $actual = GuestTariffRule::evaluate(
            new RosterMembershipFacts(new \DateTimeImmutable('2026-12-31'), true, false),
            'unknown',
            null
        );
        self::assertSame([null, GuestEligibility::UNKNOWN_PERIOD], [$actual->qualifies, $actual->reason]);
    }
}
