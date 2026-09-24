<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * A scenario that opens a receivable's panel waits for it to settle
 * before clicking anything inside it.
 *
 * `public-home-page.spec.js` learned this the hard way and wrote it down:
 * while the Bootstrap collapse animates it carries `.collapsing` alone,
 * and in that window the « Afficher le QR » link above slides across the
 * waive button's position. On a good day Playwright reports an
 * intercepted pointer event; on a bad one the click lands where the
 * button no longer is, the page does nothing at all, and the next
 * assertion times out against a page that looks perfectly normal.
 *
 * Then `finance-payment-labels.spec.js` performed the very same gesture
 * on the very same panel with no barrier — and CI lost the race. Worse,
 * it lost it twice over: the receivable it failed to abandon stayed
 * owing, so the home page still carried a payment band and
 * `public-home-page.spec.js`, the spec that already knew, failed on
 * somebody else's leftovers. A lesson living in one file is a lesson the
 * next file does not have.
 *
 * BOTH DIRECTIONS, like `Tests\Core\System\E2eFixedWaitRatchetTest`: a
 * scenario that opens the panel without the barrier fails, and so does a
 * declared file that has stopped opening it.
 *
 * PER OCCURRENCE, and from the same sibling. Asking whether the barrier
 * appears ANYWHERE in a file is not the rule: `public-home-page.spec.js`
 * opens the panel in two independent places — its `afterEach` teardown
 * and one test body — so a later edit dropping the barrier from one of
 * them would stay green on the strength of the other. What is counted is
 * therefore how many times each file opens the panel against how many
 * barriers it holds.
 */
class E2eCollapsePanelRatchetTest extends TestCase
{
    /**
     * What reveals the controls this rule is about.
     *
     * The label is the toggle's accessible name, so a spec that opens the
     * panel cannot avoid writing it.
     */
    private const TOGGLE = 'Détail de la créance';

    /** The barrier every one of them must hold. */
    private const BARRIER = 'settledPanelAround(';

    /**
     * The scenarios that open that panel today, and how many times each.
     *
     * The count is the point: two sites in one file are two chances to
     * lose the race, and one barrier does not cover both.
     *
     * @var array<string, int>
     */
    private const OPENS_THE_PANEL = [
        'tests/e2e/specs/finance-payment-labels.spec.js' => 1,
        'tests/e2e/specs/public-home-page.spec.js' => 2,
    ];

    public function testEveryScenarioOpeningTheReceivablePanelWaitsForItToSettle(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $offenders = [];
        $seen = [];

        foreach ($this->specFiles($repoRoot) as $relative => $path) {
            $source = (string) file_get_contents($path);

            $opens = substr_count($source, self::TOGGLE);
            if ($opens === 0) {
                continue;
            }

            $seen[$relative] = $opens;

            $barriers = substr_count($source, self::BARRIER);
            if ($barriers < $opens) {
                $offenders[] = sprintf(
                    '%s — opens the panel %d time(s), holds %d barrier(s)',
                    $relative,
                    $opens,
                    $barriers
                );
            }
        }

        sort($offenders);
        ksort($seen);

        $this->assertSame(
            [],
            $offenders,
            "A scenario that opens « " . self::TOGGLE . " » must call settledPanelAround()\n"
            . "before clicking anything the panel holds. Clicking while it animates lands on a\n"
            . "button that has moved, the page does nothing, and the failure surfaces somewhere\n"
            . "else entirely — see tests/e2e/support/collapse.js.\n\n"
            . implode("\n", $offenders)
        );

        $expected = self::OPENS_THE_PANEL;
        ksort($expected);

        $this->assertSame(
            $expected,
            $seen,
            "The list of scenarios opening that panel is out of date. Add the new one — it is\n"
            . "already held to the rule above — remove the entry that no longer opens it, or\n"
            . 'correct the count of a file that opens it a different number of times.'
        );
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function specFiles(string $repoRoot): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoRoot . '/tests/e2e/specs', \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'js') {
                continue;
            }

            $found[substr($file->getPathname(), strlen($repoRoot) + 1)] = $file->getPathname();
        }

        return $found;
    }
}
