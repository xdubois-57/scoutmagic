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
use Core\Http\Controller\PushInvitationController;
use Core\Http\Request;
use Core\Notification\PushInvitationRepository;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\HelpTopicFileFixtures;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * What the « Activer les notifications ? » dialog writes back
 * (ARCHITECTURE.md §8.111).
 *
 * The two facts worth pinning are the asymmetry between the answers —
 * only the refusal is remembered against the account, because a
 * subscription belongs to one device — and the consequence both share:
 * the day's tips step aside, which is the requirement « si ce dialogue
 * est affiché on n'affiche pas les astuces ce jour-là » outliving the
 * page the dialog was shown on.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PushInvitationControllerTest extends TestCase
{
    use HelpTopicFileFixtures;

    private \PDO $pdo;
    private string $topicDir;
    private PushInvitationRepository $invitations;
    private SeenTopicRepository $seenTopics;
    private SettingService $settings;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->invitations = new PushInvitationRepository($this->pdo);
        $this->seenTopics = new SeenTopicRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(DiscoveryService::SETTING_INTERVAL_HOURS, '24', 'number', 'T', 'T');

        $this->topicDir = $this->makeTopicDir();
        $this->writeTopic($this->topicDir, 'installer-application', ['role_min' => 'identified']);

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

    // --- The two answers ----------------------------------------------

    public function testPlusTardIsRememberedAndTheInvitationNeverComesBack(): void
    {
        $response = $this->controller()->answer($this->request(['action' => 'later']), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->decode($response->getBody())['success']);
        $this->assertNotNull($this->invitations->dismissedAt($this->accountId));
    }

    /**
     * Accepting says nothing about the ACCOUNT: a Web Push subscription
     * belongs to one device, so a second device this account installs the
     * application on still has to be asked. What stops the dialog on the
     * device that accepted is that device's own granted permission.
     */
    public function testAcceptingDoesNotCloseTheInvitationForTheAccount(): void
    {
        $this->controller()->answer($this->request(['action' => 'enabled']), []);

        $this->assertNull($this->invitations->dismissedAt($this->accountId));
    }

    /**
     * « Si ce dialogue est affiché on n'affiche pas les astuces ce
     * jour-là » — whichever way it was answered, and for the whole
     * interval rather than for the page it was answered on.
     *
     * @return array<string, array{0: string}>
     */
    public static function answers(): array
    {
        return [
            'accepté' => ['enabled'],
            'reporté' => ['later'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('answers')]
    public function testEitherAnswerHoldsTheDaysTipsBack(string $action): void
    {
        $this->controller()->answer($this->request(['action' => $action]), []);

        $until = $this->seenTopics->snoozedUntil($this->accountId);
        $this->assertNotNull($until);
        $this->assertSame(
            AppClock::now()->modify('+24 hours')->format('Y-m-d H'),
            $until->format('Y-m-d H')
        );
    }

    /**
     * **A delay already running is never shortened.** « Pas avant une
     * semaine » is an explicit choice, and answering an unrelated dialog
     * must not undo it: somebody who postponed the tips in their browser,
     * then installed the application and answered its invitation, keeps
     * their seven days. The bug this pins wrote 24 hours over them.
     */
    public function testAnswerNeverShortensADelayTheAccountAlreadyChose(): void
    {
        $inAWeek = AppClock::now()->modify('+7 days');
        $this->seenTopics->snooze($this->accountId, $inAWeek);

        $this->controller()->answer($this->request(['action' => 'later']), []);

        $until = $this->seenTopics->snoozedUntil($this->accountId);
        $this->assertNotNull($until);
        $this->assertSame(
            $inAWeek->format('Y-m-d H:i'),
            $until->format('Y-m-d H:i'),
            'answering the invitation must not bring the tips back sooner than the account asked'
        );
    }

    /**
     * And the other direction, so the guard is a comparison rather than a
     * blanket refusal to write: a delay that runs out before the ordinary
     * interval is pushed out to it.
     */
    public function testAnswerStillPushesOutADelayShorterThanTheOrdinaryInterval(): void
    {
        $this->seenTopics->snooze($this->accountId, AppClock::now()->modify('+1 hour'));

        $this->controller()->answer($this->request(['action' => 'later']), []);

        $until = $this->seenTopics->snoozedUntil($this->accountId);
        $this->assertNotNull($until);
        $this->assertSame(
            AppClock::now()->modify('+24 hours')->format('Y-m-d H'),
            $until->format('Y-m-d H')
        );
    }

    /**
     * Holding the tips back consumes nothing: no tip was shown, so no tip
     * is marked seen. Getting this wrong would spend somebody's first
     * batch on a dialog that was not about the help at all.
     */
    public function testHoldingTheTipsBackMarksNoTipAsSeen(): void
    {
        $this->controller()->answer($this->request(['action' => 'later']), []);

        $this->assertSame([], $this->seenTopics->findSeenIds($this->accountId));
    }

    // --- What is refused ----------------------------------------------

    public function testAnUnknownActionIsRefusedAndRecordsNothing(): void
    {
        $response = $this->controller()->answer($this->request(['action' => 'never']), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->invitations->dismissedAt($this->accountId));
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    public function testAMissingActionIsRefusedAndRecordsNothing(): void
    {
        $response = $this->controller()->answer($this->request([]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->invitations->dismissedAt($this->accountId));
    }

    public function testWithoutAValidTokenNothingIsRecorded(): void
    {
        $response = $this->controller()->answer($this->request(['action' => 'later'], withToken: false), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->invitations->dismissedAt($this->accountId));
        $this->assertNull($this->seenTopics->snoozedUntil($this->accountId));
    }

    // --- Plumbing ------------------------------------------------------

    private function controller(): PushInvitationController
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);

        return new PushInvitationController(
            $twig,
            $this->invitations,
            new DiscoveryService(
                new HelpService(new HelpRegistry($this->topicDir)),
                $this->seenTopics,
                $this->settings
            )
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

        return new class ('POST', '/api/notifications/invitation', [], [], [], [], (string) json_encode($payload)) extends Request
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
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
