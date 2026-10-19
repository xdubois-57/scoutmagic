<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Attention\AttentionPoint;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\SosAttentionProvider;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Issue #718 § C point 9, the telephony provider: not configured while the
 * module is on (attention), and the last redirect change failed (urgent) —
 * read from the stored outcome, never from the operator.
 */
class SosAttentionProviderTest extends TestCase
{
    /** @return AttentionPoint[] */
    private function points(?PhoneProviderInterface $provider, ?\DateTimeImmutable $failedAt): array
    {
        $config = $this->createStub(ProviderConfigService::class);
        $config->method('getActiveProvider')->willReturn($provider);
        $settings = $this->createStub(SosSettingsService::class);
        $settings->method('lastRedirectFailureAt')->willReturn($failedAt);

        return (new SosAttentionProvider($config, $settings))->collect(1);
    }

    public function testAConfiguredOperatorWhoseLastChangeWorkedRaisesNothing(): void
    {
        $this->assertSame([], $this->points($this->createStub(PhoneProviderInterface::class), null));
    }

    public function testNoOperatorIsAnAttentionPoint(): void
    {
        $points = $this->points(null, null);

        $this->assertCount(1, $points);
        $this->assertSame("Aucun opérateur téléphonique n'est configuré pour le numéro SOS", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/config/sos', $points[0]->actionUrl);
    }

    /** Not configured says it once: the failure it causes is the same fact. */
    public function testNoOperatorHidesTheFailureItCauses(): void
    {
        $points = $this->points(null, new \DateTimeImmutable('2026-10-18 10:00:00'));

        $this->assertCount(1, $points);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    public function testAFailedRedirectIsUrgent(): void
    {
        $points = $this->points(
            $this->createStub(PhoneProviderInterface::class),
            new \DateTimeImmutable('2026-10-18 10:00:00')
        );

        $this->assertCount(1, $points);
        $this->assertSame('Le dernier changement de redirection du numéro SOS a échoué', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringContainsString('Le 18/10/2026 à 10:00', $points[0]->why);
        $this->assertSame('/admin/sos', $points[0]->actionUrl);
    }
}
