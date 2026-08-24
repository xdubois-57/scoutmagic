<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Service\CandidateDetector;
use Modules\Leadership\Service\ObligationsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

class ObligationsServiceTest extends TestCase
{
    private const TODAY = '2026-03-10';

    private function service(): ObligationsService
    {
        return new ObligationsService(new CandidateDetector());
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    /** A birth date whose 20th birthday falls $days from TODAY. */
    private function birthdayIn(int $days): string
    {
        return $this->today()
            ->modify('+' . $days . ' days')
            ->modify('-' . LeadershipRules::ADULT_AGE . ' years')
            ->format('Y-m-d');
    }

    public function testListsAnAnimatorTurningTwentyInsideTheWindow(): void
    {
        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['birthDate' => $this->birthdayIn(10)]),
        ], $this->today());

        $this->assertCount(1, $lines);
        $this->assertSame(10, $lines[0]->days);
        $this->assertStringContainsString('10 jours', (string) $lines[0]->note);
    }

    public function testIncludesTodayAndTheLastDayOfTheWindow(): void
    {
        $lastDay = LeadershipRules::adultAgeAlertDays();

        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['memberId' => 1, 'birthDate' => $this->birthdayIn(0)]),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'birthDate' => $this->birthdayIn($lastDay)]),
        ], $this->today());

        $this->assertCount(2, $lines);
        $this->assertSame(0, $lines[0]->days);
        $this->assertStringContainsString("aujourd'hui", (string) $lines[0]->note);
    }

    public function testExcludesBirthdaysOutsideTheWindow(): void
    {
        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['memberId' => 1, 'birthDate' => $this->birthdayIn(-1)]),
            LeadershipTestHelper::staffRow([
                'memberId' => 2,
                'birthDate' => $this->birthdayIn(LeadershipRules::adultAgeAlertDays() + 1),
            ]),
        ], $this->today());

        $this->assertSame([], $lines);
    }

    public function testSortsSoonestFirst(): void
    {
        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['memberId' => 1, 'firstName' => 'Aaa', 'birthDate' => $this->birthdayIn(30)]),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'firstName' => 'Bbb', 'birthDate' => $this->birthdayIn(3)]),
        ], $this->today());

        $this->assertSame([3, 30], array_map(static fn ($l) => $l->days, $lines));
    }

    public function testIgnoresMembersWithNoUsableBirthDate(): void
    {
        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['memberId' => 2, 'birthDate' => null]),
            LeadershipTestHelper::staffRow(['memberId' => 3, 'birthDate' => 'pas une date']),
        ], $this->today());

        $this->assertSame([], $lines);
    }

    public function testAnIntendantTurningTwentyIsListedToo(): void
    {
        // The extrait is required because of contact with minors, not
        // because of an animation function: filtering intendants out made
        // this page answer a narrower question than its title asks.
        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow([
                'memberId' => 1,
                'functionRole' => 'intendant',
                'functionLabel' => 'Intendant',
                'birthDate' => $this->birthdayIn(5),
            ]),
        ], $this->today());

        $this->assertCount(1, $lines);
        $this->assertSame(5, $lines[0]->days);
    }

    public function testTheUnknownBirthDateCountIsPerPersonAndMatchesTheScan(): void
    {
        // An empty birthday block means "nobody turns 20 soon" only when
        // every birth date is known.
        $staff = [
            LeadershipTestHelper::staffRow(['memberId' => 1, 'birthDate' => $this->birthdayIn(5)]),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'birthDate' => null]),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'birthDate' => null, 'functionLabel' => 'Autre']),
            LeadershipTestHelper::staffRow(['memberId' => 3, 'birthDate' => 'pas une date']),
        ];

        $this->assertSame(2, $this->service()->countWithoutBirthDate($staff));
    }

    /**
     * Somebody holding two animation functions is one person to warn, not
     * two lines to read.
     */
    public function testDeduplicatesAPersonHoldingTwoFunctions(): void
    {
        $birthDate = $this->birthdayIn(5);

        $lines = $this->service()->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['memberId' => 7, 'memberFunctionId' => 1, 'birthDate' => $birthDate]),
            LeadershipTestHelper::staffRow(['memberId' => 7, 'memberFunctionId' => 2, 'birthDate' => $birthDate]),
        ], $this->today());

        $this->assertCount(1, $lines);
    }

    /**
     * The whole point of the two messages: age can EXCLUDE the extrait, it
     * can never identify which document is actually missing.
     *
     * @return array<string, array{?int, string}>
     */
    public static function candidateMessages(): array
    {
        return [
            'clearly a minor' => [16, 'CQA à signer'],
            'the day before twenty' => [19, 'CQA à signer'],
            'exactly twenty' => [20, 'CQA ou extrait — à vérifier dans Desk'],
            'well over twenty' => [45, 'CQA ou extrait — à vérifier dans Desk'],
            // No age means nothing can be excluded, so the wider message is
            // the correct one — never the narrower one, which would be a
            // guess dressed as a fact.
            'age unknown' => [null, 'CQA ou extrait — à vérifier dans Desk'],
        ];
    }

    #[DataProvider('candidateMessages')]
    public function testCandidateMessageSaysNoMoreThanTheAgeAllows(?int $age, string $expected): void
    {
        $lines = $this->service()->candidates([
            LeadershipTestHelper::staffRow([
                'functionLabel' => 'Candidat animateur',
                'birthDate' => $age === null ? null : LeadershipTestHelper::birthDateForAge($age, $this->today()),
            ]),
        ], $this->today());

        $this->assertCount(1, $lines);
        $this->assertSame($expected, $lines[0]->note);
    }

    public function testCandidateWithoutABirthDateSaysTheAgeIsUnknown(): void
    {
        $lines = $this->service()->candidates([
            LeadershipTestHelper::staffRow(['functionLabel' => 'Candidat animateur', 'birthDate' => null]),
        ], $this->today());

        $this->assertStringContainsString('âge inconnu', (string) $lines[0]->detail);
    }

    public function testOnlyCandidateFunctionsAreListed(): void
    {
        $lines = $this->service()->candidates([
            LeadershipTestHelper::staffRow(['memberId' => 1, 'functionLabel' => 'Animateur']),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'functionLabel' => 'Candidate animatrice']),
        ], $this->today());

        $this->assertCount(1, $lines);
        $this->assertSame('Dupont', explode(' ', $lines[0]->fullName)[1]);
    }

    /**
     * One line per candidate FUNCTION: being flagged on one of two
     * functions is a different situation from being flagged on both, and
     * collapsing them would hide it.
     */
    public function testACandidateIsListedPerFlaggedFunction(): void
    {
        $lines = $this->service()->candidates([
            LeadershipTestHelper::staffRow([
                'memberId' => 7, 'memberFunctionId' => 1, 'functionLabel' => 'Candidat animateur',
            ]),
            LeadershipTestHelper::staffRow([
                'memberId' => 7, 'memberFunctionId' => 2, 'functionLabel' => 'Candidat intendant',
                'functionRole' => 'intendant',
            ]),
        ], $this->today());

        $this->assertCount(2, $lines);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function birthDateFormats(): array
    {
        return [
            'ISO' => ['2000-03-10', 26],
            'French slashes' => ['10/03/2000', 26],
            'French dashes' => ['10-03-2000', 26],
            'ISO with a time part' => ['2000-03-10 00:00:00', 26],
            'the day before the birthday' => ['2000-03-11', 25],
        ];
    }

    #[DataProvider('birthDateFormats')]
    public function testAgeAcceptsTheShapesDeskExports(string $birthDate, int $expected): void
    {
        $this->assertSame($expected, $this->service()->ageOn($birthDate, $this->today()));
    }

    /**
     * An impossible date is unknown, never PHP's overflow interpretation of
     * it (31 February would otherwise silently become 3 March).
     *
     * @return array<string, array{?string}>
     */
    public static function unusableBirthDates(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'prose' => ['inconnu'],
            'impossible day' => ['2000-02-31'],
        ];
    }

    #[DataProvider('unusableBirthDates')]
    public function testAgeIsNullWhenTheDateCannotBeTrusted(?string $birthDate): void
    {
        $this->assertNull($this->service()->ageOn($birthDate, $this->today()));
    }
}
