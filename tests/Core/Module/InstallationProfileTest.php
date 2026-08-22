<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\InstallationProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstallationProfileTest extends TestCase
{
    private const DESTINATION = 'https://scoutmagic.be';

    public function testTheStatisticsReceiverFlagFollowsTheDestination(): void
    {
        $receiver = InstallationProfile::resolve('https://scoutmagic.be', self::DESTINATION);
        $this->assertTrue($receiver->has(InstallationProfile::FLAG_STATISTICS_RECEIVER));

        $ordinary = InstallationProfile::resolve('https://unite-exemple.be', self::DESTINATION);
        $this->assertFalse($ordinary->has(InstallationProfile::FLAG_STATISTICS_RECEIVER));
    }

    public function testTheStatisticsReceiverFlagIsAbsentWithoutADestination(): void
    {
        $profile = InstallationProfile::resolve('https://scoutmagic.be', '');

        $this->assertFalse($profile->has(InstallationProfile::FLAG_STATISTICS_RECEIVER));
        // …while the reference flag, which does not depend on it, still holds.
        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    public function testTheReferenceInstallationFlagHoldsOnTheReferenceHost(): void
    {
        $profile = InstallationProfile::resolve('https://scoutmagic.be', '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
        $this->assertFalse($profile->has(InstallationProfile::FLAG_LOCAL_INSTALLATION));
    }

    public function testTheReferenceInstallationFlagIgnoresAWwwPrefix(): void
    {
        $profile = InstallationProfile::resolve('https://www.scoutmagic.be', '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    public function testTheReferenceInstallationFlagIsCaseInsensitive(): void
    {
        $profile = InstallationProfile::resolve('https://ScoutMagic.BE', '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    public function testTheReferenceInstallationFlagAcceptsAMissingScheme(): void
    {
        $profile = InstallationProfile::resolve('scoutmagic.be', '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    public function testTheReferenceInstallationFlagIgnoresATrailingDot(): void
    {
        $profile = InstallationProfile::resolve('https://scoutmagic.be./', '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    public function testTheReferenceInstallationFlagIgnoresPathAndQueryString(): void
    {
        $profile = InstallationProfile::resolve('https://scoutmagic.be/config?x=1', '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    /**
     * Another subdomain is another host — the receiver rule (§8.51) applied
     * unchanged to the reference host.
     */
    public function testASubdomainOfTheReferenceHostIsNotTheReferenceInstallation(): void
    {
        $profile = InstallationProfile::resolve('https://stats.scoutmagic.be', '');

        $this->assertFalse($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function localHostProvider(): array
    {
        return [
            'localhost' => ['http://localhost'],
            'localhost with a port' => ['http://localhost:8080'],
            'loopback IPv4' => ['http://127.0.0.1'],
            'loopback IPv6' => ['http://[::1]:8080'],
            '.test suffix' => ['http://scoutmagic.test'],
            '.local suffix' => ['https://unite-exemple.local'],
            '.localhost suffix' => ['http://scoutmagic.localhost'],
            'uppercase .TEST suffix' => ['http://ScoutMagic.TEST'],
        ];
    }

    #[DataProvider('localHostProvider')]
    public function testTheLocalInstallationFlagHoldsOnALocalHost(string $baseUrl): void
    {
        $profile = InstallationProfile::resolve($baseUrl, '');

        $this->assertTrue($profile->has(InstallationProfile::FLAG_LOCAL_INSTALLATION));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonLocalHostProvider(): array
    {
        return [
            'a real domain merely containing "local"' => ['https://mylocal.be'],
            'a real domain merely containing "localhost"' => ['https://nonlocalhost.be'],
            'a real domain merely containing "test"' => ['https://greatest.be'],
            'the reference installation' => ['https://scoutmagic.be'],
            'an ordinary unit' => ['https://unite-exemple.be'],
        ];
    }

    #[DataProvider('nonLocalHostProvider')]
    public function testTheLocalInstallationFlagDoesNotHoldOnARealDomain(string $baseUrl): void
    {
        $profile = InstallationProfile::resolve($baseUrl, '');

        $this->assertFalse($profile->has(InstallationProfile::FLAG_LOCAL_INSTALLATION));
    }

    /**
     * "Unknown" must never read as "match".
     */
    public function testAnEmptyBaseUrlYieldsNoFlagAtAll(): void
    {
        foreach (['', '   ', null] as $baseUrl) {
            $profile = InstallationProfile::resolve($baseUrl, self::DESTINATION);

            $this->assertSame([], $profile->all(), 'base_url: ' . var_export($baseUrl, true));
        }
    }

    public function testAnUnparseableBaseUrlYieldsNoFlagAtAll(): void
    {
        $profile = InstallationProfile::resolve('https://', self::DESTINATION);

        $this->assertSame([], $profile->all());
    }

    public function testSeveralFlagsCanHoldAtOnce(): void
    {
        $profile = InstallationProfile::resolve('https://scoutmagic.be', self::DESTINATION);

        $this->assertTrue($profile->has(InstallationProfile::FLAG_STATISTICS_RECEIVER));
        $this->assertTrue($profile->has(InstallationProfile::FLAG_REFERENCE_INSTALLATION));
        $this->assertFalse($profile->has(InstallationProfile::FLAG_LOCAL_INSTALLATION));
    }

    public function testHasAnyIsOrSemantics(): void
    {
        $profile = new InstallationProfile([InstallationProfile::FLAG_LOCAL_INSTALLATION]);

        $this->assertTrue($profile->hasAny(['reference_installation', 'local_installation']));
        $this->assertFalse($profile->hasAny(['reference_installation', 'statistics_receiver']));
        $this->assertFalse($profile->hasAny([]));
    }

    public function testAnUnknownFlagIsNeverHeld(): void
    {
        $profile = new InstallationProfile(['not_a_flag']);

        $this->assertSame([], $profile->all());
        $this->assertFalse($profile->has('not_a_flag'));
    }

    public function testKnownFlagsIsTheCompleteList(): void
    {
        $this->assertSame(
            [
                InstallationProfile::FLAG_STATISTICS_RECEIVER,
                InstallationProfile::FLAG_REFERENCE_INSTALLATION,
                InstallationProfile::FLAG_LOCAL_INSTALLATION,
            ],
            InstallationProfile::KNOWN_FLAGS
        );
    }
}
