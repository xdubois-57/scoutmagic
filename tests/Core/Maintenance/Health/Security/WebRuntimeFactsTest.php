<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Maintenance\Health\Security\RuntimeVersions;
use Core\Maintenance\Health\Security\WebRuntimeFacts;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The web PHP's own versions, recorded by a visit and read by the weekly
 * check that runs elsewhere (issue #1358).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class WebRuntimeFactsTest extends TestCase
{
    private SettingRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SettingRepository(DatabaseTestHelper::createTestDatabase());
        WebRuntimeFacts::register(new SettingService($this->repository));
    }

    public function testNothingIsKnownBeforeAVisitHasMeasured(): void
    {
        $this->assertNull(WebRuntimeFacts::read(new SettingService($this->repository)));
    }

    public function testAVisitRecordsThisProcesssExactVersionsWithTheTime(): void
    {
        WebRuntimeFacts::record(new SettingService($this->repository), 1_800_000_000);

        $facts = WebRuntimeFacts::read(new SettingService($this->repository));
        $this->assertNotNull($facts);
        $this->assertSame(1_800_000_000, $facts->measuredAt);
        $this->assertSame(PHP_SAPI, $facts->sapi);
        $this->assertSame(PHP_VERSION, $facts->versions->php);
        $this->assertSame(extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : null, $facts->versions->openssl);
    }

    public function testAMalformedRowReadsAsNeverMeasured(): void
    {
        $this->assertNull(WebRuntimeFacts::parse('{"measured_at": 0}'));
        $this->assertNull(WebRuntimeFacts::parse('{"measured_at": 5, "versions": {"php": ""}}'));
        $this->assertNull(WebRuntimeFacts::parse('not json'));
        $this->assertSame(
            '8.4.1',
            WebRuntimeFacts::parse((string) json_encode((new WebRuntimeFacts(5, 'fpm-fcgi', new RuntimeVersions('8.4.1', null, null, null)))->toArray()))?->versions->php
        );
    }

    /** Never fatal: a page that has already been sent must not fail on a diagnostic. */
    public function testRecordingWithoutTheSettingIsSwallowed(): void
    {
        $settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));

        WebRuntimeFacts::record($settings, 1_800_000_000);

        $this->assertNull(WebRuntimeFacts::read($settings));
    }
}
