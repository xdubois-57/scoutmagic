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
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * IT-17's optional AI re-reading of what families wrote in free text.
 *
 * The three properties that matter are not about the model's answer at
 * all — they are about how little the site sends and how little it
 * trusts what comes back.
 *
 * @group database
 */
class PassageCommentReviewServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReenrollmentRepository $repository;
    private PassageNoteRepository $notes;
    private int $targetYearId;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $scoutYears = new ScoutYearService($this->pdo);
        $scoutYears->ensureYear('2026-2027');
        $this->targetYearId = $scoutYears->ensureYear('2027-2028');

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_ONE')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $this->repository = new ReenrollmentRepository($this->pdo, $this->encryption);
        $this->notes = new PassageNoteRepository($this->pdo, $this->encryption);
    }

    public function testWithoutTheConnectorNothingIsAvailableAndNothingIsSent(): void
    {
        $this->answerWithComment('On aimerait qu’il reste avec les copains de sa patrouille.');

        $service = new PassageCommentReviewService($this->repository, $this->notes, null);

        $this->assertFalse($service->isAvailable());
        $this->assertSame(0, $service->pendingCount($this->targetYearId));
        $this->assertSame(0, $service->reviewPending($this->targetYearId));
        $this->assertNull($this->notes->find($this->memberId, $this->targetYearId));
    }

    public function testACommentIsSentOnceAndNeverAgain(): void
    {
        $this->answerWithComment('On aimerait qu’il reste avec les copains de sa patrouille.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Rester avec sa patrouille.']);
        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);

        $this->assertSame(1, $service->pendingCount($this->targetYearId));
        $this->assertSame(1, $service->reviewPending($this->targetYearId));
        $this->assertSame(1, $connector->calls);

        // The second round is the point: the page will be opened many
        // times, and the button pressed again.
        $this->assertSame(0, $service->pendingCount($this->targetYearId));
        $this->assertSame(0, $service->reviewPending($this->targetYearId));
        $this->assertSame(1, $connector->calls);
    }

    public function testAFamilyEditingTheirCommentIsReadExactlyOnceMore(): void
    {
        $this->answerWithComment('Première version.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Un souhait.']);
        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);
        $service->reviewPending($this->targetYearId);

        $this->answerWithComment('Deuxième version, tout autre chose.');

        $this->assertSame(1, $service->reviewPending($this->targetYearId));
        $this->assertSame(2, $connector->calls);
        $this->assertSame(0, $service->reviewPending($this->targetYearId));
        $this->assertSame(2, $connector->calls);
    }

    public function testTheChildIsNeverNamedInWhatIsSent(): void
    {
        $this->answerWithComment('Léa voudrait rester avec Zoé.');

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Rester avec une amie.']);
        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);
        $service->reviewPending($this->targetYearId);

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
    }

    public function testASuggestionArrivesUnconfirmedAndStaysThatWayUntilAHumanSaysOtherwise(): void
    {
        $this->answerWithComment('On aimerait la même section que son frère.');

        $service = new PassageCommentReviewService(
            $this->repository,
            $this->notes,
            $this->connector(['has_wish' => true, 'summary' => 'La même section que son frère.'])
        );
        $service->reviewPending($this->targetYearId);

        $stored = $this->notes->find($this->memberId, $this->targetYearId);
        $this->assertNotNull($stored);
        $this->assertSame('La même section que son frère.', $stored['ai_suggestion']);
        $this->assertFalse($stored['ai_confirmed']);

        $this->notes->confirmAiSuggestion($this->memberId, $this->targetYearId, true);
        $this->assertTrue($this->notes->find($this->memberId, $this->targetYearId)['ai_confirmed']);
    }

    public function testAConfirmationDoesNotSurviveTheCommentItWasAbout(): void
    {
        $this->answerWithComment('Première version.');
        $connector = $this->connector(['has_wish' => true, 'summary' => 'Un souhait.']);
        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);
        $service->reviewPending($this->targetYearId);
        $this->notes->confirmAiSuggestion($this->memberId, $this->targetYearId, true);

        $this->answerWithComment('Deuxième version.');
        $service->reviewPending($this->targetYearId);

        $this->assertFalse(
            $this->notes->find($this->memberId, $this->targetYearId)['ai_confirmed'],
            'a validation belongs to the sentence it was given for'
        );
    }

    public function testAModelSayingThereIsNoWishStoresNothingButStillCountsAsRead(): void
    {
        $this->answerWithComment('Merci pour tout, très belle année !');

        $connector = $this->connector(['has_wish' => false, 'summary' => null]);
        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);
        $service->reviewPending($this->targetYearId);

        $this->assertNull($this->notes->find($this->memberId, $this->targetYearId)['ai_suggestion']);
        $this->assertSame(0, $service->pendingCount($this->targetYearId), 'read is read, wish or no wish');
    }

    public function testAFailingProviderCostsTheChiefNothingButTheHint(): void
    {
        $this->answerWithComment('Un commentaire quelconque.');

        $connector = new class implements LlmConnectorInterface {
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
                throw new LlmException('Le fournisseur ne répond pas.');
            }
        };

        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);

        $this->assertSame(1, $service->reviewPending($this->targetYearId));
        $this->assertNull($this->notes->find($this->memberId, $this->targetYearId)['ai_suggestion']);
    }

    public function testAnAnswerWithoutACommentIsNeverSentAnywhere(): void
    {
        $this->repository->saveAnswer($this->memberId, $this->targetYearId, 'reenrolled', null, null, null, []);

        $connector = $this->connector(['has_wish' => true, 'summary' => 'Quelque chose.']);
        $service = new PassageCommentReviewService($this->repository, $this->notes, $connector);

        $this->assertSame(0, $service->pendingCount($this->targetYearId));
        $this->assertSame(0, $service->reviewPending($this->targetYearId));
        $this->assertSame(0, $connector->calls);
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
