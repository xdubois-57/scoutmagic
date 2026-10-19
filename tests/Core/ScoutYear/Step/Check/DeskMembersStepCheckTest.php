<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\Step\Check\DeskMembersStepCheck;
use Modules\Registration\Api\MissingAcceptedRequest;
use Modules\Registration\Api\PassageMismatch;
use Modules\Registration\Api\PreparedImportGaps;
use Modules\Registration\Api\ScoutYearMember;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 5 (chantier « Année scoute » §2.5): the three gaps between what was
 * prepared and the target year's import, asked only once that import
 * exists.
 */
class DeskMembersStepCheckTest extends TestCase
{
    public function testBeforeTheImportTheGapsAreNotEvenAsked(): void
    {
        $review = $this->createMock(ScoutYearRegistrationReviewProvider::class);
        $review->expects($this->never())->method('preparedImportGaps');

        $check = (new DeskMembersStepCheck($review, $this->createStub(SectionService::class)))
            ->check(CheckFixtures::context(imported: false));

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertArrayNotHasKey('gaps', $check->details);
    }

    public function testTheThreeGapsAreListedAndCounted(): void
    {
        $review = $this->createStub(ScoutYearRegistrationReviewProvider::class);
        $review->method('preparedImportGaps')->willReturn(new PreparedImportGaps(
            [new ScoutYearMember(1, 101, 'Tom Renard', 2), new ScoutYearMember(2, 102, 'Inès Dupont', 2)],
            [new PassageMismatch(3, 103, 'Max Collin', 4, 5)],
            [new MissingAcceptedRequest(77, 'Zoé Henry', MissingAcceptedRequest::STATUS_ACCEPTED, null)]
        ));
        $sections = $this->createStub(SectionService::class);
        $sections->method('findByIds')->willReturn([
            4 => CheckFixtures::sectionRow(4, 'Éclaireurs'),
            5 => CheckFixtures::sectionRow(5, 'Pionniers'),
        ]);

        $check = (new DeskMembersStepCheck($review, $sections))->check(CheckFixtures::context());

        $this->assertSame([
            "2 partants présents dans l'import",
            '1 passage dans une autre section',
            '1 inscription acceptée non retrouvée',
        ], $check->pointsToCheck);
        $this->assertSame([
            ['kind' => 'leaver', 'label' => 'Annoncé partant, présent en 2026-2027', 'name' => 'Tom Renard', 'url' => '/admin/members/101'],
            ['kind' => 'leaver', 'label' => 'Annoncé partant, présent en 2026-2027', 'name' => 'Inès Dupont', 'url' => '/admin/members/102'],
            ['kind' => 'passage', 'label' => 'Passage vers Éclaireurs, importé en Pionniers', 'name' => 'Max Collin', 'url' => '/admin/members/103'],
            ['kind' => 'request', 'label' => "Inscription acceptée, absente de l'import", 'name' => 'Zoé Henry', 'url' => '/config/inscriptions/demandes/77'],
        ], $check->details['gaps']);
    }

    public function testNoGapIsNoPoint(): void
    {
        $review = $this->createStub(ScoutYearRegistrationReviewProvider::class);
        $review->method('preparedImportGaps')->willReturn(new PreparedImportGaps([], [], []));

        $check = (new DeskMembersStepCheck($review, $this->createStub(SectionService::class)))->check(CheckFixtures::context());

        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame([], $check->details['gaps']);
    }

    public function testWithoutTheRegistrationModuleThereIsNothingToCompare(): void
    {
        $check = (new DeskMembersStepCheck(null, $this->createStub(SectionService::class)))->check(CheckFixtures::context());

        $this->assertSame([], $check->pointsToCheck);
        $this->assertNull($check->pendingMessage);
    }
}
