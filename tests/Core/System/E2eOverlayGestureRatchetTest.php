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
 * The other ways of writing the same thing are refused alike, because they
 * reach the same element without its barrier: `[id="associate-modal"]`, a
 * `.modal` or `.collapse` class selector (`.modal.show`), and
 * `getByRole('dialog')`. Comments are not code and are not read.
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
     * An allowance covers STATE READS ONLY, checked one site at a time: the
     * locator is the subject of an `expect(…)` and nothing else, or a
     * constant that is never used but as one. A file on this list that
     * trades one of its reads for `page.locator('#…').click()` keeps its
     * count and still fails — the click is a gesture, wherever it is.
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
        // The three configuration boxes arrive folded. Two of them are
        // checked again, panel and all, by openCollapse() on the way in;
        // « États d'une demande » is never opened, so its panel is read
        // here — the toggle's aria-expanded alone is in the markup before
        // Bootstrap has run.
        'tests/e2e/specs/registration-flow.spec.js' => 1,
    ];

    /**
     * Overlays the scan must go on finding, one per way a template
     * declares one — the proof the scanner still reads them.
     *
     * @var list<string>
     */
    private const KNOWN_OVERLAYS = [
        'associate-modal',              // partials/modal.html.twig
        'richTextEditorModal',          // a hand-written .modal
        'registration-capacities-box',  // a .collapse
    ];

    public function testNoScenarioReachesAnOverlayWithoutItsBarrier(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $overlays = $this->overlayIds($repoRoot);

        $offenders = [];
        $reads = [];

        foreach ($this->specFiles($repoRoot) as $relative => $path) {
            $sites = self::sitesIn((string) file_get_contents($path), $overlays);

            $problems = $sites['gestures'];
            if ($sites['reads'] > 0) {
                $reads[$relative] = $sites['reads'];
                if (!array_key_exists($relative, self::STATE_READS_ON_ARRIVAL)) {
                    $problems[] = sprintf('undeclared state read ×%d', $sites['reads']);
                }
            }

            if ($problems !== []) {
                $offenders[] = $relative . ' — ' . implode(', ', $problems);
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
            . "and scope what follows to the locator they return. A read of the state a page\n"
            . "arrives in, with no gesture, is declared in STATE_READS_ON_ARRIVAL.\n\n"
            . implode("\n", $offenders)
        );

        $allowed = [];
        foreach (self::STATE_READS_ON_ARRIVAL as $relative => $count) {
            $allowed[$relative] = $reads[$relative] ?? 0;
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
     * Every way a view can declare an overlay, in either quote style — and
     * what does not make one.
     */
    public function testTheTemplateScanReadsEveryDeclarationForm(): void
    {
        $source = <<<'TWIG'
            {% embed 'partials/modal.html.twig' with { id: 'embed-single' } only %}{% endembed %}
            {% embed "partials/modal.html.twig" with { id: "embed-double" } only %}{% endembed %}
            <div class="modal fade" id="class-double" tabindex="-1"></div>
            <div class='modal fade' id='class-single'></div>
            <div id="id-first" class="collapse mt-3"></div>
            <div class="navbar-collapse" id="not-a-collapse"></div>
            <div class="modal-dialog" id="not-a-modal"></div>
            <div class="collapse" id="{{ card }}-body"></div>
            TWIG;

        $this->assertSame(
            ['class-double', 'class-single', 'embed-double', 'embed-single', 'id-first'],
            self::overlayIdsIn($source)
        );
    }

    /**
     * What the rule counts as a state read, and what as a gesture.
     */
    public function testOnlyAnExpectSubjectIsAStateRead(): void
    {
        $overlays = ['x-modal'];
        $reads = static fn (string $js): array => self::sitesIn($js, $overlays);

        $this->assertSame(['reads' => 1, 'gestures' => []], $reads(
            "await expect(page.locator('#x-modal')).toBeHidden();"
        ));
        $this->assertSame(['reads' => 1, 'gestures' => []], $reads(
            "await expect(\n    page.locator('#x-modal'),\n    'none on arrival',\n).toHaveCount(0);"
        ));
        $this->assertSame(['reads' => 1, 'gestures' => []], $reads(
            "const panel = page.locator('#x-modal');\nawait expect(panel).toBeHidden();\n"
            . "await expect(panel, 'the panel is open').toBeVisible();"
        ));
        $this->assertSame([], $reads("// page.locator('#x-modal').click();\n/* '.modal.show' */")['gestures']);
        // A `/*` inside a line comment opens no block comment: the gesture
        // after it is still read, and a `//` inside a string opens no line
        // comment either.
        $this->assertNotSame([], $reads(
            "// read from modules/*/ at load\nawait page.locator('#x-modal').click();\n/** doc */"
        )['gestures']);
        $this->assertNotSame([], $reads(
            "await page.goto('https://example.invalid/'); await page.locator('#x-modal').click();"
        )['gestures']);

        foreach ([
            "await page.locator('#x-modal').click();",
            "await expect(page.locator('#x-modal').getByRole('button')).toBeVisible();",
            "const panel = page.locator('#x-modal');\nawait expect(panel).toBeHidden();\nawait panel.click();",
            "await page.locator('[id=\"x-modal\"]').click();",
            "await expect(page.locator('.modal.show')).toBeVisible();",
            "await page.locator('.collapse').first().click();",
            'await expect(page.locator(`#${id}`)).toBeVisible();',
            "await page.getByRole('dialog').click();",
        ] as $gesture) {
            $this->assertNotSame([], $reads($gesture)['gestures'], $gesture);
        }
    }

    /**
     * Sorts a spec's overlay sites into state reads and gestures.
     *
     * @param list<string> $overlays
     * @return array{reads: int, gestures: list<string>}
     */
    private static function sitesIn(string $source, array $overlays): array
    {
        $code = self::withoutComments($source);
        $reads = 0;
        $gestures = [];

        foreach ($overlays as $id) {
            $quoted = preg_quote($id, '/');

            $hashes = preg_match_all('/#' . $quoted . '(?![\w-])/', $code, $found, PREG_OFFSET_CAPTURE);
            $direct = 0;
            for ($i = 0; $i < $hashes; $i++) {
                if (self::isStateRead($code, $found[0][$i][1])) {
                    $reads++;
                } else {
                    $direct++;
                }
            }
            if ($direct > 0) {
                $gestures[] = sprintf('#%s ×%d', $id, $direct);
            }

            $attribute = preg_match_all('/\[id\s*=\s*([\'"]?)' . $quoted . '\1\s*\]/', $code);
            if ($attribute > 0) {
                $gestures[] = sprintf('[id="%s"] ×%d', $id, $attribute);
            }
        }

        $patterns = [
            '`#${…}` selector' => '/locator\(\s*`#\$\{/',
            "getByRole('dialog')" => '/getByRole\(\s*[\'"]dialog[\'"]/',
            '.modal/.collapse class selector' => '/([\'"`])[^\'"`\n]*\.(?:modal|collapse)(?![\w-])[^\'"`\n]*\1/',
        ];
        foreach ($patterns as $label => $pattern) {
            $count = preg_match_all($pattern, $code);
            if ($count > 0) {
                $gestures[] = sprintf('%s ×%d', $label, $count);
            }
        }

        return ['reads' => $reads, 'gestures' => $gestures];
    }

    /**
     * A selector at $offset is a state read when its locator is the whole
     * subject of an `expect(…)`, or a constant used only as one.
     */
    private static function isStateRead(string $code, int $offset): bool
    {
        $before = substr($code, 0, $offset);
        $after = substr($code, $offset);

        if (preg_match('/expect\(\s*\w+\.locator\(\s*[\'"]$/', $before) === 1) {
            return preg_match('/^[^\'"\n]*[\'"]\s*\)\s*[,)]/', $after) === 1;
        }

        if (preg_match('/(?:const|let)\s+(\w+)\s*=\s*\w+\.locator\(\s*[\'"]$/', $before, $declared) === 1
            && preg_match('/^[^\'"\n]*[\'"]\s*\)\s*;/', $after) === 1) {
            return self::isOnlyEverExpected($code, $declared[1]);
        }

        return false;
    }

    /**
     * Every use of $name, strings aside, is its declaration or the subject
     * of an `expect(…)`.
     */
    private static function isOnlyEverExpected(string $code, string $name): bool
    {
        $bare = (string) preg_replace('/\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`/s', "''", $code);
        $quoted = preg_quote($name, '/');

        $uses = preg_match_all('/(?<![\w$.])' . $quoted . '(?![\w$])/', $bare, $found, PREG_OFFSET_CAPTURE);
        for ($i = 0; $i < $uses; $i++) {
            $offset = $found[0][$i][1];
            $before = substr($bare, 0, $offset);
            $after = substr($bare, $offset + strlen($name));

            $declaration = preg_match('/(?:const|let)\s+$/', $before) === 1;
            $expected = preg_match('/expect\(\s*$/', $before) === 1 && preg_match('/^\s*[,)]/', $after) === 1;
            if (!$declaration && !$expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * The source without its comments — a selector quoted in an explanation
     * is not a gesture.
     *
     * One pass over strings and both comment styles together, the earliest
     * match winning, so each is read the way the language reads it: a
     * `modules/*` glob in a `//` comment opens no block comment (removing
     * block comments first once erased half a spec from the scan), and the
     * `//` of a URL inside a string opens no line comment. Strings are kept.
     */
    private static function withoutComments(string $source): string
    {
        $tokens = '/\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`|\/\*.*?\*\/|\/\/[^\n]*/s';

        return (string) preg_replace_callback(
            $tokens,
            static fn (array $token): string => str_starts_with($token[0], '/') ? '' : $token[0],
            $source
        );
    }

    /**
     * Every modal and collapse id the views declare, from the markup alone.
     *
     * @return list<string>
     */
    private function overlayIds(string $repoRoot): array
    {
        $ids = [];

        foreach ($this->templateFiles($repoRoot) as $path) {
            foreach (self::overlayIdsIn((string) file_get_contents($path)) as $id) {
                $ids[$id] = true;
            }
        }

        $list = array_keys($ids);
        sort($list);

        return $list;
    }

    /**
     * The modal and collapse ids one template declares, in either quote
     * style.
     *
     * Ids built by Twig (`{{ … }}`) are skipped: they are families of
     * elements, reached in the specs through a helper's own id convention
     * (openCard()'s `<cardId>-body`).
     *
     * @return list<string>
     */
    private static function overlayIdsIn(string $source): array
    {
        $ids = [];

        $embed = '/([\'"])partials\/modal\.html\.twig\1\s+with\s+\{\s*id:\s*([\'"])([^\'"]+)\2/';
        if (preg_match_all($embed, $source, $embeds) > 0) {
            foreach ($embeds[3] as $id) {
                $ids[$id] = true;
            }
        }

        if (preg_match_all('/<[a-z]+\b[^>]*>/i', $source, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/\bclass=([\'"])(.*?)\1/', $tag, $class) !== 1) {
                    continue;
                }
                if (preg_match('/(?<![\w-])(modal|collapse)(?![\w-])/', $class[2]) !== 1) {
                    continue;
                }
                if (preg_match('/\bid=([\'"])([\w-]+)\1/', $tag, $id) === 1) {
                    $ids[$id[2]] = true;
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
