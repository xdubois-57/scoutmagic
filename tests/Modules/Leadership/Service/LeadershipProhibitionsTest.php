<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\CandidateDetector;
use Modules\Leadership\Service\ObligationsService;
use Modules\Leadership\Service\StewardService;
use Modules\Leadership\Value\PersonLine;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

/**
 * The other half of the module's central prohibition, on the surface where
 * breaking it would actually be dangerous: the sentence printed next to a
 * named person.
 *
 * Tests\Modules\Leadership\Controller\LeadershipRbacTest checks the whole
 * rendered page for the same claims, but a page also carries explanatory
 * prose that legitimately uses some of these words. Per-person text carries
 * no such prose: every string here was written by a service about one named
 * human, so ANY assertion about a document in one is a defect — the site
 * holds no such information, and printing a guess of it beside somebody's
 * name is the single most damaging thing this module could do.
 */
class LeadershipProhibitionsTest extends TestCase
{
    /**
     * Words that must never appear in per-person text, in any combination.
     * Stricter than the page-level list on purpose.
     *
     * @return list<string>
     */
    private static function forbiddenWords(): array
    {
        return [
            'en ordre', 'en règle', 'valide', 'expir', 'manquant', 'à jour',
            'conforme', 'éligible', 'ok', 'complet', 'incomplet', 'signé',
        ];
    }

    /**
     * @param list<PersonLine> $lines
     */
    private function assertNoClaims(array $lines): void
    {
        $this->assertNotEmpty($lines, 'The fixture must actually produce lines, or this proves nothing.');

        foreach ($lines as $line) {
            $text = mb_strtolower(($line->detail ?? '') . ' ' . ($line->note ?? ''));

            foreach (self::forbiddenWords() as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $text,
                    "Per-person text must never assert a paperwork status. Found « {$word} » in: {$text}"
                );
            }
        }
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-10');
    }

    public function testCandidateLinesAssertNothingAboutADocument(): void
    {
        $service = new ObligationsService(new CandidateDetector());

        $this->assertNoClaims($service->candidates([
            LeadershipTestHelper::staffRow([
                'memberId' => 1,
                'functionLabel' => 'Candidat animateur',
                'birthDate' => LeadershipTestHelper::birthDateForAge(17, $this->today()),
            ]),
            LeadershipTestHelper::staffRow([
                'memberId' => 2,
                'functionLabel' => 'Candidat animateur',
                'birthDate' => LeadershipTestHelper::birthDateForAge(25, $this->today()),
            ]),
            LeadershipTestHelper::staffRow([
                'memberId' => 3,
                'functionLabel' => 'Candidate animatrice',
                'birthDate' => null,
            ]),
        ], $this->today()));
    }

    public function testBirthdayLinesAssertNothingAboutADocument(): void
    {
        $service = new ObligationsService(new CandidateDetector());
        $birthDate = $this->today()->modify('+10 days')->modify('-20 years')->format('Y-m-d');

        $this->assertNoClaims($service->upcomingAdultBirthdays([
            LeadershipTestHelper::staffRow(['birthDate' => $birthDate]),
        ], $this->today()));
    }

    public function testStewardLinesAssertNothingAboutADocument(): void
    {
        $repository = $this->createStub(LeadershipRepository::class);
        $repository->method('findEarliestSectionPeriodStart')->willReturn('2026-01-15');

        $service = new StewardService($repository, new ObligationsService(new CandidateDetector()));

        $this->assertNoClaims($service->registrations([
            LeadershipTestHelper::staffRow([
                'memberId' => 1,
                'functionRole' => 'intendant',
                'functionStartDate' => '2026-01-01',
            ]),
            // The approximate-date branch, whose sentence is the longest
            // the module writes and therefore the likeliest to drift.
            LeadershipTestHelper::staffRow([
                'memberId' => 2,
                'functionRole' => 'intendant',
                'functionStartDate' => null,
            ]),
        ], 1, $this->today()));
    }

    /**
     * The other prohibition, and the subtler one: never say "en ordre"
     * about a non-candidate. The absence of a candidate flag at the last
     * import says what Desk thought then, not what is true now — so
     * somebody who is not on the candidates list gets no line at all,
     * rather than a reassuring one.
     */
    public function testANonCandidateGetsNoLineRatherThanAReassuringOne(): void
    {
        $service = new ObligationsService(new CandidateDetector());

        $lines = $service->candidates([
            LeadershipTestHelper::staffRow(['functionLabel' => 'Animateur']),
        ], $this->today());

        $this->assertSame([], $lines);
    }
}
