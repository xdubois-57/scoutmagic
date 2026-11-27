<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Maintenance\Health\Security\ComponentFamily;
use Core\Maintenance\Health\Security\VersionNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Version strings as hosts really report them (issue #1358): the upstream
 * release is compared, a build suffix is kept as the sign of a possible
 * backport, and a pre-release is recognised rather than compared.
 */
final class VersionNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, ?string, ?string}>
     */
    public static function versions(): array
    {
        return [
            'plain PHP' => ['8.4.1', ComponentFamily::PHP, '8.4.1', null, null],
            'Ubuntu PHP' => ['8.3.6-1ubuntu1.2', ComponentFamily::PHP, '8.3.6', null, '-1ubuntu1.2'],
            'Debian PHP' => ['8.2.29-1~deb12u1', ComponentFamily::PHP, '8.2.29', null, '-1~deb12u1'],
            'PHP release candidate' => ['8.5.0RC1', ComponentFamily::PHP, '8.5.0', 'RC1', null],
            'PHP development build' => ['8.4.0-dev', ComponentFamily::PHP, '8.4.0', 'dev', null],
            'MariaDB alone' => ['10.11.6-MariaDB', ComponentFamily::MARIADB, '10.11.6', null, null],
            'MariaDB from Debian' => ['10.11.6-MariaDB-0+deb12u1', ComponentFamily::MARIADB, '10.11.6', null, '-0+deb12u1'],
            'MariaDB with the replication prefix' => ['5.5.5-10.11.6-MariaDB-log', ComponentFamily::MARIADB, '10.11.6', null, null],
            'MySQL from Ubuntu' => ['8.0.36-0ubuntu0.22.04.1', ComponentFamily::MYSQL, '8.0.36', null, '-0ubuntu0.22.04.1'],
            'OpenSSL patch letter' => ['1.1.1w', ComponentFamily::OPENSSL, '1.1.1w', null, null],
            'OpenSSL two patch letters' => ['1.0.2zh', ComponentFamily::OPENSSL, '1.0.2zh', null, null],
            'OpenSSL two letters and a build' => ['1.0.2zh-fips', ComponentFamily::OPENSSL, '1.0.2zh', null, '-fips'],
            'PHP RC stays a pre-release' => ['8.5.0rc', ComponentFamily::PHP, '8.5.0', 'rc', null],
            'OpenSSL alpha' => ['3.0.0-alpha1', ComponentFamily::OPENSSL, '3.0.0', 'alpha1', null],
        ];
    }

    #[DataProvider('versions')]
    public function testItSplitsWhatIsComparedFromWhatIsNot(
        string $raw,
        string $family,
        string $upstream,
        ?string $prerelease,
        ?string $build
    ): void {
        $version = VersionNumber::parse($raw, ComponentFamily::get($family));

        $this->assertNotNull($version);
        $this->assertSame([$upstream, $prerelease, $build], [$version->upstream(), $version->prerelease, $version->buildSuffix]);
    }

    public function testAStringThatIsNotAVersionIsRefused(): void
    {
        $this->assertNull(VersionNumber::parse('inconnue'));
        $this->assertNull(VersionNumber::parse(''));
    }

    public function testComparisonFollowsSegmentsThenThePatchLetter(): void
    {
        $compare = static fn(string $a, string $b): int => VersionNumber::parse($a)?->compare(VersionNumber::parse($b) ?? throw new \LogicException()) ?? 99;

        $this->assertSame(-1, $compare('8.4.9', '8.4.10'), 'numeric, not lexical');
        $this->assertSame(0, $compare('8.4', '8.4.0'));
        $this->assertSame(1, $compare('10.11.10', '10.11.9'));
        $this->assertSame(-1, $compare('1.1.1t', '1.1.1w'));
        $openssl = static fn(string $a, string $b): int => (VersionNumber::parse($a, ComponentFamily::get(ComponentFamily::OPENSSL)) ?? throw new \LogicException())
            ->compare(VersionNumber::parse($b, ComponentFamily::get(ComponentFamily::OPENSSL)) ?? throw new \LogicException());
        $this->assertSame(-1, $openssl('1.0.2zg', '1.0.2zh'), 'the fix boundary of CVE-2023-2650');
        $this->assertSame(1, $openssl('1.0.2zh', '1.0.2z'));
        $this->assertSame(1, $compare('1.1.1a', '1.1.1'));
        // The build suffix never takes part: the upstream number is equal.
        $this->assertSame(0, $compare('8.3.6-1ubuntu1.2', '8.3.6'));
    }

    public function testABranchIsAPrefixOfSegmentsNotOfCharacters(): void
    {
        $version = VersionNumber::parse('1.1.10') ?? throw new \LogicException();

        $this->assertTrue($version->isInBranch('1.1'));
        $this->assertFalse($version->isInBranch('1.1.1'), '1.1.10 is not in the 1.1.1 branch');
        $this->assertTrue((VersionNumber::parse('10.11.6') ?? throw new \LogicException())->isInBranch('10.11'));
        $this->assertFalse((VersionNumber::parse('10.1.6') ?? throw new \LogicException())->isInBranch('10.11'));
    }

    public function testTheCpeNameCarriesTheUpstreamVersionOnly(): void
    {
        $family = ComponentFamily::get(ComponentFamily::MARIADB) ?? throw new \LogicException();

        $this->assertSame(
            'cpe:2.3:a:mariadb:mariadb:10.11.6:*:*:*:*:*:*:*',
            $family->cpeName(VersionNumber::parse('10.11.6-MariaDB-0+deb12u1', $family) ?? throw new \LogicException())
        );
    }
}
