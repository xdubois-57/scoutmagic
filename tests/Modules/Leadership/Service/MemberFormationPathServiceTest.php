<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\FormationLevelResolver;
use Modules\Leadership\Service\MemberFormationPathService;
use PHPUnit\Framework\TestCase;

class MemberFormationPathServiceTest extends TestCase
{
    /**
     * @param array<string, string> $mapping
     */
    private function service(?string $rawLevel, array $mapping = []): MemberFormationPathService
    {
        $repository = $this->createStub(LeadershipRepository::class);
        $repository->method('findFormationLevelForMember')->willReturn($rawLevel);

        $mappingRepository = $this->createStub(FormationLevelMappingRepository::class);
        $mappingRepository->method('findAll')->willReturn($mapping);

        return new MemberFormationPathService($repository, $mappingRepository, new FormationLevelResolver());
    }

    public function testDrawsThePathUpToTheStepReached(): void
    {
        $path = $this->service('T2')->getFormationPath(1, 7);

        $this->assertNotNull($path);
        $this->assertSame(
            // The path ends at the BACV since IT-19 — the brevet the ONE
            // recognises, rather than a brevet of unstated kind.
            ['T1' => true, 'T2' => true, 'T3' => false, 'BACV' => false],
            array_combine(
                array_column($path->steps, 'label'),
                array_column($path->steps, 'reached')
            )
        );
        $this->assertSame('T3', $path->nextLabel);
        $this->assertTrue($path->isRecognised);
    }

    public function testMarksTheCurrentStep(): void
    {
        $path = $this->service('T2')->getFormationPath(1, 7);

        $this->assertNotNull($path);
        $current = array_values(array_filter($path->steps, static fn (array $s): bool => $s['current']));

        $this->assertCount(1, $current);
        $this->assertSame('T2', $current[0]['label']);
    }

    public function testTheBrevetIsTheEndOfThePath(): void
    {
        $path = $this->service('Brevet')->getFormationPath(1, 7);

        $this->assertNotNull($path);
        $this->assertSame([true, true, true, true], array_column($path->steps, 'reached'));
        $this->assertNull($path->nextLabel);
    }

    /**
     * Nothing encoded is a real, honest state — not an unknown one — and
     * the site says so in those words rather than showing a dash.
     */
    public function testNothingEncodedReadsAsNoFormationRatherThanUnknown(): void
    {
        $path = $this->service(null)->getFormationPath(1, 7);

        $this->assertNotNull($path);
        $this->assertTrue($path->isRecognised);
        $this->assertSame([false, false, false, false], array_column($path->steps, 'reached'));
        $this->assertStringContainsString("Aucun niveau de formation n'est encodé", $path->currentLabel);
        // No next step is stated: guessing that somebody who has started
        // nothing will start with T1 is an estimate, and this module does
        // not show estimates.
        $this->assertNull($path->nextLabel);
    }

    /**
     * An unresolvable value lights up no milestone at all. Drawing the path
     * partly filled would be the site asserting progress it cannot read.
     */
    public function testAnUnrecognisedValueReachesNothingAndSaysSo(): void
    {
        $path = $this->service('Module transversal 4')->getFormationPath(1, 7);

        $this->assertNotNull($path);
        $this->assertFalse($path->isRecognised);
        $this->assertSame([false, false, false, false], array_column($path->steps, 'reached'));
        $this->assertNull($path->nextLabel);
        $this->assertStringContainsString("n'est pas reconnu", $path->currentLabel);
    }

    public function testTheAdminMappingReachesTheMemberPage(): void
    {
        $path = $this->service('Module transversal 4', [
            FormationLevelResolver::keyFor('Module transversal 4') => FormationStep::T3->value,
        ])->getFormationPath(1, 7);

        $this->assertNotNull($path);
        $this->assertTrue($path->isRecognised);
        $this->assertSame('BACV', $path->nextLabel);
    }
}
