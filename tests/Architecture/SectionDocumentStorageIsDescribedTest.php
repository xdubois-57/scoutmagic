<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ARCHITECTURE.md §8.28 describes the storage the section documents
 * actually use (issue #532).
 *
 * **What it said, and for how long.** §8.28 announced « a `files` row with
 * `role_min: 'identified'` uploaded via `UploadHandler` », PDF only, and
 * « optional Ghostscript compression if available on server » during the
 * request. Every clause of that had moved: the bytes are encrypted at rest
 * through `EncryptedFileStorageService`, access is decided by the
 * `owner_type`/`owner_id` registry and `SectionDocumentOwnershipChecker`,
 * eleven more MIME types are accepted, and compression is a scheduled task
 * that swaps the stored file in place afterwards. The paragraph was not
 * vague — it was precise and wrong, which is worse: a reader who trusts it
 * reasons about a feature that does not exist, and the one who wrote
 * `/files/{id}` into a new page learns the access rules from the wrong
 * place.
 *
 * **Why a test and not just a rewrite.** Prose cannot be type-checked, so
 * the rewrite would rot exactly as the first version did. What CAN be
 * checked is the vocabulary: an identifier this section names has to exist
 * in the code, and a journal action this feature writes has to appear in
 * the section. Both directions, because each catches the drift the other
 * cannot — a section that falls behind a new event, and a section that
 * keeps naming one that has gone.
 *
 * This deliberately does not try to check the SENTENCES. A guard that
 * demanded phrases would be satisfied by pasting them, which is the same
 * failure in a new costume.
 */
class SectionDocumentStorageIsDescribedTest extends TestCase
{
    private const DOCUMENT = 'ARCHITECTURE.md';
    private const SECTION = '8.28';
    private const SERVICE = 'core/Member/SectionDocumentService.php';

    /**
     * Named in §8.28 as the collaborators the upload path goes through.
     * Each is asserted to be imported by the Service, so the section
     * cannot keep crediting a class the code has stopped using — which is
     * the exact shape of what it got wrong about `UploadHandler`.
     */
    private const STORAGE_COLLABORATORS = [
        'Core\File\EncryptedFileStorageService',
        'Core\Pdf\PdfCompressor',
        'Core\Scheduler\SchedulerService',
    ];

    /**
     * The class §8.28 credited for four years and the code never called.
     * Kept by name on purpose: this is the one assertion that fails if
     * somebody restores the old paragraph from memory.
     */
    private const NOT_THE_UPLOAD_PATH = 'UploadHandler';

    public function testEveryJournalActionOfThisFeatureIsNamedInTheSection(): void
    {
        $actions = $this->journalActionsInTheCode();

        // A floor, not a count: six today, and a seventh is welcome — what
        // this refuses is a sweep that finds none because the `->log()`
        // shape moved, and then passes for having nothing to check.
        $this->assertGreaterThanOrEqual(
            6,
            count($actions),
            'No section-document journal action was found in core/ at all. The reader below looks for '
                . "->log('<module>', '<action>'); if that call shape changed, fix the reader — do not "
                . 'let it report an empty sweep as agreement.'
        );

        $section = $this->sectionText();
        foreach ($actions as $action) {
            $this->assertStringContainsString(
                $action,
                $section,
                sprintf(
                    '%s §%s does not name the journal action `%s`, which this feature writes. A reader '
                        . 'auditing what the section documents leave behind is told about the other '
                        . 'entries and not this one.',
                    self::DOCUMENT,
                    self::SECTION,
                    $action
                )
            );
        }
    }

    /**
     * The other direction: every `section_document_*` identifier the
     * section names — journal action, setting, task name — exists in the
     * code as a literal.
     *
     * This is the half that catches a rename. `section_document_updated`
     * was in §8.28 and has never been written by anything: the code logs
     * `section_document_renamed`. A guard that only checked the forward
     * direction would have left that sentence standing, and it is the one
     * a reader greps for.
     */
    public function testEveryIdentifierTheSectionNamesExistsInTheCode(): void
    {
        $section = $this->sectionText();
        preg_match_all('/section_document_[a-z_]+/', $section, $matches);
        $named = array_values(array_unique($matches[0]));

        $this->assertNotEmpty($named, 'The section names no identifier at all — it used to name several.');

        $code = $this->codeOfTheFeature();
        foreach ($named as $identifier) {
            $this->assertStringContainsString(
                "'" . $identifier . "'",
                $code,
                sprintf(
                    '%s §%s names `%s`, and nothing under core/ holds it as a string literal. Either the '
                        . 'code renamed it and the section was not told, or the section invented it.',
                    self::DOCUMENT,
                    self::SECTION,
                    $identifier
                )
            );
        }
    }

    public function testTheSectionCreditsTheClassesTheServiceReallyImports(): void
    {
        $service = $this->read(self::SERVICE);
        $section = $this->sectionText();

        foreach (self::STORAGE_COLLABORATORS as $class) {
            $this->assertStringContainsString(
                'use ' . $class . ';',
                $service,
                sprintf(
                    '%s no longer imports %s, so §%s must stop crediting it — the list in this test is '
                        . 'the list the section commits to.',
                    self::SERVICE,
                    $class,
                    self::SECTION
                )
            );
            $shortName = substr($class, (int) strrpos($class, '\\') + 1);
            $this->assertStringContainsString(
                $shortName,
                $section,
                sprintf(
                    '%s §%s does not mention %s, which is on the upload path.',
                    self::DOCUMENT,
                    self::SECTION,
                    $shortName
                )
            );
        }

        $this->assertStringNotContainsString(
            self::NOT_THE_UPLOAD_PATH,
            $service,
            'If the service really does use ' . self::NOT_THE_UPLOAD_PATH . ' again, this test is the '
                . 'wrong thing to change first: §' . self::SECTION . ' is.'
        );
        $this->assertStringNotContainsString(
            self::NOT_THE_UPLOAD_PATH,
            $section,
            sprintf(
                '%s §%s is back to describing the upload through %s. It never went through it: the bytes '
                    . 'are encrypted at rest by EncryptedFileStorageService (issue #532).',
                self::DOCUMENT,
                self::SECTION,
                self::NOT_THE_UPLOAD_PATH
            )
        );
    }

    /**
     * The readers, on literal fixtures whose answers are known.
     *
     * The sweeps above all pass today, so a `sectionText()` that returned
     * the WHOLE document would satisfy every one of them — §8.28's
     * vocabulary is in §8.28, but also, trivially, in the file that
     * contains it. Only a fixture says whether the extraction still stops
     * where the section stops.
     */
    public function testTheReadersStopAtTheSectionTheyClaimToRead(): void
    {
        $fixture = <<<'MARKDOWN'
            ### 8.27 Something else

            Mentions section_document_from_the_wrong_section.

            ### 8.28 Section documents

            Writes section_document_added.

            ### 8.29 Another thing

            Mentions section_document_from_after_the_end.
            MARKDOWN;

        $extracted = $this->extractSection($fixture, '8.28');
        $this->assertStringContainsString('section_document_added', $extracted);
        $this->assertStringNotContainsString('from_the_wrong_section', $extracted);
        $this->assertStringNotContainsString('from_after_the_end', $extracted);

        // And the action reader reads the second argument of ->log(), not
        // any nearby string: a payload key spelled the same way is not an
        // action, and neither is a setting.
        $snippet = <<<'PHP_SNIPPET'
            $this->journalService->log(
                'core',
                'section_document_added',
                'info',
                'Document de section ajouté',
                ['section_document_id' => $documentId]
            );
            $this->settingService->get('section_document_compression_enabled');
            PHP_SNIPPET;

        $this->assertSame(['section_document_added'], $this->journalActionsIn($snippet));
    }

    /** @return string[] sorted, unique */
    private function journalActionsInTheCode(): array
    {
        $actions = $this->journalActionsIn($this->codeOfTheFeature());
        sort($actions);

        return $actions;
    }

    /**
     * The action of a `->log()` call is its SECOND argument, the first
     * being the module. Matching `section_document_*` anywhere would have
     * counted `['section_document_id' => …]` in every payload and the four
     * settings besides.
     *
     * @return string[] unique, in no particular order
     */
    private function journalActionsIn(string $source): array
    {
        preg_match_all(
            '/->log\(\s*\'[a-z]+\',\s*\'(section_document_[a-z_]+)\'/',
            $source,
            $matches
        );

        return array_values(array_unique($matches[1]));
    }

    /** Every PHP file under core/, concatenated — one read, one haystack. */
    private function codeOfTheFeature(): string
    {
        $source = '';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/core', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!($file instanceof \SplFileInfo) || $file->getExtension() !== 'php') {
                continue;
            }
            // Read without asserting per file: five hundred of those would
            // drown this test's own assertion count, and an unreadable
            // file shows up as the empty haystack refused below.
            $contents = file_get_contents($file->getPathname());
            if (is_string($contents)) {
                $source .= $contents;
            }
        }

        $this->assertNotSame('', $source, 'No PHP source was read from core/ at all.');

        return $source;
    }

    private function sectionText(): string
    {
        return $this->extractSection($this->read(self::DOCUMENT), self::SECTION);
    }

    /**
     * From `### <number> ` to the line before the next `### `, or the end
     * of the document.
     *
     * **One mechanism, not two.** This first read `break`-ed on reaching a
     * heading while inside the section, AND re-evaluated `$inside` on
     * every heading. Either alone is the whole boundary — a `### ` line
     * closes the section before it by opening another — so each masked the
     * other: removing the `break` passed, and making `$inside` sticky
     * passed too. Two defences that make each other's absence invisible
     * are one defence and one decoration, and the decoration is the one
     * that gets trusted next time.
     */
    private function extractSection(string $document, string $number): string
    {
        $lines = explode("\n", $document);
        $collected = [];
        $inside = false;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '### ')) {
                $inside = str_starts_with($trimmed, '### ' . $number . ' ');
            }
            if ($inside) {
                $collected[] = $line;
            }
        }

        $this->assertNotEmpty($collected, 'Section §' . $number . ' was not found at all.');

        return implode("\n", $collected);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        $this->assertIsString($contents, $relativePath . ' could not be read.');

        return $contents;
    }
}
