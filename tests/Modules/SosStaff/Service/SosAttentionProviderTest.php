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
    /**
     * @param \Closure(SosSettingsService): void|null $configure more answers
     *        for the settings stub
     * @return AttentionPoint[]
     */
    private function points(
        ?PhoneProviderInterface $provider,
        ?\DateTimeImmutable $failedAt,
        ?\Closure $configure = null
    ): array {
        $config = $this->createStub(ProviderConfigService::class);
        $config->method('getActiveProvider')->willReturn($provider);
        $settings = $this->createStub(SosSettingsService::class);
        $settings->method('lastRedirectFailureAt')->willReturn($failedAt);
        if ($configure !== null) {
            $configure($settings);
        }

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

    /**
     * Issue #1125: a chosen default number that no longer holds says who was
     * chosen and who the line uses instead.
     */
    public function testAStaleDefaultNumberNamesTheChoiceAndTheFallback(): void
    {
        $points = $this->points($this->createStub(PhoneProviderInterface::class), null, function ($settings): void {
            $settings->method('staleDefaultNumberMemberId')->willReturn(7);
            $settings->method('resolveDefaultNumberMemberId')->willReturn(8);
            $settings->method('labelForMember')->willReturnCallback(
                static fn(int $id): string => $id === 7 ? 'Baloo (Paul Martin)' : 'Akela (Jean Dupont)'
            );
        });

        $this->assertCount(1, $points);
        $this->assertSame("Le numéro par défaut choisi pour le numéro SOS n'est plus valable", $points[0]->title);
        $this->assertStringContainsString('Baloo (Paul Martin)', $points[0]->why);
        $this->assertStringContainsString('Akela (Jean Dupont)', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/admin/sos/reglages', $points[0]->actionUrl);
    }

    public function testAStaleDefaultNumberWithNoFallbackIsUrgent(): void
    {
        $points = $this->points($this->createStub(PhoneProviderInterface::class), null, function ($settings): void {
            $settings->method('staleDefaultNumberMemberId')->willReturn(7);
            $settings->method('resolveDefaultNumberMemberId')->willReturn(null);
        });

        $this->assertCount(1, $points);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringContainsString('La personne choisie', $points[0]->why);
    }

    public function testUnusableGsmsAreNamed(): void
    {
        $points = $this->points($this->createStub(PhoneProviderInterface::class), null, function ($settings): void {
            $settings->method('getStaffWithUnusableMobile')->willReturn([
                ['member_id' => 3, 'label' => 'Baloo (Paul Martin)'],
                ['member_id' => 4, 'label' => 'Bagheera (Anne Leroy)'],
            ]);
        });

        $this->assertCount(1, $points);
        $this->assertSame("2 GSM du Staff d'U ne sont pas des numéros de téléphone valides", $points[0]->title);
        $this->assertStringContainsString('Baloo (Paul Martin), Bagheera (Anne Leroy)', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    /**
     * Review of #1194: with a stale default and a failed redirection, the
     * default-number point states the planned configuration only — the
     * failure point says where the line may really be.
     */
    public function testAStaleDefaultNeverClaimsWhereTheCallsArriveNow(): void
    {
        $points = $this->points(
            $this->createStub(PhoneProviderInterface::class),
            new \DateTimeImmutable('2026-10-18 10:00:00'),
            function ($settings): void {
                $settings->method('staleDefaultNumberMemberId')->willReturn(7);
                $settings->method('resolveDefaultNumberMemberId')->willReturn(8);
                $settings->method('labelForMember')->willReturn('Akela (Jean Dupont)');
            }
        );

        $this->assertCount(2, $points);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringContainsString('est désormais prévue vers', $points[1]->why);
        $this->assertStringNotContainsString('arrivent', $points[1]->why);
    }
}
