<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * A scenario reaches a Bootstrap modal or collapse only through a helper
 * that knows when it has finished opening or closing.
 *
 * `E2eCollapsePanelRatchetTest` holds ONE panel to that, found by the label
 * of its toggle — « Détail de la créance ». #520 showed what that leaves
 * out: `finance-receipts.spec.js` lost a pick inside `#associate-modal`, and
 * `registration-flow.spec.js` clicked a collapse toggle before Bootstrap had
 * loaded, a mistake `registration-grids.spec.js` had already documented
 * and guarded against for the very same box. The helper that would have
 * prevented the second existed; the file did not use it, and the ratchet
 * could not see it because it only knew one button's name.
 *
 * So this one is not keyed on a name. The overlays come from the TEMPLATES:
 * every modal a view declares (`partials/modal.html.twig`'s `id`, or an
 * element of class `modal`) and every element of class `collapse` with an
 * id. A modal added tomorrow is covered the day it is written, with nobody
 * to remember to list it.
 *
 * WHAT IS A GESTURE, STATICALLY. A spec cannot act on an overlay, nor prove
 * that it opened or closed, without a locator for it — and the locator is
 * written with the overlay's id. The helpers take the id and hand the
 * locator back once it is safe to use: `openModal()` / `closeModal()`
 * (support/modal.js), `openCollapse()` (support/collapse.js),
 * `openSectionEditor()` (support/section-editor.js), `openCard()`
 * (support/collapsible-card.js). A spec that writes the selector itself —
 * `#associate-modal`, or a `` `#${…}` `` built at run time — is doing what
 * those helpers exist to do, without their barriers. That is what fails
 * here, per occurrence: one barrier-less site in a file is one chance to
 * lose the race, whatever the rest of the file does.
 *
 * `getByRole('dialog')` is refused for the same reason: it is the other way
 * of reaching a modal without its id.
 *
 * WHAT IT DOES NOT SEE: a spec that clicks a toggle and never touches what
 * it opens. Such a spec asserts nothing about the overlay, and the first
 * control it aims at inside will fail loudly on its own.
 *
 * BOTH DIRECTIONS, like the ratchets beside it: the allowance below is
 * exact, so a site that disappears must be struck from it, and the
 * template scan must keep finding overlays it is known to have — a scanner
 * that silently stopped matching would pass everything.
 */
class E2eOverlayGestureRatchetTest extends TestCase
{
    /**
     * Sites that name an overlay without a gesture, by file and count.
     *
     * Each is a state read on arrival — which panels a page serves open,
     * which folded — where there is no opening or closing to wait for.
     *
     * @var array<string, int>
     */
    private const STATE_READS_ON_ARRIVAL = [
        // Which state the camps map ARRIVES in is the subject: open on a
        // desktop, folded on a phone, a fold remembered only with consent.
        // Its own toggleAndSettle() waits for the settled class, and every
        // toggle follows a navigation to 'domcontentloaded' — after the
        // bundle has run.
        'tests/e2e/specs/camps-place-and-review.spec.js' => 2,
        // Pages that must serve NO tip at all — behind the cookie banner,
        // once the delay is running, on the help itself: a count of zero,
        // not a dialog that opens or closes.
        'tests/e2e/specs/help-discovery.spec.js' => 3,
        // Configuration > Maintenance serves its first two cards open and
        // the backup and reset cards folded; that default is the subject.
        'tests/e2e/specs/maintenance-backup.spec.js' => 4,
    ];

    /**
     * Overlays the scan must go on finding, one per way a template
     * declares one — the proof the scanner still reads them.
     *
     * @var list<string>
     */
    private const KNOWN_OVERLAYS = [
        'associate-modal',              // partials/modal.html.twig
        'groups-detail-modal',          // a hand-written .modal
        'registration-capacities-box',  // a .collapse
    ];

    public function testNoScenarioReachesAnOverlayWithoutItsBarrier(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $overlays = $this->overlayIds($repoRoot);

        $offenders = [];
        $seen = [];

        foreach ($this->specFiles($repoRoot) as $relative => $path) {
            $source = (string) file_get_contents($path);

            $sites = [];
            foreach ($overlays as $id) {
                $count = preg_match_all('/#' . preg_quote($id, '/') . '(?![\w-])/', $source);
                if ($count > 0) {
                    $sites[] = sprintf('#%s ×%d', $id, $count);
                    $seen[$relative] = ($seen[$relative] ?? 0) + $count;
                }
            }

            $dynamic = preg_match_all('/locator\(\s*`#\$\{/', $source);
            if ($dynamic > 0) {
                $sites[] = sprintf('`#${…}` selector ×%d', $dynamic);
                $seen[$relative] = ($seen[$relative] ?? 0) + $dynamic;
            }

            $dialogs = preg_match_all('/getByRole\(\s*[\'"]dialog[\'"]/', $source);
            if ($dialogs > 0) {
                $sites[] = sprintf("getByRole('dialog') ×%d", $dialogs);
                $seen[$relative] = ($seen[$relative] ?? 0) + $dialogs;
            }

            if ($sites !== [] && !array_key_exists($relative, self::STATE_READS_ON_ARRIVAL)) {
                $offenders[] = $relative . ' — ' . implode(', ', $sites);
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "A scenario names a modal or a collapse itself instead of receiving it from a helper\n"
            . "that waits for Bootstrap to be done with it. Acting on a dialog that is still opening\n"
            . "leaves it stuck on screen; clicking a toggle before Bootstrap has loaded does nothing;\n"
            . "either way the failure surfaces later, as a timeout on something else (#520).\n"
            . "Use openModal()/closeModal() (support/modal.js) or openCollapse() (support/collapse.js),\n"
            . "and scope what follows to the locator they return.\n\n"
            . implode("\n", $offenders)
        );

        $allowed = [];
        foreach (self::STATE_READS_ON_ARRIVAL as $relative => $count) {
            $allowed[$relative] = $seen[$relative] ?? 0;
        }

        $this->assertSame(
            self::STATE_READS_ON_ARRIVAL,
            $allowed,
            "The allowance for state reads on arrival is out of date: correct the count of a file\n"
            . 'that reads a different number of overlays, or strike the one that reads none.'
        );
    }

    public function testTheTemplateScanStillFindsTheOverlaysItKnows(): void
    {
        $overlays = $this->overlayIds(dirname(__DIR__, 3));

        foreach (self::KNOWN_OVERLAYS as $id) {
            $this->assertContains(
                $id,
                $overlays,
                "The template scan no longer finds « {$id} ». If it was renamed or removed, replace it here\n"
                . 'with another overlay declared the same way; if not, the scan has stopped matching and'
                . ' the rule above has gone blind.'
            );
        }
    }

    /**
     * Every modal and collapse id the views declare, from the markup alone.
     *
     * Ids built by Twig (`{{ … }}`) are skipped: they are families of
     * elements, reached in the specs through a helper's own id convention
     * (openCard()'s `<cardId>-body`).
     *
     * @return list<string>
     */
    private function overlayIds(string $repoRoot): array
    {
        $ids = [];

        foreach ($this->templateFiles($repoRoot) as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match_all("/'partials\\/modal\\.html\\.twig'\\s+with\\s+\\{\\s*id:\\s*'([^']+)'/", $source, $embeds) > 0) {
                foreach ($embeds[1] as $id) {
                    $ids[$id] = true;
                }
            }

            if (preg_match_all('/<[a-z]+\b[^>]*>/i', $source, $tags) > 0) {
                foreach ($tags[0] as $tag) {
                    if (preg_match('/\bclass="([^"]*)"/', $tag, $class) !== 1) {
                        continue;
                    }
                    if (preg_match('/(?<![\w-])(modal|collapse)(?![\w-])/', $class[1]) !== 1) {
                        continue;
                    }
                    if (preg_match('/\bid="([\w-]+)"/', $tag, $id) === 1) {
                        $ids[$id[1]] = true;
                    }
                }
            }
        }

        $list = array_keys($ids);
        sort($list);

        return $list;
    }

    /**
     * @return list<string>
     */
    private function templateFiles(string $repoRoot): array
    {
        $found = [];

        foreach (['core', 'modules'] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $root, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        sort($found);

        return $found;
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

        ksort($found);

        return $found;
    }
}
