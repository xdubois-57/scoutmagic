<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Service\PassageCommentReviewService;
use Modules\Registration\Service\ReenrollmentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The AI re-reading of what families wrote in free text (IT-17), run by
 * « Répartir » before it distributes (issue #733).
 *
 * What matters is how little the site sends — only the people about to be
 * placed, each comment once — and how little it trusts what comes back:
 * a section or a name becomes an id only when it designates exactly one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PassageCommentReviewServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReenrollmentRepository $repository;
    private PassageNoteRepository $notes;
    private int $targetYearId;
    private int $currentYearId;
    private int $memberId;

    private const SECTION_A = 41;
    private const SECTION_B = 42;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $scoutYears = new ScoutYearService($this->pdo);
        $this->currentYearId = $scoutYears->ensureYear('2026-2027');
        $this->targetYearId = $scoutYears->ensureYear('2027-2028');

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_ONE')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $this->repository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $this->notes = new PassageNoteRepository($this->pdo, $this->encryption);
    }

    public function testWithoutTheConnectorNothingIsAvailableAndNothingIsSent(): void
    {
        $this->answerWithComment('On aimerait qu’il reste avec les copains de sa patrouille.');

        $service = $this->service(null);

        $this->assertFalse($service->isAvailable());
        $this->assertSame(0, $this->review($service));
        $this->assertNull($this->notes->find($this->memberId, $this->targetYearId));
    }

    public function testACommentIsSentOnceAndNeverAgain(): void
    {
        $this->answerWithComment('On aimerait qu’il reste avec les copains de sa patrouille.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Rester avec sa patrouille.']);
        $service = $this->service($connector);

        $this->assertSame(1, $this->review($service));
        $this->assertSame(1, $connector->calls);

        // The second round is the point: « Répartir » is pressed again.
        $this->assertSame(0, $this->review($service));
        $this->assertSame(1, $connector->calls);
    }

    public function testOnlyThePeopleAboutToBePlacedHaveTheirCommentSent(): void
    {
        $this->answerWithComment('Avec Zoé, svp.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Avec Zoé.', 'friends' => ['Zoé']]);
        $service = $this->service($connector);

        $this->assertSame(
            0,
            $service->reviewArrivals($this->targetYearId, $this->currentYearId, [999 => $this->arrival()]),
            'a child who is not changing branch in this run never has their family read'
        );
        $this->assertSame(0, $connector->calls);
    }

    public function testAFamilyEditingTheirCommentIsReadExactlyOnceMore(): void
    {
        $this->answerWithComment('Première version.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Un souhait.']);
        $service = $this->service($connector);
        $this->review($service);

        $this->answerWithComment('Deuxième version, tout autre chose.');

        $this->assertSame(1, $this->review($service));
        $this->assertSame(2, $connector->calls);
        $this->assertSame(0, $this->review($service));
        $this->assertSame(2, $connector->calls);
    }

    public function testACommentReadBeforeTheStructuredReadingIsReadOnceMore(): void
    {
        // A hash written by the IT-17 reading, which had no section and no
        // friends: without a fresh read, that comment would never count.
        $this->answerWithComment('Avec Zoé, svp.');
        $this->notes->setAiSuggestion(
            $this->memberId,
            $this->targetYearId,
            hash('sha256', 'Avec Zoé, svp.'),
            'Avec Zoé.'
        );

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Avec Zoé.']);

        $this->assertSame(1, $this->review($this->service($connector)));
    }

    public function testTheChildIsNeverNamedInWhatIsSent(): void
    {
        $this->answerWithComment('Léa voudrait rester avec Zoé.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Rester avec une amie.']);
        $this->review($this->service($connector));

        $this->assertNotNull($connector->lastRequest);
        $this->assertSame(
            'Léa voudrait rester avec Zoé.',
            $connector->lastRequest->prompt,
            'the comment goes alone — the provider reads a sentence, not a file on a child'
        );
        $this->assertStringNotContainsString(
            (string) $this->memberId,
            $connector->lastRequest->systemPrompt ?? '',
            'nothing identifies whose comment it is'
        );
        $this->assertStringContainsString(
            'Louveteaux A, Louveteaux B',
            $connector->lastRequest->systemPrompt ?? '',
            'the section names go along, so the answer can be resolved'
        );
    }

    public function testTheReadingIsStoredStructuredAndResolvedToIds(): void
    {
        $this->answerWithComment('Elle aimerait aller chez les Louveteaux B avec Zoé et Paul.');

        $service = $this->service(
            $this->connector([
                'has_wish' => true,
                'summary' => 'Louveteaux B, avec Zoé et Paul.',
                'section' => 'louveteaux b',
                'friends' => ['Zoé', 'Paul'],
            ]),
            ['Zoé' => [501], 'Paul' => [502]]
        );
        $this->review($service);

        $stored = $this->notes->find($this->memberId, $this->targetYearId);
        $this->assertNotNull($stored);
        $this->assertSame('Louveteaux B, avec Zoé et Paul.', $stored['ai_suggestion']);
        $this->assertSame(self::SECTION_B, $stored['ai_section_id']);
        $this->assertSame([501, 502], $stored['ai_friend_member_ids']);
        $this->assertFalse($stored['ai_confirmed']);
    }

    public function testAnAmbiguousReadingNeverBecomesAChoice(): void
    {
        $this->answerWithComment('Chez les Louveteaux, avec Léo et un copain.');

        $service = $this->service(
            $this->connector([
                'has_wish' => true,
                'summary' => 'Louveteaux, avec Léo.',
                // Both sections of the branch are « Louveteaux … ».
                'section' => 'Louveteaux',
                // Two Léos, and a name nobody carries.
                'friends' => ['Léo', 'Inconnu'],
            ]),
            ['Léo' => [601, 602], 'Inconnu' => []]
        );
        $this->review($service);

        $stored = $this->notes->find($this->memberId, $this->targetYearId);
        $this->assertNotNull($stored);
        $this->assertNull($stored['ai_section_id']);
        $this->assertSame([], $stored['ai_friend_member_ids']);
    }

    public function testASectionOutsideTheArrivalBranchIsDropped(): void
    {
        $this->answerWithComment('Chez les Éclaireurs.');

        $this->review($this->service($this->connector([
            'has_wish' => true,
            'summary' => 'Éclaireurs.',
            'section' => 'Éclaireurs',
            'friends' => [],
        ])));

        $this->assertNull($this->notes->find($this->memberId, $this->targetYearId)['ai_section_id']);
    }

    public function testAConfirmationDoesNotSurviveTheCommentItWasAbout(): void
    {
        $this->answerWithComment('Première version.');
        $connector = $this->connector(['has_wish' => true, 'summary' => 'Un souhait.']);
        $service = $this->service($connector);
        $this->review($service);
        $this->notes->confirmAiSuggestion($this->memberId, $this->targetYearId, true);

        $this->answerWithComment('Deuxième version.');
        $this->review($service);

        $this->assertFalse(
            $this->notes->find($this->memberId, $this->targetYearId)['ai_confirmed'],
            'a validation belongs to the sentence it was given for'
        );
    }

    public function testAModelSayingThereIsNoWishStoresNothingButStillCountsAsRead(): void
    {
        $this->answerWithComment('Merci pour tout, très belle année !');

        $connector = $this->connector(['has_wish' => false, 'summary' => null, 'section' => 'Louveteaux B']);
        $service = $this->service($connector);
        $this->review($service);

        $stored = $this->notes->find($this->memberId, $this->targetYearId);
        $this->assertNull($stored['ai_suggestion']);
        $this->assertNull($stored['ai_section_id'], 'no wish means no section, whatever else came back');
        $this->assertSame(0, $this->review($service), 'read is read, wish or no wish');
        $this->assertSame(1, $connector->calls);
    }

    /**
     * Whatever drives the placement is what the page shows under the line,
     * and the page shows the summary: a wish without one is no wish.
     */
    public function testAWishWithoutASummaryStoresNoSectionAndNoFriend(): void
    {
        $this->answerWithComment('Louveteaux B avec Zoé.');

        $this->review($this->service(
            $this->connector(['has_wish' => true, 'summary' => '  ', 'section' => 'Louveteaux B', 'friends' => ['Zoé']]),
            ['Zoé' => [501]]
        ));

        $stored = $this->notes->find($this->memberId, $this->targetYearId);
        $this->assertNull($stored['ai_suggestion']);
        $this->assertNull($stored['ai_section_id']);
        $this->assertSame([], $stored['ai_friend_member_ids']);
    }

    /**
     * A family taking their comment back takes the wish with it — even
     * with the AI switched off since: forgetting needs no reading.
     */
    public function testAWithdrawnCommentStopsSteeringThePlacement(): void
    {
        $this->answerWithComment('Chez les Louveteaux B, avec Zoé.');
        $this->review($this->service(
            $this->connector(['has_wish' => true, 'summary' => 'Louveteaux B, avec Zoé.', 'section' => 'Louveteaux B', 'friends' => ['Zoé']]),
            ['Zoé' => [501]]
        ));
        $this->assertSame(self::SECTION_B, $this->notes->find($this->memberId, $this->targetYearId)['ai_section_id']);

        $this->repository->saveAnswer($this->memberId, $this->targetYearId, 'reenrolled', null, null, null, []);
        $this->assertSame(0, $this->review($this->service(null)));

        $stored = $this->notes->find($this->memberId, $this->targetYearId);
        $this->assertNull($stored['ai_suggestion']);
        $this->assertNull($stored['ai_section_id']);
        $this->assertSame([], $stored['ai_friend_member_ids']);
    }

    public function testAFailingProviderCostsNothingAndIsAskedAgainNextTime(): void
    {
        $this->answerWithComment('Un commentaire quelconque.');

        $connector = new class implements LlmConnectorInterface {
            public int $calls = 0;

            public function isAvailable(): bool
            {
                return true;
            }

            public function isTierAvailable(LlmTier $tier): bool
            {
                return true;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;
                throw new LlmException('Le fournisseur ne répond pas.');
            }
        };

        $service = $this->service($connector);

        $this->assertSame(0, $this->review($service));
        $this->assertNull($this->notes->find($this->memberId, $this->targetYearId));
        $this->assertSame(0, $this->review($service));
        $this->assertSame(2, $connector->calls, 'a failed read is not a read: the next run tries again');
    }

    public function testAnAnswerWithoutACommentIsNeverSentAnywhere(): void
    {
        $this->repository->saveAnswer($this->memberId, $this->targetYearId, 'reenrolled', null, null, null, []);

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Quelque chose.']);

        $this->assertSame(0, $this->review($this->service($connector)));
        $this->assertSame(0, $connector->calls);
    }

    /**
     * @param array<string, array<int, int>> $candidatesByName the member
     *        ids the module's name matcher finds for each name
     */
    private function service(?LlmConnectorInterface $connector, array $candidatesByName = []): PassageCommentReviewService
    {
        $reenrollment = $this->createStub(ReenrollmentService::class);
        $reenrollment->method('candidatesFor')->willReturnCallback(
            static fn(string $name): array => array_map(
                static fn(int $id): array => ['member_id' => $id, 'label' => 'Membre ' . $id],
                $candidatesByName[$name] ?? []
            )
        );

        return new PassageCommentReviewService($this->repository, $this->notes, $reenrollment, $connector);
    }

    private function review(PassageCommentReviewService $service): int
    {
        return $service->reviewArrivals(
            $this->targetYearId,
            $this->currentYearId,
            [$this->memberId => $this->arrival()]
        );
    }

    /**
     * @return array{branch_id: int, sections: array<int, array<string, mixed>>}
     */
    private function arrival(): array
    {
        return [
            'branch_id' => 2,
            'sections' => [
                ['id' => self::SECTION_A, 'name' => 'Louveteaux A', 'desk_code' => 'LA', 'age_branch_id' => 2],
                ['id' => self::SECTION_B, 'name' => 'Louveteaux B', 'desk_code' => 'LB', 'age_branch_id' => 2],
            ],
        ];
    }

    private function answerWithComment(string $comment): void
    {
        $this->repository->saveAnswer($this->memberId, $this->targetYearId, 'reenrolled', null, $comment, null, []);
    }

    /**
     * A connector that answers with `$parsed` and remembers what it was
     * asked — a real double rather than a mock, so the test can assert on
     * WHAT was sent, which is the half that matters here.
     *
     * @param array<string, mixed> $parsed
     */
    private function connector(array $parsed): LlmConnectorInterface
    {
        return new class ($parsed) implements LlmConnectorInterface {
            public int $calls = 0;
            public ?LlmRequest $lastRequest = null;

            /** @param array<string, mixed> $parsed */
            public function __construct(private array $parsed)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function isTierAvailable(LlmTier $tier): bool
            {
                return true;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;
                $this->lastRequest = $request;

                return new LlmResponse(
                    content: (string) json_encode($this->parsed),
                    parsed: $this->parsed,
                    inputTokens: 1,
                    outputTokens: 1
                );
            }
        };
    }
}
