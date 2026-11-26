<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Maintenance\Health\Security\ComponentFamily;
use Core\Maintenance\Health\Security\HostInventory;
use Core\Maintenance\Health\Security\ObservedComponent;
use Core\Maintenance\Health\Security\RuntimeVersions;
use Core\Maintenance\Health\Security\WebRuntimeFacts;
use Core\System\CronExecutionFacts;
use PHPUnit\Framework\TestCase;

/**
 * Lot 1 of issue #1358: one dated component per context, the web and the
 * cron never standing in for each other, and a measured absence told apart
 * from an unknown.
 */
final class HostInventoryTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testTheWebAndTheCronAreTwoContextsWithTheirOwnExactVersions(): void
    {
        $components = $this->byPresence(HostInventory::collect(
            $this->web(new RuntimeVersions('8.4.1', 'OpenSSL 3.0.13 30 Jan 2024', '8.5.0', 'OpenSSL/3.0.13')),
            $this->cron(new RuntimeVersions('8.3.6-1ubuntu1.2', 'OpenSSL 3.0.13 30 Jan 2024', '8.5.0', 'OpenSSL/3.0.13')),
            'mysql',
            '10.11.6-MariaDB-0+deb12u1',
            self::NOW
        ));

        $this->assertSame('8.4.1', $components['php_web']->version);
        $this->assertSame('8.3.6-1ubuntu1.2', $components['php_cron']->version, 'the build suffix is kept');
        $this->assertSame('3.0.13', $components['openssl_web']->version);
        $this->assertSame(ComponentFamily::MARIADB, $components['database']->family);
        $this->assertSame('10.11.6-MariaDB-0+deb12u1', $components['database']->version);
        $this->assertSame(self::NOW - 60, $components['php_web']->measuredAt);
        $this->assertSame(self::NOW - 120, $components['php_cron']->measuredAt);
        $this->assertStringContainsString('OpenSSL/3.0.13', $components['curl_web']->note);
        // The same OpenSSL twice is one subject, not two.
        $this->assertArrayNotHasKey('curl_tls_web', $components);
    }

    /**
     * cURL built on an OpenSSL other than the extension's is a component of
     * its own — the two engines are kept apart as the ticket asks.
     */
    public function testCurlOnADifferentOpensslIsItsOwnSubject(): void
    {
        $components = $this->byPresence(HostInventory::collect(
            $this->web(new RuntimeVersions('8.4.1', 'OpenSSL 3.0.13 30 Jan 2024', '8.5.0', 'OpenSSL/3.5.1')),
            null,
            'mysql',
            '8.0.36',
            self::NOW
        ));

        $this->assertSame('3.5.1', $components['curl_tls_web']->version);
        $this->assertSame(ComponentFamily::MYSQL, $components['database']->family);
    }

    public function testAnExtensionThatIsNotLoadedIsAMeasuredAbsence(): void
    {
        $components = $this->byPresence(HostInventory::collect(
            $this->web(new RuntimeVersions('8.4.1', null, null, null)),
            null,
            'mysql',
            '8.0.36',
            self::NOW
        ));

        $this->assertSame(ObservedComponent::ABSENT, $components['openssl_web']->presence);
        $this->assertSame(ObservedComponent::ABSENT, $components['curl_web']->presence);
    }

    /**
     * Never measured, or measured too long ago, is « unknown » — never
     * « absent », and never the other context's answer.
     */
    public function testAMissingOrStaleMeasurementIsUnknownNotAbsent(): void
    {
        $components = $this->byPresence(HostInventory::collect(
            new WebRuntimeFacts(self::NOW - HostInventory::WEB_MAX_AGE_SECONDS - 1, 'fpm-fcgi', new RuntimeVersions('8.4.1', null, null, null)),
            null,
            'sqlite',
            '3.45.0',
            self::NOW
        ));

        foreach (['php_web', 'openssl_web', 'curl_web', 'php_cron', 'openssl_cron', 'curl_cron', 'database'] as $subject) {
            $this->assertSame(ObservedComponent::UNKNOWN, $components[$subject]->presence, $subject);
        }
        $this->assertStringContainsString('trop ancienne', $components['php_web']->note);
        $this->assertStringContainsString('pas encore mesuré', $components['php_cron']->note);
    }

    public function testACronMeasurementTakenBeforeVersionsWereRecordedIsUnknown(): void
    {
        $cron = new CronExecutionFacts(self::NOW - 60, 'cli', true, true, 'exec', 'ok', null, null);

        $components = $this->byPresence(HostInventory::collect(null, $cron, 'mysql', '8.0.36', self::NOW));

        $this->assertSame(ObservedComponent::UNKNOWN, $components['php_cron']->presence);
    }

    public function testAnotherTlsLibraryIsPresentButNotComparedYet(): void
    {
        $components = $this->byPresence(HostInventory::collect(
            $this->web(new RuntimeVersions('8.4.1', 'LibreSSL 3.8.2', '8.5.0', 'LibreSSL/3.8.2')),
            null,
            'mysql',
            '8.0.36',
            self::NOW
        ));

        $this->assertSame(ObservedComponent::UNKNOWN, $components['openssl_web']->presence);
        $this->assertSame('LibreSSL 3.8.2', $components['openssl_web']->version);
    }

    public function testComponentsComeInTheDeclaredSubjectOrder(): void
    {
        $components = HostInventory::collect(
            $this->web(new RuntimeVersions('8.4.1', 'OpenSSL 3.0.13', '8.5.0', null)),
            $this->cron(new RuntimeVersions('8.4.1', 'OpenSSL 3.0.13', '8.5.0', null)),
            'mysql',
            '8.0.36',
            self::NOW
        );

        $this->assertSame(
            ['php_web', 'php_cron', 'database', 'openssl_web', 'openssl_cron', 'curl_web', 'curl_cron'],
            array_map(static fn(ObservedComponent $c): string => $c->subject, $components)
        );
    }

    private function web(RuntimeVersions $versions): WebRuntimeFacts
    {
        return new WebRuntimeFacts(self::NOW - 60, 'fpm-fcgi', $versions);
    }

    private function cron(RuntimeVersions $versions): CronExecutionFacts
    {
        return new CronExecutionFacts(self::NOW - 120, 'cli', true, true, 'exec', 'ok', null, null, true, 'none', $versions);
    }

    /**
     * @param list<ObservedComponent> $components
     * @return array<string, ObservedComponent>
     */
    private function byPresence(array $components): array
    {
        $indexed = [];
        foreach ($components as $component) {
            $indexed[$component->subject] = $component;
        }

        return $indexed;
    }
}
