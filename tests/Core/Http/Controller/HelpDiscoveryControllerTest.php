<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppClock;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Help\Discovery\DiscoveryService;
use Core\Help\Discovery\SeenTopicRepository;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Http\Controller\HelpDiscoveryController;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\HelpTopicFileFixtures;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * What the « Le saviez-vous ? » dialog writes back (ARCHITECTURE.md
 * §8.95), from the outside.
 *
 * The revalidation is the test that matters most and the one nothing
 * else would catch: an id a browser sends is written only if the account
 * could actually have been offered it. Getting that wrong costs somebody
 * a tip they never read — small enough that it would never be reported,
 * which is exactly why it is pinned here.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HelpDiscoveryControllerTest extends TestCase
{
    use HelpTopicFileFixtures;

    private \PDO $pdo;
    private string $topicDir;
    private SeenTopicRepository $seenTopics;
    private SettingService $settings;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->seenTopics = new SeenTopicRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->topicDir = $this->makeTopicDir();
        foreach (['publipostage', 'presences-feuille', 'camps-encoder', 'mes-paiements'] as $id) {
            $this->writeTopic($this->topicDir, $id, ['role_min' => 'identified']);
        }
        // Above the floor of the account below, so it can never be
        // eligible for it — the id a hostile payload will try to claim.
        $this->writeTopic($this->topicDir, 'reglages', ['role_min' => 'admin']);
        $this->writeTopic($this->topicDir, 'cookies', ['role_min' => 'identified', 'discovery' => 'off']);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['parent@test.be', hash('sha256', 'parent@test.be')]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        AuthSession::login($this->accountId, 'parent@test.be', 'identified');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTopicDirs();
    }

    // --- The four actions ---------------------------------------------

    public function testCloseMarksWhatWasReadAndHoldsTheDialogBackForTheOrdinaryDelay(): void
    {
        $this->settings->register(DiscoveryService::SETTING_INTERVAL_HOURS, '24', 'number', 'T', 'T');

        $response = $this->controller()->record($this->request(['ids' => ['publipostage'], 'action' => 'close']), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->decode($response->getBody())['success']);
        $this->assertSame(['publipostage'], $this->seenTopics->findSeenIds($this->accountId));

        $until = $this->seenTopics->snoozedUntil($this->accountId);
        $this->assertNotNull($until);
        $this->assertSame(
            AppClock::now()->modify('+24 hours')->format('Y-m-d H'),
            $until->format('Y-m-d H')
        );
    }

    public function testSnoozeMarksWhatWasReadAndHoldsTheDialogBackForTheLongerDelay(): void
    {
        $this->settings->register(DiscoveryService::SETTING_SNOOZE_DAYS, '7', 'number', 'T', 'T');

        $this->controller()->record($this->request(['ids' => ['publipostage'], 'action' => 'snooze']), []);

        $until = $this->seenTopics->snoozedUntil($this->accountId);
        $this->assertNotNull($until);
        $this->assertSame(AppClock::now()->modify('+7 days')->format('Y-m-d'), $until->format('Y-m-d'));
    }

    /**
     * « Ne plus me proposer » consumes the WHOLE eligible remainder and
     * raises no separate mute flag — which is what gives it the intended
     * meaning: the refusal covers today's scope only, and a promotion or
     * a newly enabled module brings the dialog back with what it made
     * eligible.
     */
    public function testNeverConsumesTheWholeRemainderAndSetsNoDelay(): void
    {
        $this->controller()->record($this->request(['ids' => ['publipostage'], 'action' => 'never']), []);

        $seen = $this->seenTopics->findSeenIds($this->accountId);
        sort($seen);

        $this->assertSame(['camps-encoder', 'mes-paiements', 'presences-feuille', 'publipostage'], $seen);
        // Never the `off` topic, and never one above the account's role:
        // neither was ever eligible.
        $this->assertNotContains('cookies', $seen);
        $this->assertNotContains('reglages', $seen);
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    public function testMoreMarksWhatWasReadAndReleasesTheAccountAtOnce(): void
    {
        $this->seenTopics->snooze($this->accountId, AppClock::now()->modify('+1 day'));

        $this->controller()->record($this->request([
            'ids' => ['publipostage', 'camps-encoder'],
            'action' => 'more',
        ]), []);

        $seen = $this->seenTopics->findSeenIds($this->accountId);
        sort($seen);
        $this->assertSame(['camps-encoder', 'publipostage'], $seen);
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    public function testAnUnknownActionIsRefusedRatherThanGuessed(): void
    {
        $response = $this->controller()->record($this->request(['ids' => ['publipostage'], 'action' => 'purge']), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->seenTopics->findSeenIds($this->accountId));
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    // --- The revalidation ---------------------------------------------

    public function testAnIdTheAccountWasNeverOfferedIsNotWritten(): void
    {
        $this->controller()->record($this->request([
            // 'reglages' is admin-only, 'cookies' is discovery: off, and
            // 'inexistant' is nothing at all.
            'ids' => ['publipostage', 'reglages', 'cookies', 'inexistant'],
            'action' => 'close',
        ]), []);

        $this->assertSame(['publipostage'], $this->seenTopics->findSeenIds($this->accountId));
    }

    public function testAnIdOfTheWrongShapeIsDroppedBeforeItReachesTheComparison(): void
    {
        $this->controller()->record($this->request([
            'ids' => ['publipostage', '../../etc/passwd', 'UPPERCASE', 12, null],
            'action' => 'close',
        ]), []);

        $this->assertSame(['publipostage'], $this->seenTopics->findSeenIds($this->accountId));
    }

    public function testAMissingIdsListIsNotAnError(): void
    {
        $response = $this->controller()->record($this->request(['action' => 'close']), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->seenTopics->findSeenIds($this->accountId));
    }

    // --- CSRF ---------------------------------------------------------

    public function testAPostWithoutAValidTokenIsRefusedAndWritesNothing(): void
    {
        $response = $this->controller()->record(
            $this->request(['ids' => ['publipostage'], 'action' => 'close'], withToken: false),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->seenTopics->findSeenIds($this->accountId));
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    public function testTheResetWithoutAValidTokenIsRefusedAndForgetsNothing(): void
    {
        $this->seenTopics->markSeen($this->accountId, ['publipostage']);

        $response = $this->controller()->reset(
            new Request('POST', '/account/discovery/reset', [], [], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['publipostage'], $this->seenTopics->findSeenIds($this->accountId));
    }

    // --- « Revoir les astuces » ---------------------------------------

    public function testTheResetForgetsEverythingAndReleasesTheAccount(): void
    {
        $this->seenTopics->markSeen($this->accountId, ['publipostage', 'camps-encoder']);
        $this->seenTopics->snooze($this->accountId, AppClock::now()->modify('+1 day'));

        $response = $this->controller()->reset(
            new Request('POST', '/account/discovery/reset', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/account', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->seenTopics->findSeenIds($this->accountId));
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    // --- Plumbing ------------------------------------------------------

    private function controller(): HelpDiscoveryController
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);

        return new HelpDiscoveryController(
            $twig,
            new DiscoveryService(
                new HelpService(new HelpRegistry($this->topicDir)),
                $this->seenTopics,
                $this->settings
            ),
            $this->seenTopics
        );
    }

    /**
     * A real Request with its raw body swapped, not a mock: the CSRF
     * guard reads the parsed body and the superglobals off this same
     * object, and a stub answering only getRawBody() would let it pass
     * for the wrong reason.
     *
     * @param array<string, mixed> $payload
     */
    private function request(array $payload, bool $withToken = true): Request
    {
        if ($withToken) {
            $payload['_csrf_token'] = CsrfGuard::generateToken();
        }

        return new class ('POST', '/api/aide/decouverte', [], [], [], [], (string) json_encode($payload)) extends Request
        {
            /**
             * @param array<string, mixed> $query
             * @param array<string, mixed> $body
             * @param array<string, mixed> $cookies
             * @param array<string, mixed> $server
             */
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
}
