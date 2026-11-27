<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Maintenance\Health\Security\Advisory;
use Core\Maintenance\Health\Security\AdvisoryLookup;
use Core\Maintenance\Health\Security\BranchLookup;
use Core\Maintenance\Health\Security\ComponentAssessment;
use Core\Maintenance\Health\Security\ComponentFamily;
use Core\Maintenance\Health\Security\HostSecurityEvaluator;
use Core\Maintenance\Health\Security\HostSecuritySnapshot;
use Core\Maintenance\Health\Security\ObservedComponent;
use Core\Maintenance\Health\Security\ReleaseBranch;
use Core\Maintenance\Health\Security\VersionNumber;
use PHPUnit\Framework\TestCase;

/**
 * Lot 2 of issue #1358: the matching of measured versions against
 * reference data, on synthetic sources only.
 */
final class HostSecurityEvaluatorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testTheLatestPatchOfAMaintainedBranchWithNoAdvisoryIsUpToDate(): void
    {
        $assessment = $this->php('8.4.28', []);

        $this->assertSame(ComponentAssessment::STATE_UP_TO_DATE, $assessment->state);
        $this->assertSame(ComponentAssessment::UPDATE_CURRENT, $assessment->updateStatus);
        $this->assertFalse($assessment->isProblem());
        $this->assertTrue($assessment->conclusive);
        $this->assertSame('', $assessment->action);
    }

    /**
     * A newer major version, on its own, never makes a maintained branch
     * obsolete: 8.4 is compared with 8.4.28, not with 8.6.0.
     */
    public function testANewerMajorAloneDoesNotMakeAMaintainedBranchOutdated(): void
    {
        $assessment = $this->php('8.4.28', [], [new ReleaseBranch('8.6', '8.6.0', true), new ReleaseBranch('8.4', '8.4.28', true)]);

        $this->assertSame(ComponentAssessment::STATE_UP_TO_DATE, $assessment->state);
    }

    public function testAPatchAvailableInTheBranchIsOutdatedAndNamesTheVersion(): void
    {
        $assessment = $this->php('8.4.1', []);

        $this->assertSame(ComponentAssessment::STATE_OUTDATED, $assessment->state);
        $this->assertSame(ComponentAssessment::UPDATE_BEHIND, $assessment->updateStatus);
        $this->assertSame('8.4.28', $assessment->latest);
        $this->assertTrue($assessment->certain);
        $this->assertTrue($assessment->needsUpdate);
        $this->assertFalse($assessment->alertWorthy(), 'a delay alone does not notify');
        $this->assertStringContainsString('PHP 8.4.28', $assessment->action);
    }

    public function testABranchPastItsSecuritySupportIsOutdated(): void
    {
        $assessment = $this->php('7.4.33', [], [new ReleaseBranch('7.4', '7.4.33', false, '2022-11-28')]);

        $this->assertSame(ComponentAssessment::STATE_OUTDATED, $assessment->state);
        $this->assertSame(ComponentAssessment::UPDATE_UNSUPPORTED, $assessment->updateStatus);
        $this->assertStringContainsString('branche encore maintenue', $assessment->action);
    }

    /** 7.0 is inside the threshold and triggers the condition; 6.9 is not. */
    public function testSevenPointZeroIsVulnerableAndSixPointNineIsNot(): void
    {
        $at70 = $this->php('8.4.1', [new Advisory('CVE-2026-0070', 7.0, '3.1', 'nvd@nist.gov', 'https://nvd.nist.gov/vuln/detail/CVE-2026-0070')]);
        $at69 = $this->php('8.4.1', [new Advisory('CVE-2026-0069', 6.9, '3.1', 'nvd@nist.gov', 'https://nvd.nist.gov/vuln/detail/CVE-2026-0069')]);

        $this->assertSame(ComponentAssessment::STATE_VULNERABLE, $at70->state);
        $this->assertSame(['CVE-2026-0070'], $at70->highAdvisoryIds);
        $this->assertTrue($at70->alertWorthy());

        $this->assertSame(ComponentAssessment::STATE_OUTDATED, $at69->state);
        $this->assertSame([], $at69->highAdvisoryIds);
        $this->assertFalse($at69->alertWorthy());
        $this->assertSame(1, $at69->advisoryCount, 'a lower advisory is still listed');
    }

    /**
     * An advisory on the newest release of a branch — nothing to update to
     * — is visible and potentially vulnerable, and is not the notification
     * condition, which needs an update.
     */
    public function testAHighAdvisoryWithNoUpdateAvailableDoesNotMeetTheNotificationCondition(): void
    {
        $assessment = $this->php('8.4.28', [new Advisory('CVE-2026-0098', 9.8, '3.1', null, 'u')]);

        $this->assertSame(ComponentAssessment::STATE_VULNERABLE, $assessment->state);
        $this->assertFalse($assessment->needsUpdate);
        $this->assertFalse($assessment->alertWorthy());
        $this->assertTrue($assessment->conclusive, 'a verified current version can re-arm');
        $this->assertStringContainsString('contournement', $assessment->action);
    }

    public function testAnUnknownScoreStaysUnknownAndNeverCountsAsHighOrAsZero(): void
    {
        $assessment = $this->php('8.4.28', [new Advisory('CVE-2026-0100', null, null, null, 'u')]);

        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $assessment->state);
        $this->assertSame([], $assessment->highAdvisoryIds);
        $this->assertNotEmpty(array_filter($assessment->notes, static fn(string $n): bool => str_contains($n, 'gravité est inconnue')));
    }

    /**
     * A distribution's build suffix may carry backported fixes: the result
     * is qualified, never presented as proof.
     */
    public function testABuildSuffixQualifiesTheVerdictAsToConfirm(): void
    {
        $assessment = $this->php('8.4.1-1ubuntu1.2', [new Advisory('CVE-2026-0001', 8.1, '3.1', null, 'u')]);

        $this->assertSame(ComponentAssessment::STATE_VULNERABLE, $assessment->state);
        $this->assertFalse($assessment->certain);
        $this->assertNotEmpty(array_filter($assessment->notes, static fn(string $n): bool => str_contains($n, '-1ubuntu1.2')));
        $this->assertTrue($assessment->alertWorthy(), 'a potentially applicable advisory still notifies');
    }

    /** OpenSSL's distributions do not show their patches in its number at all. */
    public function testAnOpensslBehindItsBranchIsToConfirmEvenWithoutASuffix(): void
    {
        $assessment = $this->assess('openssl_web', ComponentFamily::OPENSSL, '3.0.2', [new ReleaseBranch('3.0', '3.0.18', true)], [], 'endoflife:openssl');

        $this->assertSame(ComponentAssessment::STATE_OUTDATED, $assessment->state);
        $this->assertFalse($assessment->certain);
    }

    public function testThePatchLetterOfOpensslIsCompared(): void
    {
        $branches = [new ReleaseBranch('1.1.1', '1.1.1w', false), new ReleaseBranch('3.0', '3.0.18', true)];

        $assessment = $this->assess('openssl_web', ComponentFamily::OPENSSL, '1.1.1t', $branches, [], 'endoflife:openssl');

        $this->assertSame('1.1.1', $assessment->branch);
        $this->assertSame(ComponentAssessment::UPDATE_UNSUPPORTED, $assessment->updateStatus);
    }

    public function testAPrereleaseIsUnverifiableRatherThanComparedAsARelease(): void
    {
        $assessment = $this->php('8.5.0RC1', [new Advisory('CVE-2026-0001', 9.8, '3.1', null, 'u')]);

        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $assessment->state);
        $this->assertFalse($assessment->alertWorthy());
        $this->assertFalse($assessment->conclusive);
    }

    public function testAnUnknownComponentIsUnverifiableAndAnAbsentOneIsLeftOut(): void
    {
        $assessments = (new HostSecurityEvaluator())->evaluate([
            new ObservedComponent('php_cron', ComponentFamily::PHP, ObservedComponent::UNKNOWN, null, null, 'Pas encore mesuré.'),
            new ObservedComponent('curl_web', ComponentFamily::CURL, ObservedComponent::ABSENT, null, self::NOW),
        ], [], []);

        $this->assertCount(1, $assessments);
        $this->assertSame('php_cron', $assessments[0]->subject);
        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $assessments[0]->state);
        $this->assertFalse($assessments[0]->conclusive);
    }

    /**
     * A source that failed is never a reason to say « À jour », nor, on its
     * own, a reason to say an update is needed; and it must not re-arm.
     */
    public function testAnUnavailableSourceIsUnverifiableAndInconclusive(): void
    {
        $assessment = $this->assess(
            'php_web',
            ComponentFamily::PHP,
            '8.4.1',
            null,
            null,
            'php.net'
        );

        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $assessment->state);
        $this->assertFalse($assessment->needsUpdate);
        $this->assertFalse($assessment->conclusive);
    }

    /**
     * cURL has no version source: its update half is unknown, and an
     * applicable high advisory alone is what says an update is needed.
     */
    public function testWithoutAVersionSourceAnApplicableHighAdvisoryMeansAnUpdateIsNeeded(): void
    {
        $assessment = $this->assess('curl_web', ComponentFamily::CURL, '7.81.0', null, [new Advisory('CVE-2026-0002', 7.5, '3.1', null, 'u')], null);

        $this->assertSame(ComponentAssessment::UPDATE_UNKNOWN, $assessment->updateStatus);
        $this->assertSame(ComponentAssessment::STATE_VULNERABLE, $assessment->state);
        $this->assertTrue($assessment->alertWorthy());
    }

    /**
     * Review of #1415: with no version source, a complete advisory answer
     * with no high score is « À jour » — otherwise cURL kept every
     * installation's summary at « Non vérifiable » for ever.
     */
    public function testWithoutAVersionSourceACleanCompleteAdvisoryAnswerIsUpToDate(): void
    {
        $assessment = $this->assess('curl_web', ComponentFamily::CURL, '8.11.1', null, [new Advisory('CVE-2026-0003', 5.3, '3.1', null, 'u')], null);

        $this->assertSame(ComponentAssessment::UPDATE_UNKNOWN, $assessment->updateStatus);
        $this->assertSame(ComponentAssessment::STATE_UP_TO_DATE, $assessment->state);
        $this->assertTrue($assessment->conclusive);

        $unanswered = $this->assess('curl_web', ComponentFamily::CURL, '8.11.1', null, null, null);
        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $unanswered->state);
        $this->assertFalse($unanswered->conclusive);
    }

    /**
     * Review of #1415: a branch said to be maintained but naming no latest
     * release compares nothing, so it neither reads « À jour » nor makes the
     * assessment conclusive (which would re-arm a standing alert).
     */
    public function testAMaintainedBranchWithoutALatestReleaseProvesNothing(): void
    {
        $assessment = $this->assess(
            'php_web',
            ComponentFamily::PHP,
            '8.4.20',
            [new ReleaseBranch('8.4', null, true)],
            null,
            'php.net'
        );

        $this->assertSame(ComponentAssessment::UPDATE_UNKNOWN, $assessment->updateStatus);
        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $assessment->state);
        $this->assertFalse($assessment->conclusive);
    }

    /**
     * A branch the data does not list — an end-of-life PHP 7 that php.net
     * dropped, say — leaves the update half unknown; a high advisory then
     * says an update is needed, as for a source that failed (review of
     * #1415). Before, the alert never fired for exactly that population.
     */
    public function testAnUnlistedBranchWithAHighAdvisoryNeedsAnUpdateAndNotifies(): void
    {
        $assessment = $this->php('7.2.34', [new Advisory('CVE-2026-0072', 9.8, '3.1', null, 'u')]);

        $this->assertSame(ComponentAssessment::UPDATE_UNKNOWN, $assessment->updateStatus);
        $this->assertTrue($assessment->needsUpdate);
        $this->assertTrue($assessment->alertWorthy());
    }

    /** Same for a maintained branch that names no latest release. */
    public function testABranchWithoutALatestReleaseFallsBackToTheAdvisories(): void
    {
        $assessment = $this->php('8.4.1', [new Advisory('CVE-2026-0073', 7.0, '3.1', null, 'u')], [new ReleaseBranch('8.4', null, true)]);

        $this->assertTrue($assessment->alertWorthy());
    }

    /**
     * An advisory the NVD has not scored yet may be 7 or more: it must not
     * re-arm a standing alert (review of #1415). A known low score still can.
     */
    public function testAnUnscoredAdvisoryIsNeverAConclusiveAllClear(): void
    {
        $unscored = $this->php('8.4.1', [new Advisory('CVE-2026-0101', null, null, null, 'u')]);
        $low = $this->php('8.4.1', [new Advisory('CVE-2026-0102', 5.0, '3.1', null, 'u')]);
        $curl = $this->assess('curl_web', ComponentFamily::CURL, '7.81.0', null, [new Advisory('CVE-2026-0103', null, null, null, 'u')], null);

        $this->assertFalse($unscored->conclusive);
        $this->assertFalse($curl->conclusive);
        $this->assertTrue($low->conclusive);
    }

    /**
     * A branch cached as supported whose stated end of support has passed
     * since is out of support now (review of #1415).
     */
    public function testACachedSupportedBranchPastItsEndOfSupportIsUnsupported(): void
    {
        $branch = new ReleaseBranch('8.1', '8.1.34', true, '2025-12-31');
        $family = ComponentFamily::get(ComponentFamily::PHP) ?? throw new \LogicException();
        $evaluate = static fn(int $now): ComponentAssessment => (new HostSecurityEvaluator())->evaluate(
            [new ObservedComponent('php_web', ComponentFamily::PHP, ObservedComponent::PRESENT, '8.1.34', $now)],
            ['php.net' => new BranchLookup(BranchLookup::OK, [$branch], $now)],
            [$family->cpeName(VersionNumber::parse('8.1.34', $family) ?? throw new \LogicException()) => new AdvisoryLookup(AdvisoryLookup::OK, [], 0, $now)],
            $now
        )[0];

        $this->assertSame(ComponentAssessment::UPDATE_CURRENT, $evaluate((int) strtotime('2025-12-31 12:00:00 UTC'))->updateStatus);
        $this->assertSame(ComponentAssessment::UPDATE_UNSUPPORTED, $evaluate((int) strtotime('2026-01-01 00:00:01 UTC'))->updateStatus);
    }

    /**
     * The identifiers at CVSS ≥ 7 a source reported beyond the detail kept
     * are part of the set a notification is decided on.
     */
    public function testHighIdentifiersBeyondTheKeptDetailAreCounted(): void
    {
        $family = ComponentFamily::get(ComponentFamily::PHP) ?? throw new \LogicException();
        $assessment = (new HostSecurityEvaluator())->evaluate(
            [new ObservedComponent('php_web', ComponentFamily::PHP, ObservedComponent::PRESENT, '8.4.1', self::NOW)],
            ['php.net' => new BranchLookup(BranchLookup::OK, [new ReleaseBranch('8.4', '8.4.28', true)], self::NOW)],
            [$family->cpeName(VersionNumber::parse('8.4.1') ?? throw new \LogicException()) => new AdvisoryLookup(
                AdvisoryLookup::OK,
                [new Advisory('CVE-2026-0001', 9.8, '3.1', null, 'u')],
                3,
                self::NOW,
                ['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']
            )]
        )[0];

        $this->assertSame(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003'], $assessment->highAdvisoryIds);
    }

    public function testTheSameAdvisoryTwiceInASourceIsOneAdvisory(): void
    {
        $advisory = new Advisory('CVE-2026-0001', 8.1, '3.1', null, 'u');

        $assessment = $this->php('8.4.1', [$advisory, $advisory]);

        $this->assertSame(['CVE-2026-0001'], $assessment->highAdvisoryIds);
        $this->assertSame(1, $assessment->advisoryCount);
    }

    /**
     * The overall state shows the most important situation, and a partial
     * or old check never concludes « À jour ».
     */
    public function testTheOverallStateNeverHidesAnUncertainty(): void
    {
        $ok = $this->php('8.4.28', []);
        $outdated = $this->php('8.4.1', []);
        $unknown = (new HostSecurityEvaluator())->evaluate([
            new ObservedComponent('php_cron', ComponentFamily::PHP, ObservedComponent::UNKNOWN, null, null),
        ], [], [])[0];

        $this->assertSame(ComponentAssessment::STATE_UP_TO_DATE, (new HostSecuritySnapshot(self::NOW, [$ok], true))->overallState(self::NOW));
        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, (new HostSecuritySnapshot(self::NOW, [$ok, $unknown], true))->overallState(self::NOW));
        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, (new HostSecuritySnapshot(self::NOW, [$ok], false))->overallState(self::NOW));
        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, (new HostSecuritySnapshot(self::NOW, [], true))->overallState(self::NOW));
        $this->assertSame(
            ComponentAssessment::STATE_UNVERIFIABLE,
            (new HostSecuritySnapshot(self::NOW - HostSecuritySnapshot::STALE_AFTER_SECONDS - 1, [$ok], true))->overallState(self::NOW)
        );
        $this->assertSame(ComponentAssessment::STATE_OUTDATED, (new HostSecuritySnapshot(self::NOW, [$ok, $outdated, $unknown], true))->overallState(self::NOW));
    }

    /**
     * Several advisories and a delay on one component are ONE point; an
     * unverifiable component is none.
     */
    public function testPointsAreOnePerComponentAndContext(): void
    {
        $snapshot = new HostSecuritySnapshot(self::NOW, [
            $this->php('8.4.1', [new Advisory('CVE-2026-0001', 9.8, '3.1', null, 'u'), new Advisory('CVE-2026-0002', 7.5, '3.1', null, 'u')]),
            $this->assess('database', ComponentFamily::MARIADB, '10.11.5', [new ReleaseBranch('10.11', '10.11.14', true)], [], 'endoflife:mariadb'),
            (new HostSecurityEvaluator())->evaluate([new ObservedComponent('php_cron', ComponentFamily::PHP, ObservedComponent::UNKNOWN, null, null)], [], [])[0],
        ], true);

        $this->assertSame(2, $snapshot->pointsToFix());
        $this->assertTrue($snapshot->hasVulnerable());
    }

    public function testAnAssessmentSurvivesTheSnapshotRoundTrip(): void
    {
        $snapshot = new HostSecuritySnapshot(self::NOW, [$this->php('8.4.1-1ubuntu1.2', [new Advisory('CVE-2026-0001', 7.0, '3.1', 'nvd@nist.gov', 'u')])], true);

        $restored = HostSecuritySnapshot::fromArray(json_decode((string) json_encode($snapshot->toArray()), true));

        $this->assertNotNull($restored);
        $this->assertEquals($snapshot, $restored);
        $this->assertNull(HostSecuritySnapshot::fromArray('garbage'));
    }

    /**
     * @param list<Advisory> $advisories
     * @param list<ReleaseBranch>|null $branches
     */
    private function php(string $version, array $advisories, ?array $branches = null): ComponentAssessment
    {
        return $this->assess(
            'php_web',
            ComponentFamily::PHP,
            $version,
            $branches ?? [new ReleaseBranch('8.4', '8.4.28', true, '2028-12-31', 'https://www.php.net/supported-versions.php')],
            $advisories,
            'php.net'
        );
    }

    /**
     * @param list<ReleaseBranch>|null $branches null: the version source failed
     * @param list<Advisory>|null $advisories null: the advisory source failed
     */
    private function assess(string $subject, string $familyId, string $version, ?array $branches, ?array $advisories, ?string $sourceKey): ComponentAssessment
    {
        $family = ComponentFamily::get($familyId) ?? throw new \LogicException();
        $parsed = VersionNumber::parse($version, $family);
        $branchLookups = $sourceKey !== null && $branches !== null
            ? [$sourceKey => new BranchLookup(BranchLookup::OK, $branches, self::NOW)]
            : [];
        $advisoryLookups = $parsed !== null && $advisories !== null
            ? [$family->cpeName($parsed) => new AdvisoryLookup(AdvisoryLookup::OK, $advisories, count(Advisory::deduplicated($advisories)), self::NOW)]
            : [];

        return (new HostSecurityEvaluator())->evaluate(
            [new ObservedComponent($subject, $familyId, ObservedComponent::PRESENT, $version, self::NOW)],
            $branchLookups,
            $advisoryLookups
        )[0];
    }
}
