<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Assistant;

use Core\Help\Assistant\AssistantCacheRepository;
use Core\Help\Assistant\AssistantCatalog;
use Core\Help\Assistant\AssistantException;
use Core\Help\Assistant\AssistantRateLimitRepository;
use Core\Help\Assistant\AssistantService;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\Role;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmTier;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Core\Help\HelpTopicFileFixtures;

/**
 * The assistant's engine, against a scripted connector.
 *
 * Two of these tests are about what the model is SENT rather than what it
 * answers, and they are the ones that matter most: the catalogue must be
 * filtered by role, and no unit data may enter a prompt. Both are claims
 * about the prompt text, and FakeLlmConnector keeps it so they can be
 * made as assertions rather than as comments.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AssistantServiceTest extends TestCase
{
    use HelpTopicFileFixtures;

    private \PDO $pdo;
    private string $topicDir;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->topicDir = $this->makeTopicDir();

        $this->writeTopic($this->topicDir, 'envoi-de-mails', [
            'title' => 'Envoyer un e-mail groupé',
            'summary' => 'Du brouillon au test, puis l\'envoi.',
            'role_min' => 'chief',
            'question' => ["Comment prévenir tous les parents d'une section ?"],
        ], "Ouvrez « Nouveau message », choisissez l'audience, puis testez sur vous-même.\n");

        $this->writeTopic($this->topicDir, 'journal', [
            'title' => 'Consulter le journal du site',
            'summary' => 'Qui a fait quoi, et quand.',
            'role_min' => 'admin',
            'question' => ['Qui a changé cela, et quand ?'],
        ], "Le journal consigne les actions notables.\n");

        $this->writeTopic($this->topicDir, 'calendrier', [
            'title' => 'Consulter le calendrier',
            'summary' => 'Les activités, mois par mois.',
            'role_min' => 'public',
            'question' => ['Où voir les dates des prochaines activités ?'],
        ], "Naviguez avec « Mois précédent » et « Mois suivant ».\n");
    }

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    private function service(?FakeLlmConnector $connector, string $version = '1.2.3'): AssistantService
    {
        $helpService = new HelpService(new HelpRegistry($this->topicDir));

        return new AssistantService(
            $helpService,
            new AssistantCatalog($helpService),
            new AssistantRateLimitRepository($this->pdo),
            new AssistantCacheRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo)),
            $version,
            $connector
        );
    }

    private function connectorAnswering(array $ids, string $answer = 'Voici comment faire.'): FakeLlmConnector
    {
        return (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, FakeLlmConnector::selection($ids))
            ->willAnswer(LlmTier::CAPABLE, FakeLlmConnector::answer($answer));
    }

    // --- Availability -------------------------------------------------

    public function testWithoutTheConnectorTheAssistantIsSimplyUnavailable(): void
    {
        // llm_connector disabled: the nullable Api dependency is null and
        // the feature degrades, it does not break (ARCHITECTURE.md §7.5).
        $service = $this->service(null);

        $this->assertFalse($service->isAvailable());

        $this->expectException(AssistantException::class);
        $this->expectExceptionMessage("La recherche dans l'aide, elle, fonctionne toujours");
        $service->ask('Comment écrire aux parents ?', Role::CHIEF, 1);
    }

    public function testATierWithoutAModelMakesTheAssistantUnavailable(): void
    {
        // isAvailable() only answers "is anything configured at all", so a
        // connector with no CAPABLE model would pass it and fail at the
        // answering call. Both tiers are checked.
        $connector = (new FakeLlmConnector())->withTierUnavailable(LlmTier::CAPABLE);

        $this->assertFalse($this->service($connector)->isAvailable());
        $this->assertTrue($this->service(new FakeLlmConnector())->isAvailable());
    }

    // --- The role gate ------------------------------------------------

    public function testTheCatalogueSentToTheModelIsFilteredByRole(): void
    {
        $connector = $this->connectorAnswering(['calendrier']);
        $this->service($connector)->ask('Où voir les activités ?', Role::CHIEF, 1);

        $prompt = (string) $connector->promptFor(LlmTier::CHEAP);

        // A chief sees the public and chief topics…
        $this->assertStringContainsString('calendrier |', $prompt);
        $this->assertStringContainsString('envoi-de-mails |', $prompt);
        // …and the admin-only one is not in the text at all. Not "the
        // model was told to ignore it" — it never received it.
        $this->assertStringNotContainsString('journal', $prompt);
    }

    public function testAnIdTheModelInventedDisappearsSilently(): void
    {
        $connector = $this->connectorAnswering(['calendrier', 'sujet-invente']);
        $answer = $this->service($connector)->ask('Où voir les activités ?', Role::CHIEF, 1);

        $this->assertSame(['calendrier'], $answer->topicIds());
    }

    public function testAnIdAboveTheAskersRoleIsRefusedEvenIfTheModelReturnsIt(): void
    {
        // The catalogue never held it, so this is the model going wrong —
        // and findById() at the caller's role is what makes it harmless.
        $connector = $this->connectorAnswering(['journal']);
        $answer = $this->service($connector)->ask('Qui a changé cela ?', Role::CHIEF, 1);

        $this->assertSame([], $answer->topicIds());
        $this->assertTrue($answer->foundNothing);
        // …and the answering call never happened: nothing to answer from.
        $this->assertNull($connector->promptFor(LlmTier::CAPABLE));
    }

    // --- The privacy claim --------------------------------------------

    public function testTheAnsweringPromptCarriesTopicBodiesAndTheQuestionOnly(): void
    {
        $connector = $this->connectorAnswering(['envoi-de-mails']);
        $this->service($connector)->ask('Comment prévenir les parents ?', Role::CHIEF, 42);

        $prompt = (string) $connector->promptFor(LlmTier::CAPABLE);

        $this->assertStringContainsString('Ouvrez « Nouveau message »', $prompt);
        $this->assertStringContainsString('Comment prévenir les parents ?', $prompt);
        // Nothing identifies the asker or their unit: the account id is
        // what the quota is charged to, never something the model sees.
        $this->assertStringNotContainsString('42', $prompt);
    }

    public function testTheQuestionTextIsNeverJournaled(): void
    {
        $connector = $this->connectorAnswering(['envoi-de-mails']);
        $this->service($connector)->ask('Le père de Lucie Dupont a-t-il payé ?', Role::CHIEF, 1);

        $rows = $this->pdo->query('SELECT description, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $everything = json_encode($rows);
        $this->assertStringNotContainsString('Lucie', (string) $everything);
        $this->assertStringNotContainsString('Dupont', (string) $everything);
        // …but the counters and the ids that were consulted are there.
        $this->assertStringContainsString('envoi-de-mails', (string) $everything);
        $this->assertStringContainsString('selection_tokens', (string) $everything);
    }

    // --- Outcomes ------------------------------------------------------

    public function testNothingFoundIsAnOutcomeRatherThanAnError(): void
    {
        $connector = $this->connectorAnswering([]);
        $answer = $this->service($connector)->ask('Comment réparer ma voiture ?', Role::CHIEF, 1);

        $this->assertTrue($answer->foundNothing);
        $this->assertSame('', $answer->text);
        $this->assertSame([], $answer->topicIds());
        $this->assertSame(1, $connector->callCount(), 'the answering call must not happen');
    }

    public function testATruncatedAnswerIsAFailureRatherThanHalfAnAnswer(): void
    {
        $connector = (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, FakeLlmConnector::selection(['envoi-de-mails']))
            ->willAnswer(LlmTier::CAPABLE, FakeLlmConnector::answer('Ouvrez la page et cliquez sur', true));

        $this->expectException(AssistantException::class);
        $this->expectExceptionMessage('réponse complète');
        $this->service($connector)->ask('Comment écrire aux parents ?', Role::CHIEF, 1);
    }

    public function testAConnectorFailureReachesTheCallerAsItComes(): void
    {
        // LlmException already implements UserFacingException and already
        // carries a French sentence — re-wrapping it would relabel a
        // technical message as user-facing (AGENTS.md § Exception messages).
        $connector = (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, new LlmException('Le fournisseur d\'IA ne répond pas.'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage("Le fournisseur d'IA ne répond pas.");
        $this->service($connector)->ask('Comment écrire aux parents ?', Role::CHIEF, 1);
    }

    // --- Quota ---------------------------------------------------------

    public function testTheQuotaIsPerAccountAndRefusesOnceSpent(): void
    {
        $service = $this->service($this->connectorAnswering(['envoi-de-mails']));

        for ($i = 0; $i < AssistantService::QUOTA_PER_WINDOW; $i++) {
            // A different question each time, so the cache never answers
            // and every one of them really spends an allowance.
            $service->ask("Question numéro {$i} ?", Role::CHIEF, 7);
        }

        // Another account is untouched by the first one's spending.
        $service->ask('Une question de quelqu\'un d\'autre ?', Role::CHIEF, 8);

        $this->expectException(AssistantException::class);
        $this->expectExceptionMessage('Réessayez dans une heure');
        $service->ask('Une question de trop ?', Role::CHIEF, 7);
    }

    public function testAFailedCallStillSpendsItsAllowance(): void
    {
        // Otherwise a failing provider is an unlimited retry loop.
        $connector = (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, new LlmException('Le fournisseur d\'IA ne répond pas.'));
        $service = $this->service($connector);

        try {
            $service->ask('Une question ?', Role::CHIEF, 9);
        } catch (LlmException $e) {
            // expected
        }

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM help_assistant_rate_limits')->fetchColumn();
        $this->assertSame(1, $count);
    }

    // --- Cache ---------------------------------------------------------

    public function testTheSameQuestionAskedTwiceCostsOneCall(): void
    {
        $connector = $this->connectorAnswering(['envoi-de-mails'], 'Ouvrez la page « Nouveau message ».');
        $service = $this->service($connector);

        $first = $service->ask('Comment écrire aux parents ?', Role::CHIEF, 1);
        $second = $service->ask('  COMMENT écrire aux parents ?  ', Role::CHIEF, 1);

        $this->assertSame($first->text, $second->text);
        $this->assertSame($first->topicIds(), $second->topicIds());
        $this->assertFalse($first->fromCache);
        $this->assertTrue($second->fromCache);
        $this->assertSame(2, $connector->callCount(), 'the second question must not reach the model');
    }

    public function testACacheHitCostsNoQuota(): void
    {
        $service = $this->service($this->connectorAnswering(['envoi-de-mails']));
        $service->ask('Comment écrire aux parents ?', Role::CHIEF, 5);
        $service->ask('Comment écrire aux parents ?', Role::CHIEF, 5);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM help_assistant_rate_limits')->fetchColumn();
        $this->assertSame(1, $count, 'an answer that cost nothing must not spend an allowance');
    }

    public function testTwoRolesAskingTheSameWordsAreTwoDifferentQuestions(): void
    {
        // The catalogue they are answered from differs, so the answers are
        // not interchangeable and one must never be served to the other.
        $connector = $this->connectorAnswering(['calendrier']);
        $service = $this->service($connector);

        $service->ask('Où voir les activités ?', Role::CHIEF, 1);
        $service->ask('Où voir les activités ?', Role::ADMIN, 2);

        $this->assertSame(4, $connector->callCount());
    }

    public function testAReleaseInvalidatesEveryCachedAnswerAtOnce(): void
    {
        $connector = $this->connectorAnswering(['calendrier']);
        $this->service($connector, '1.2.3')->ask('Où voir les activités ?', Role::CHIEF, 1);
        $this->service($connector, '1.2.4')->ask('Où voir les activités ?', Role::CHIEF, 1);

        $this->assertSame(4, $connector->callCount(), 'the version is part of the key, so a release re-asks');
    }

    public function testTheQuestionTextIsNeverStoredInTheCache(): void
    {
        $service = $this->service($this->connectorAnswering(['envoi-de-mails']));
        $service->ask('Le père de Lucie Dupont a-t-il payé ?', Role::CHIEF, 1);

        $rows = $this->pdo->query('SELECT * FROM help_assistant_cache')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('Lucie', (string) json_encode($rows));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['fingerprint']);
    }

    // --- Input --------------------------------------------------------

    public function testAnEmptyOrEnormousQuestionIsRefusedBeforeAnyCall(): void
    {
        $connector = $this->connectorAnswering(['calendrier']);
        $service = $this->service($connector);

        try {
            $service->ask('   ', Role::CHIEF, 1);
            $this->fail('an empty question must be refused');
        } catch (AssistantException $e) {
            $this->assertStringContainsString('Posez une question', $e->getMessage());
        }

        try {
            $service->ask(str_repeat('a', AssistantService::MAX_QUESTION_LENGTH + 1), Role::CHIEF, 1);
            $this->fail('an enormous question must be refused');
        } catch (AssistantException $e) {
            $this->assertStringContainsString('trop longue', $e->getMessage());
        }

        $this->assertSame(0, $connector->callCount());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM help_assistant_rate_limits')->fetchColumn(),
            'a refused question must not spend an allowance'
        );
    }

    // --- The anchor ----------------------------------------------------

    public function testTheCurrentPageAndItsTopicsAnchorAnAmbiguousQuestion(): void
    {
        $connector = $this->connectorAnswering(['envoi-de-mails']);
        $this->service($connector)->ask('Comment je fais ça ?', Role::CHIEF, 1, [], '/mass-mail', ['envoi-de-mails']);

        $prompt = (string) $connector->promptFor(LlmTier::CHEAP);
        $this->assertStringContainsString('/mass-mail', $prompt);
        $this->assertStringContainsString('envoi-de-mails', $prompt);
    }

    public function testThePreviousExchangesReachBothCalls(): void
    {
        $connector = $this->connectorAnswering(['envoi-de-mails']);
        $history = [['question' => 'Comment écrire aux parents ?', 'answer' => 'Ouvrez la page.']];
        $this->service($connector)->ask('Et pour une autre section ?', Role::CHIEF, 1, $history);

        $this->assertStringContainsString('Comment écrire aux parents ?', (string) $connector->promptFor(LlmTier::CHEAP));
        $this->assertStringContainsString('Ouvrez la page.', (string) $connector->promptFor(LlmTier::CAPABLE));
    }
}
