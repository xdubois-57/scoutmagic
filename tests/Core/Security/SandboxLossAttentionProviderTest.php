<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Attention\AttentionPoint;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SandboxLossAttentionProvider;
use Core\Security\SandboxLossState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The attention point a blocked sandbox raises — core's, so it shows with
 * the test toolbox not loaded at all (issue #1469).
 */
#[Group('database')]
final class SandboxLossAttentionProviderTest extends TestCase
{
    private SandboxLossState $state;
    private JournalService $journal;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settings = new SettingService(new SettingRepository($pdo));
        SandboxLossState::register($settings);
        $this->state = new SandboxLossState($settings);
        $this->journal = new JournalService(new JournalRepository($pdo));
    }

    public function testNothingWhileNothingIsLost(): void
    {
        $this->assertSame([], (new SandboxLossAttentionProvider($this->state))->collect(1));
    }

    public function testABlockedMailCaptureIsAnUrgentPointLeadingToTheDecision(): void
    {
        $this->state->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);

        $points = (new SandboxLossAttentionProvider($this->state))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame(
            'La capture des e-mails est armée mais n\'agit plus : l\'envoi est suspendu',
            $points[0]->title
        );
        $this->assertStringContainsString('profil d\'installation est absent de config/app.php', $points[0]->why);
        $this->assertStringContainsString('seuls les liens de connexion partent encore', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('Décider', $points[0]->actionLabel);
        $this->assertSame(SandboxLossState::PAGE_URL, $points[0]->actionUrl);
    }

    public function testABlockedTelephonyIsItsOwnPoint(): void
    {
        $this->state->record(
            SandboxLossState::CHANNEL_TELEPHONY,
            SandboxLossState::REASON_MODULE_UNLOADED,
            $this->journal
        );

        $points = (new SandboxLossAttentionProvider($this->state))->collect(1);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('téléphonie simulée', $points[0]->title);
        $this->assertStringContainsString('module Outils de test n\'est plus chargé', $points[0]->why);
    }

    public function testAReleasedChannelRaisesNothing(): void
    {
        $this->state->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_HOST_MISMATCH, $this->journal);
        $this->state->release(SandboxLossState::CHANNEL_MAIL, 1, $this->journal);

        $this->assertSame([], (new SandboxLossAttentionProvider($this->state))->collect(1));
    }
}
