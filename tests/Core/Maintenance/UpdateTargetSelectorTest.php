<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\ReleaseInfo;
use Core\Maintenance\UpdateTargetSelector;
use PHPUnit\Framework\TestCase;

class UpdateTargetSelectorTest extends TestCase
{
    /**
     * @param string[] $tags
     * @return array<int, ReleaseInfo>
     */
    private function releases(array $tags): array
    {
        return array_map(
            fn(string $tag): ReleaseInfo => new ReleaseInfo($tag, 'Notes ' . $tag, 'https://github.test/r/' . $tag, 'https://github.test/' . $tag . '.zip'),
            $tags
        );
    }

    public function testProposesTheLatestReleaseWhenNoMajorBumpIsPending(): void
    {
        $target = UpdateTargetSelector::selectTarget('1.0.36', $this->releases(['v1.0.36', 'v1.1.0', 'v1.2.4']));

        $this->assertNotNull($target);
        $this->assertSame('1.2.4', $target->version());
    }

    /**
     * The whole point of the stepping rule: a site several majors behind
     * installs them one at a time so each major's migrations run — and can
     * be rolled back — on their own.
     */
    public function testProposesTheFirstMajorReleaseWhenSeveralMajorsAreAvailable(): void
    {
        $target = UpdateTargetSelector::selectTarget('1.4.2', $this->releases(['v1.5.0', 'v2.0.0', 'v2.3.1', 'v3.0.0', 'v3.1.0']));

        $this->assertNotNull($target);
        $this->assertSame('2.0.0', $target->version());
    }

    /**
     * "The next major" is the oldest release of the next major line, which
     * is not necessarily an x.0.0 tag — a major line whose first published
     * release is 2.0.1 steps there.
     */
    public function testProposesTheOldestReleaseOfTheNextMajorLine(): void
    {
        $target = UpdateTargetSelector::selectTarget('1.4.2', $this->releases(['v2.1.0', 'v2.0.1', 'v3.0.0']));

        $this->assertNotNull($target);
        $this->assertSame('2.0.1', $target->version());
    }

    /**
     * Once the only pending major IS the latest release, the two rules
     * agree — nothing is skipped on the way there.
     */
    public function testProposesTheMajorReleaseWhenItIsAlsoTheLatest(): void
    {
        $target = UpdateTargetSelector::selectTarget('1.4.2', $this->releases(['v1.5.0', 'v2.0.0']));

        $this->assertNotNull($target);
        $this->assertSame('2.0.0', $target->version());
    }

    public function testIgnoresReleasesOlderThanOrEqualToTheInstalledVersion(): void
    {
        $target = UpdateTargetSelector::selectTarget('2.0.0', $this->releases(['v1.0.0', 'v1.9.9', 'v2.0.0']));

        $this->assertNull($target);
    }

    public function testReturnsNullWhenNothingIsPublished(): void
    {
        $this->assertNull(UpdateTargetSelector::selectTarget('1.0.36', []));
    }

    /**
     * A "dev-{sha}" build has no major component to step from (it parses
     * as 0.0.0), so it must not be pinned to the repository's oldest
     * release — same bypass as GitHubWebhookService::isBumpAllowed().
     */
    public function testProposesTheLatestReleaseOverAnInstalledDevBuild(): void
    {
        $target = UpdateTargetSelector::selectTarget('dev-a1b2c3d', $this->releases(['v1.0.0', 'v2.0.0', 'v3.2.1']));

        $this->assertNotNull($target);
        $this->assertSame('3.2.1', $target->version());
    }

    public function testProposesTheLatestReleaseWhenTheInstalledVersionIsUnknown(): void
    {
        $target = UpdateTargetSelector::selectTarget('0.0.0', $this->releases(['v1.0.0', 'v2.0.0', 'v3.2.1']));

        $this->assertNotNull($target);
        $this->assertSame('3.2.1', $target->version());
    }
}
