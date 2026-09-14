<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Notification\PushInvitationRepository;
use Core\Notification\PushInvitationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Which requests carry the « Activer les notifications ? » markup
 * (ARCHITECTURE.md §8.110).
 *
 * Every case here is a "no" the browser would never get to correct, which
 * is what makes them worth pinning: the client half of the decision can
 * only ever refuse to open a dialog the server put in the page, so a page
 * that ships the markup when it should not is a dialog nothing stops.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PushInvitationServiceTest extends TestCase
{
    private const KEY = 'BFakeVapidPublicKey';

    private \PDO $pdo;
    private PushInvitationRepository $invitations;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->invitations = new PushInvitationRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['parent@test.be', hash('sha256', 'parent@test.be')]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    private function service(string $vapidPublicKey = self::KEY): PushInvitationService
    {
        return new PushInvitationService($this->invitations, $vapidPublicKey);
    }

    public function testAnOrdinaryPageOfASignedInAccountCarriesTheInvitation(): void
    {
        $this->assertTrue($this->service()->offerForRequest($this->accountId, 'GET', '/', true));
    }

    public function testAVisitorWithNoAccountIsNeverAsked(): void
    {
        $this->assertFalse($this->service()->offerForRequest(null, 'GET', '/', true));
    }

    /**
     * No VAPID key pair means the installation has no Web Push at all —
     * the dialog would ask for something nothing can deliver, and the
     * browser's permission prompt would be spent for nothing.
     */
    public function testAnInstallationWithoutAVapidKeyPairNeverAsks(): void
    {
        $this->assertFalse($this->service('')->offerForRequest($this->accountId, 'GET', '/', true));
    }

    /**
     * A modal backdrop over the consent banner swallows every click aimed
     * at the decision the site is asking for, and the reader's only way
     * out — dismissing the dialog — answers « Plus tard » for good. The
     * gate is *answered*, either way, never *accepted*.
     */
    public function testNothingIsOfferedWhileTheCookieBannerIsStillUnanswered(): void
    {
        $this->assertFalse($this->service()->offerForRequest($this->accountId, 'GET', '/', false));
    }

    public function testAnAccountThatAnsweredPlusTardIsNeverAskedAgain(): void
    {
        $this->invitations->dismiss($this->accountId);

        $this->assertFalse($this->service()->offerForRequest($this->accountId, 'GET', '/', true));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function excludedPaths(): array
    {
        return [
            // The « Notifications push » switch the dialog sends the
            // reader to is ON this page.
            'mon compte' => ['/account'],
            'une sous-page du compte' => ['/account/passkeys'],
            'une réponse JSON' => ['/api/push-subscription'],
            'les préférences cookies' => ['/cookies'],
            'la page de connexion' => ['/login'],
            'la page hors connexion' => ['/offline'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excludedPaths')]
    public function testTheInvitationNeverShipsOnAnExcludedPage(string $path): void
    {
        $this->assertFalse($this->service()->offerForRequest($this->accountId, 'GET', $path, true));
    }

    /**
     * Prefix, then boundary — the same rule as §8.95's, and the same trap:
     * a page whose path merely starts with the same letters is not the
     * excluded page.
     */
    public function testAPageWhoseNameOnlyStartsLikeAnExcludedOneStillCarriesIt(): void
    {
        $this->assertTrue($this->service()->offerForRequest($this->accountId, 'GET', '/accounts-a-nous', true));
    }

    public function testNothingIsOfferedOnAWrite(): void
    {
        $this->assertFalse($this->service()->offerForRequest($this->accountId, 'POST', '/', true));
    }

    public function testTheMethodIsReadCaseInsensitively(): void
    {
        $this->assertTrue($this->service()->offerForRequest($this->accountId, 'get', '/', true));
    }
}
