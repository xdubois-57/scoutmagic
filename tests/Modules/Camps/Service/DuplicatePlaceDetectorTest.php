<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class DuplicatePlaceDetectorTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->places = new PlaceRepository($this->pdo);
    }

    // ── Level 1: textual ────────────────────────────────────────────

    /**
     * @dataProvider sameNameSpellings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sameNameSpellings')]
    public function testTheSameNameWrittenDifferentlyIsFound(string $typed): void
    {
        $this->places->create('Domaine de Mozet', null, '5340', 'Mozet', 'Belgique', null);

        $found = $this->detector()->findCandidates(['name' => $typed, 'city' => 'Mozet']);

        $this->assertCount(1, $found, "« {$typed} » should match « Domaine de Mozet »");
        $this->assertSame('certain', $found[0]['certainty']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sameNameSpellings(): array
    {
        return [
            'identical' => ['Domaine de Mozet'],
            'lower case' => ['domaine de mozet'],
            'upper case' => ['DOMAINE DE MOZET'],
            'no accents typed' => ['Domaine de Mozet'],
            'hyphenated' => ['Domaine-de-Mozet'],
            'with a legal suffix' => ['Domaine de Mozet (asbl)'],
            'extra spaces' => ['  Domaine   de  Mozet '],
        ];
    }

    public function testAccentsFoldTogether(): void
    {
        $this->places->create('Ferme des Sources', null, null, 'Namur', null, null);

        $this->assertCount(1, $this->detector()->findCandidates(['name' => 'Fèrme dès Sourcès', 'city' => 'Namur']));
    }

    public function testATypoIsFoundButOnlyAsAPossibility(): void
    {
        $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $found = $this->detector()->findCandidates(['name' => 'Domaine de Mozzet', 'city' => 'Mozet']);

        $this->assertCount(1, $found);
        $this->assertSame('possible', $found[0]['certainty']);
    }

    public function testAShortNameToleratesNoTypoAtAll(): void
    {
        $this->places->create('Ry', null, null, 'Durbuy', null, null);

        // Two characters apart is a slip in "Domaine de Mozet" and a
        // different place entirely in "Ry".
        $this->assertSame([], $this->detector()->findCandidates(['name' => 'Xy', 'city' => 'Durbuy']));
    }

    public function testTwoGenuinelyDifferentPlacesAreNotConfused(): void
    {
        $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $this->assertSame([], $this->detector()->findCandidates([
            'name' => 'Ferme du Moulin', 'city' => 'Vielsalm',
        ]));
    }

    public function testTheSameAddressCountsEvenUnderAnotherName(): void
    {
        $this->places->create('Chez Delvaux', 'Rue du Tronquoy 4', '5340', 'Mozet', null, null);

        $found = $this->detector()->findCandidates([
            'name' => 'Le pré derrière la ferme',
            'address' => 'Rue du Tronquoy 4',
            'city' => 'Mozet',
        ]);

        $this->assertCount(1, $found);
        $this->assertSame('certain', $found[0]['certainty']);
    }

    public function testAnArchivedPlaceIsNotOfferedAsADuplicate(): void
    {
        $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $this->pdo->exec('UPDATE camp_places SET is_archived = 1');

        $this->assertSame([], $this->detector()->findCandidates(['name' => 'Domaine de Mozet', 'city' => 'Mozet']));
    }

    public function testANamelessSubjectIsNotCompared(): void
    {
        $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $this->assertSame([], $this->detector()->findCandidates(['name' => '   ', 'city' => 'Mozet']));
    }

    // ── Level 2: the AI, and its boundaries ─────────────────────────

    public function testTheAiIsAskedOnlyWhenTheTextComparisonCouldNotDecide(): void
    {
        $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $llm = $this->createMock(LlmConnectorInterface::class);
        // Level 1 already answered "certain" — spending a model call to
        // re-confirm it would be waste.
        $llm->expects($this->never())->method('complete');

        (new DuplicatePlaceDetector($this->places, $llm))
            ->findCandidates(['name' => 'Domaine de Mozet', 'city' => 'Mozet']);
    }

    public function testTheAiCanRecogniseAPlaceWrittenCompletelyDifferently(): void
    {
        $this->places->create('Ferme du Moulin', null, null, 'Vielsalm', null, null);
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(
            new LlmResponse('{"matches":[0]}', ['matches' => [0]], 10, 5)
        );

        $found = (new DuplicatePlaceDetector($this->places, $llm))
            ->findCandidates(['name' => 'Chez Delvaux', 'city' => 'Vielsalm']);

        $this->assertCount(1, $found);
        // NEVER 'certain'. The AI makes a suggestion a human accepts or
        // refuses; it must not be able to assert a merge.
        $this->assertSame('possible', $found[0]['certainty']);
    }

    public function testWithoutTheLlmModuleLevelOneStandsAlone(): void
    {
        $this->places->create('Ferme du Moulin', null, null, 'Vielsalm', null, null);

        $this->assertSame([], $this->detector()->findCandidates([
            'name' => 'Chez Delvaux', 'city' => 'Vielsalm',
        ]));
    }

    public function testADisabledConnectorIsNeverCalled(): void
    {
        $this->places->create('Ferme du Moulin', null, null, 'Vielsalm', null, null);
        $llm = $this->createMock(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(false);
        $llm->method('isTierAvailable')->willReturn(false);
        $llm->expects($this->never())->method('complete');

        $this->assertSame([], (new DuplicatePlaceDetector($this->places, $llm))
            ->findCandidates(['name' => 'Chez Delvaux', 'city' => 'Vielsalm']));
    }

    public function testAFailingModelNeverBreaksTheCreationForm(): void
    {
        $this->places->create('Ferme du Moulin', null, null, 'Vielsalm', null, null);
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willThrowException(new LlmException('provider down'));

        // A duplicate hint that throws would stop a chief creating a
        // place — worse than no hint at all.
        $this->assertSame([], (new DuplicatePlaceDetector($this->places, $llm))
            ->findCandidates(['name' => 'Chez Delvaux', 'city' => 'Vielsalm']));
    }

    public function testAModelAnsweringNonsenseIsIgnored(): void
    {
        $this->places->create('Ferme du Moulin', null, null, 'Vielsalm', null, null);
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(
            new LlmResponse('{"matches":[99,"deux",null]}', ['matches' => [99, 'deux', null]], 10, 5)
        );

        $this->assertSame([], (new DuplicatePlaceDetector($this->places, $llm))
            ->findCandidates(['name' => 'Chez Delvaux', 'city' => 'Vielsalm']));
    }

    private function detector(): DuplicatePlaceDetector
    {
        return new DuplicatePlaceDetector($this->places, null);
    }
}
