<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Help\Assistant\AssistantCacheRepository;
use Core\Help\Assistant\AssistantCatalog;
use Core\Help\Assistant\AssistantRateLimitRepository;
use Core\Help\Assistant\AssistantService;
use Core\Help\Assistant\AssistantSession;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Http\Controller\HelpAssistantController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmTier;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\Assistant\FakeLlmConnector;
use Tests\Core\Help\HelpTopicFileFixtures;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The assistant's endpoint, from the outside: what a browser gets back.
 *
 * The status codes are the point of most of these. The frontend has to
 * tell « come back in an hour » (429) from « this site has no provider »
 * (503) from « the provider failed just now » (502), and it can only do
 * that if each one is a distinct answer rather than a generic 500 with a
 * sentence in it. The connector-absent case is the one that must NOT be a
 * 404: a route that vanishes with a module leaves the caller guessing
 * whether it was disabled or whether the URL is wrong.
 *
 * The other half is that a model's answer is untrusted content and comes
 * back escaped. That test writes the tag itself, so a regression cannot
 * pass by accident.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HelpAssistantControllerTest extends TestCase
{
    use HelpTopicFileFixtures;

    private \PDO $pdo;
    private string $topicDir;
    private Environment $twig;

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

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        AuthSession::login(7, 'chef@test.be', 'chief');

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'tok'));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTopicDirs();
    }

    private function controller(?FakeLlmConnector $connector): HelpAssistantController
    {
        $helpService = new HelpService(new HelpRegistry($this->topicDir));

        return new HelpAssistantController(
            $this->twig,
            new AssistantService(
                $helpService,
                new AssistantCatalog($helpService),
                new AssistantRateLimitRepository($this->pdo),
                new AssistantCacheRepository($this->pdo),
                new JournalService(new JournalRepository($this->pdo)),
                '1.2.3',
                $connector
            ),
            $helpService
        );
    }

    private function connectorAnswering(string $answer = 'Voici comment faire.'): FakeLlmConnector
    {
        return (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, FakeLlmConnector::selection(['envoi-de-mails']))
            ->willAnswer(LlmTier::CAPABLE, FakeLlmConnector::answer($answer));
    }

    /**
     * A real Request with its raw body swapped, not a mock: the CSRF
     * guard reads the parsed body and the superglobals off this same
     * object, and a stub answering only getRawBody() would let it pass
     * for the wrong reason.
     *
     * @param array<string, string> $payload
     */
    private function request(array $payload, bool $withToken = true): Request
    {
        if ($withToken) {
            $payload['_csrf_token'] = CsrfGuard::generateToken();
        }

        return new class ('POST', '/api/aide/assistant', [], [], [], [], (string) json_encode($payload)) extends Request {
            public function __construct(
                string $method,
                string $path,
                array $query,
                array $body,
                array $cookies,
                array $server,
                private string $raw
            ) {
                parent::__construct($method, $path, $query, $body, $cookies, $server);
            }

            public function getRawBody(): string
            {
                return $this->raw;
            }
        };
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    // --- The page -----------------------------------------------------

    public function testThePageRendersWithNoConnectorAndSaysWhatStillWorks(): void
    {
        $response = $this->controller(null)->page(new Request('GET', '/aide/assistant', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        // The degraded state is written, not blank: a unit running with no
        // AI provider is a normal way to run (locked decision D7).
        $this->assertStringContainsString("L'assistant n'est pas disponible sur ce site", $response->getBody());
        $this->assertStringContainsString("La recherche dans l'aide", $response->getBody());
    }

    public function testThePageOffersTheFormWhenTheConnectorIsThere(): void
    {
        $response = $this->controller($this->connectorAnswering())
            ->page(new Request('GET', '/aide/assistant', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-help-assistant-form', $response->getBody());
        $this->assertStringContainsString('data-help-assistant-input', $response->getBody());
    }

    // --- The endpoint's refusals --------------------------------------

    public function testWithoutACsrfTokenTheEndpointRefuses(): void
    {
        $response = $this->controller($this->connectorAnswering())
            ->ask($this->request(['question' => 'Comment écrire aux parents ?'], false), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->decode($response->getBody())['success']);
    }

    public function testWithNoConnectorTheEndpointAnswers503AndNot404(): void
    {
        $response = $this->controller(null)
            ->ask($this->request(['question' => 'Comment écrire aux parents ?']), []);

        $this->assertSame(503, $response->getStatusCode());
        $body = $this->decode($response->getBody());
        $this->assertFalse($body['success']);
        $this->assertStringContainsString("La recherche dans l'aide", (string) $body['error']);
    }

    public function testAnEmptyQuestionIsA400AndNotA500(): void
    {
        $response = $this->controller($this->connectorAnswering())
            ->ask($this->request(['question' => '   ']), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testASpentQuotaIsA429(): void
    {
        $rateLimits = new AssistantRateLimitRepository($this->pdo);
        for ($i = 0; $i < AssistantService::QUOTA_PER_WINDOW; $i++) {
            $rateLimits->record(7);
        }

        $connector = $this->connectorAnswering();
        $response = $this->controller($connector)
            ->ask($this->request(['question' => 'Comment écrire aux parents ?']), []);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Réessayez dans une heure', (string) $this->decode($response->getBody())['error']);
        // And the provider was never reached — the quota is what stops the
        // spending, so it has to stop it before the call, not after.
        $this->assertSame(0, $connector->callCount());
    }

    public function testAProviderFailureIsA502WithItsOwnFrenchSentence(): void
    {
        $connector = (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, new LlmException('Le fournisseur n\'a pas répondu.'));

        $response = $this->controller($connector)
            ->ask($this->request(['question' => 'Comment écrire aux parents ?']), []);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(
            "Le fournisseur n'a pas répondu.",
            $this->decode($response->getBody())['error'],
            'An LlmException is already user-facing and already French — it is shown as it comes.'
        );
    }

    // --- The answer ---------------------------------------------------

    public function testTheAnswerComesBackRenderedAndEscaped(): void
    {
        $response = $this->controller($this->connectorAnswering(
            "Ouvrez **Nouveau message**.\n\n<script>alert(1)</script>"
        ))->ask($this->request(['question' => 'Comment écrire aux parents ?']), []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response->getBody());
        $html = (string) $body['answer_html'];

        // Markdown from the model is rendered…
        $this->assertStringContainsString('<strong>Nouveau message</strong>', $html);
        // …and its HTML is not. A model's output is untrusted content,
        // exactly like anything else a third party wrote.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTheTopicsTravelBackAsIdAndTitle(): void
    {
        $response = $this->controller($this->connectorAnswering())
            ->ask($this->request(['question' => 'Comment écrire aux parents ?']), []);

        $body = $this->decode($response->getBody());
        $this->assertTrue($body['success']);
        $this->assertSame(
            [['id' => 'envoi-de-mails', 'title' => 'Envoyer un e-mail groupé']],
            $body['topics']
        );
    }

    public function testAnAnsweredQuestionJoinsTheConversation(): void
    {
        $this->assertSame([], AssistantSession::history());

        $this->controller($this->connectorAnswering('Voici comment faire.'))
            ->ask($this->request(['question' => 'Comment écrire aux parents ?']), []);

        $history = AssistantSession::history();
        $this->assertCount(1, $history);
        $this->assertSame('Comment écrire aux parents ?', $history[0]['question']);
        $this->assertSame('Voici comment faire.', $history[0]['answer']);
    }

    public function testAQuestionTheCorpusDoesNotCoverIsAnAnswerAndNotAnError(): void
    {
        $connector = (new FakeLlmConnector())
            ->willAnswer(LlmTier::CHEAP, FakeLlmConnector::selection([]));

        $response = $this->controller($connector)
            ->ask($this->request(['question' => 'Quel temps fera-t-il au camp ?']), []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response->getBody());
        $this->assertTrue($body['success']);
        $this->assertTrue($body['found_nothing']);
        $this->assertSame('', $body['answer_html']);
        // Nothing was answered, so nothing joins the conversation: an
        // empty exchange would only make the next prompt worse.
        $this->assertSame([], AssistantSession::history());
    }

    // --- The panel, the other surface ---------------------------------

    public function testThePanelCarriesTheInviteAndTheAssistantWhenItIsOnOffer(): void
    {
        $this->twig->addGlobal('help_assistant_available', true);

        $html = $this->twig->render('partials/help_panel.html.twig', ['route_help' => []]);

        // The invite, hidden until a search has run (help-search.js shows
        // it under the results and nowhere else — locked decision D2)…
        $this->assertStringContainsString('data-help-assistant-invite-zone', $html);
        // …and the same partial the page uses, ready to be revealed in
        // place rather than a second implementation of it (D3).
        $this->assertStringContainsString('data-help-assistant-host', $html);
        $this->assertStringContainsString('data-help-assistant-thread', $html);

        // But NO field of its own. The panel already has the search box,
        // and « Demander à l'assistant » sends what is typed there: two
        // boxes one under the other on the same screen is the thing a
        // reader cannot make sense of.
        $this->assertStringNotContainsString('data-help-assistant-form', $html);
        $this->assertStringNotContainsString('data-help-assistant-input', $html);
        // One field, so it must be emptiable without selecting a long
        // question by hand to type the next one.
        $this->assertStringContainsString('data-help-search-clear', $html);
    }

    public function testThePanelSaysNothingAboutTheAssistantWhenItIsNotOnOffer(): void
    {
        // No connector, or a role below chief: not a disabled button and
        // not an explanation nobody asked for — simply absent, and the
        // search is the whole feature.
        $this->twig->addGlobal('help_assistant_available', false);

        $html = $this->twig->render('partials/help_panel.html.twig', ['route_help' => []]);

        $this->assertStringNotContainsString('data-help-assistant', $html);
        $this->assertStringContainsString('data-help-search-input', $html);
    }

    public function testTheQuestionTextIsNeverJournalled(): void
    {
        $this->controller($this->connectorAnswering())
            ->ask($this->request(['question' => 'Comment écrire à la famille Dupont ?']), []);

        $rows = (string) json_encode(
            $this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC)
        );

        // SECURITY.md §11: a question is free text a human typed and can
        // name a person, an address, an amount. The journal carries
        // counters, never the words.
        $this->assertStringNotContainsString('Dupont', $rows);
        $this->assertStringNotContainsString('Comment écrire', $rows);
    }
}
