<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Maintenance\Health\Security\Advisory;
use Core\Maintenance\Health\Security\ReferenceParsers;
use Core\Maintenance\Health\Security\ReferenceSourceUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * The three reference documents, read from synthetic copies of their real
 * shapes — no test reaches php.net, endoflife.date or the NVD.
 */
final class ReferenceParsersTest extends TestCase
{
    public function testPhpNetBranchesCarryTheirLatestReleaseAndSupport(): void
    {
        $branches = ReferenceParsers::phpBranches((string) json_encode([
            ['branch' => '8.4', 'latest' => '8.4.28', 'state' => 'stable', 'security_support_end' => '2028-12-31T00:00:00+00:00'],
            ['branch' => '8.1', 'latest' => '8.1.34', 'state' => 'security', 'security_support_end' => '2025-12-31T00:00:00+00:00'],
            ['branch' => '7.4', 'latest' => '7.4.33', 'state' => 'eol'],
            ['branch' => '9.0', 'latest' => '9.0.0alpha1', 'state' => 'future'],
            'not an entry',
        ]));

        $this->assertCount(4, $branches);
        $this->assertSame(['8.4', '8.4.28', true, '2028-12-31'], [$branches[0]->name, $branches[0]->latest, $branches[0]->supported, $branches[0]->supportEnds]);
        $this->assertTrue($branches[1]->supported, 'security-only is still supported');
        $this->assertFalse($branches[2]->supported);
        $this->assertNull($branches[3]->supported, 'a state the parser does not know is unknown, not supported');
    }

    public function testEndOfLifeReleasesCarryTheirLatestPatchAndLink(): void
    {
        $branches = ReferenceParsers::endOfLifeBranches((string) json_encode(['result' => ['releases' => [
            ['name' => '11.4', 'isEol' => false, 'eolFrom' => '2029-05-29', 'latest' => ['name' => '11.4.8', 'link' => 'https://mariadb.com/kb/en/11.4.8/']],
            ['name' => '10.5', 'isEol' => true, 'eolFrom' => '2025-06-24', 'latest' => ['name' => '10.5.29', 'link' => 'javascript:alert(1)']],
        ]]]));

        $this->assertSame(['11.4', '11.4.8', true, '2029-05-29'], [$branches[0]->name, $branches[0]->latest, $branches[0]->supported, $branches[0]->supportEnds]);
        $this->assertSame('https://mariadb.com/kb/en/11.4.8/', $branches[0]->link);
        $this->assertFalse($branches[1]->supported);
        $this->assertNull($branches[1]->link, 'only an https link is ever kept for the page');
    }

    public function testAChangedDocumentShapeIsAnUnavailableSourceNeverAnEmptyAnswer(): void
    {
        foreach ([
            static fn() => ReferenceParsers::phpBranches('{"branches": []}'),
            static fn() => ReferenceParsers::phpBranches('[]'),
            static fn() => ReferenceParsers::endOfLifeBranches('{"result": {}}'),
            static fn() => ReferenceParsers::nvdAdvisories('{"totalResults": 0}'),
            static fn() => ReferenceParsers::nvdAdvisories('<html>'),
        ] as $parse) {
            try {
                $parse();
                $this->fail('a malformed document must not parse');
            } catch (ReferenceSourceUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnNvdAnswerWithNoVulnerabilityIsAValidEmptyList(): void
    {
        $this->assertSame([], ReferenceParsers::nvdAdvisories('{"vulnerabilities": []}'));
    }

    /**
     * The NVD's own analysis before a CNA's, the newest CVSS version within
     * each; the version and the scorer travel with the score; a missing
     * score stays null; a rejected record and a duplicate disappear.
     */
    public function testNvdScoresAreNormalisedWithTheirVersionAndSource(): void
    {
        $advisories = ReferenceParsers::nvdAdvisories((string) json_encode(['vulnerabilities' => [
            ['cve' => ['id' => 'CVE-2026-0001', 'metrics' => [
                'cvssMetricV40' => [['source' => 'security@php.net', 'type' => 'Secondary', 'cvssData' => ['version' => '4.0', 'baseScore' => 9.3]]],
                'cvssMetricV31' => [['source' => 'nvd@nist.gov', 'type' => 'Primary', 'cvssData' => ['version' => '3.1', 'baseScore' => 7.5]]],
            ]]],
            ['cve' => ['id' => 'CVE-2026-0002', 'metrics' => [
                'cvssMetricV31' => [['source' => 'security@php.net', 'type' => 'Secondary', 'cvssData' => ['version' => '3.1', 'baseScore' => 6.9]]],
            ]]],
            ['cve' => ['id' => 'CVE-2026-0003', 'metrics' => []]],
            ['cve' => ['id' => 'CVE-2026-0004', 'vulnStatus' => 'Rejected', 'metrics' => []]],
            ['cve' => ['id' => 'CVE-2026-0001', 'metrics' => []]],
            ['cve' => ['id' => 'GHSA-xxxx', 'metrics' => []]],
        ]]));

        $this->assertSame(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003'], array_map(static fn(Advisory $a): string => $a->id, $advisories));
        $this->assertSame([7.5, '3.1', 'nvd@nist.gov'], [$advisories[0]->score, $advisories[0]->cvssVersion, $advisories[0]->scoreSource]);
        $this->assertSame([6.9, 'security@php.net'], [$advisories[1]->score, $advisories[1]->scoreSource]);
        $this->assertNull($advisories[2]->score, 'an advisory nobody scored is not a zero');
        $this->assertSame('gravité inconnue', $advisories[2]->severityLabel());
        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2026-0001', $advisories[0]->url);
    }

    /** The agreed threshold, inclusive: 7.0 counts, 6.9 does not, unknown does not. */
    public function testTheHighThresholdIsSevenInclusive(): void
    {
        $advisory = static fn(?float $score): Advisory => new Advisory('CVE-2026-1', $score, '3.1', null, 'https://nvd.nist.gov/');

        $this->assertTrue($advisory(7.0)->isHigh());
        $this->assertTrue($advisory(10.0)->isHigh());
        $this->assertFalse($advisory(6.9)->isHigh());
        $this->assertFalse($advisory(6.95 - 0.05)->isHigh());
        $this->assertFalse($advisory(null)->isHigh());
        $this->assertSame('7,0 (CVSS 3.1)', $advisory(7.0)->severityLabel());
    }

    public function testAdvisoriesSortHighestFirstAndUnknownLast(): void
    {
        $sorted = Advisory::sorted([
            new Advisory('CVE-2026-3', null, null, null, 'u'),
            new Advisory('CVE-2026-1', 5.0, '3.1', null, 'u'),
            new Advisory('CVE-2026-2', 9.8, '3.1', null, 'u'),
        ]);

        $this->assertSame(['CVE-2026-2', 'CVE-2026-1', 'CVE-2026-3'], array_map(static fn(Advisory $a): string => $a->id, $sorted));
    }
}
