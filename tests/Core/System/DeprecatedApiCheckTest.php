<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('DEPRECATED_API_CHECK_TEST')) {
    define('DEPRECATED_API_CHECK_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/check-deprecated-api.php';

/**
 * The release gate of issue #379 — « une vérification à faire quand on
 * demande une release pour s'assurer … que c'est encore supporté
 * correctement » — reads MDN's published compatibility data and decides
 * whether `document.execCommand` is still there.
 *
 * Its whole difficulty is the shape of that data, so that is what is
 * tested here: the network call is not, and the verdict function is pure
 * so it does not have to be.
 */
class DeprecatedApiCheckTest extends TestCase
{
    /**
     * @param array<string, mixed> $support
     * @param array<string, array<string, mixed>> $commands per-command support,
     *        the sibling `__compat` entries MDN publishes under execCommand
     * @return array<string, mixed>
     */
    private static function document(array $support, array $commands = []): array
    {
        $execCommand = ['__compat' => ['support' => $support]];

        foreach ($commands as $command => $commandSupport) {
            $execCommand[$command] = ['__compat' => ['support' => $commandSupport]];
        }

        return ['api' => ['Document' => ['execCommand' => $execCommand]]];
    }

    public function testASingleStatementWithNoRemovalIsSupported(): void
    {
        $this->assertSame([], deprecatedApiRemovals(['chrome' => ['version_added' => '1']]));
    }

    /**
     * THE REGRESSION TEST FOR THIS FILE'S OWN FIRST DEFECT.
     *
     * This is Firefox's real entry, copied from the live data. Read
     * newest-first it says "fully supported since 69, partially before
     * that". The first version of the check read every statement and
     * reported Firefox as having REMOVED the API in 69 — it would have
     * blocked every release from the day it was merged, on data that says
     * the API is supported.
     */
    public function testAHistoricalRemovalFurtherDownTheListIsNotARemoval(): void
    {
        $firefox = [
            ['version_added' => '69', 'notes' => ['From Firefox 82, nested calls are not supported.']],
            [
                'version_added' => '1',
                'version_removed' => '69',
                'partial_implementation' => true,
                'notes' => 'Only supported for HTMLDocument, not all Document objects.',
            ],
        ];

        $this->assertSame(
            [],
            deprecatedApiRemovals(['firefox' => $firefox]),
            'a version_removed in an older statement is when an EARLIER form of the support ended'
        );
    }

    public function testARemovalInTheCurrentStatementIsReported(): void
    {
        $this->assertSame(
            ['chrome' => '142'],
            deprecatedApiRemovals([
                'chrome' => [
                    ['version_added' => '1', 'version_removed' => '142'],
                ],
            ])
        );
    }

    public function testARemovalStatedWithoutAVersionIsStillARemoval(): void
    {
        $this->assertSame(
            ['safari' => 'an unstated version'],
            deprecatedApiRemovals(['safari' => ['version_added' => '1.3', 'version_removed' => true]])
        );
    }

    /**
     * `"mirror"` is not data: it means "whatever the desktop entry says",
     * and that entry is inspected on its own line.
     */
    public function testAMirroredEntryCarriesNoVerdictOfItsOwn(): void
    {
        $this->assertSame([], deprecatedApiRemovals([
            'chrome' => ['version_added' => '1'],
            'chrome_android' => 'mirror',
        ]));
    }

    public function testAnEngineAbsentFromTheDataIsNotReportedAsRemoved(): void
    {
        $this->assertSame([], deprecatedApiRemovals([]));
    }

    public function testAnEngineOutsideTheWatchedListIsIgnored(): void
    {
        $this->assertSame([], deprecatedApiRemovals([
            'ie' => ['version_added' => '4', 'version_removed' => '11'],
        ]));
    }

    public function testTheVerdictIsOkWhenNothingHasRemovedIt(): void
    {
        $verdict = deprecatedApiVerdict(self::document(['chrome' => ['version_added' => '1']]));

        $this->assertSame('ok', $verdict['status']);
        $this->assertStringContainsString('déprécié mais retiré par aucun moteur', $verdict['report']);
    }

    public function testTheVerdictBlocksAndNamesTheEngineOnARemoval(): void
    {
        $verdict = deprecatedApiVerdict(self::document([
            'firefox' => ['version_added' => '69', 'version_removed' => '150'],
        ]));

        $this->assertSame('blocked', $verdict['status']);
        $this->assertStringContainsString('firefox (150)', $verdict['message']);
        $this->assertStringContainsString('firefox (150)', $verdict['report']);
        $this->assertStringContainsString('379', $verdict['report']);
    }

    /**
     * The one case that must NOT read as "supported": data whose shape has
     * moved. Nothing was looked at, so nothing can be concluded — and the
     * report line has to say so rather than quietly pass.
     */
    public function testDataWhoseShapeHasMovedIsUnverifiedRatherThanSupported(): void
    {
        foreach ([
            'a different tree' => ['api' => ['Document' => []]],
            'support missing' => ['api' => ['Document' => ['execCommand' => ['__compat' => []]]]],
            'not an object' => 'null',
        ] as $label => $data) {
            $verdict = deprecatedApiVerdict($data);
            $this->assertSame('unverified', $verdict['status'], $label);
            $this->assertStringContainsString('à vérifier à la main', $verdict['report'], $label);
        }
    }

    /**
     * A statement list with nothing usable in it — `"firefox": []`, or a
     * null first entry. Neither says the API was removed, and reading the
     * absence as a removal would block every release.
     */
    public function testAStatementListWithNothingInItIsNotARemoval(): void
    {
        $this->assertSame([], deprecatedApiRemovals(['firefox' => []]));
        $this->assertSame([], deprecatedApiRemovals(['firefox' => [null]]));
    }

    // ————— The decision path, with the fetching handed in —————

    /**
     * THE ASSERTION THE GATE'S SAFETY RESTS ON.
     *
     * The gate exits 0 whether the API is supported or the check could not
     * run, so the only thing separating those two outcomes is which verdict
     * comes back — and therefore which line lands in the release notes. A
     * fetch that comes back with nothing must never read as « supporté ».
     */
    public function testAFetchThatComesBackWithNothingIsUnverifiedAndNotSupported(): void
    {
        $verdict = deprecatedApiDecide(static fn (): ?string => null);

        $this->assertSame('unverified', $verdict['status']);
        $this->assertStringContainsString('could not be fetched', $verdict['message']);
        $this->assertStringContainsString('à vérifier à la main', $verdict['report']);
    }

    public function testAFetchedDocumentIsDecidedOnItsContents(): void
    {
        $supported = (string) json_encode(self::document(['chrome' => ['version_added' => '1']]));
        $this->assertSame('ok', deprecatedApiDecide(static fn (): string => $supported)['status']);

        $removed = (string) json_encode(self::document([
            'chrome' => ['version_added' => '1', 'version_removed' => '142'],
        ]));
        $this->assertSame('blocked', deprecatedApiDecide(static fn (): string => $removed)['status']);
    }

    /**
     * A body that is not JSON at all — what raw.githubusercontent answers
     * for a path that has moved: the plain text "404: Not Found". The
     * script's header says this is why no HTTP status line is inspected, so
     * the claim is asserted rather than left as a comment.
     */
    public function testANonJsonBodyIsUnverifiedRatherThanSupported(): void
    {
        $verdict = deprecatedApiDecide(static fn (): string => '404: Not Found');

        $this->assertSame('unverified', $verdict['status']);
    }

    // ————— Fetching, against a real stream —————

    /**
     * The status line decides whether the body is the document, and every
     * case is asserted here because the function is pure — a non-2xx page
     * that happens to be JSON would otherwise be decoded as MDN's data.
     *
     * An absent line is deliberately a success: a `file://` URL carries no
     * status at all, and PHP leaves `$http_response_header` undefined for
     * protocols that have none (measured — PHPStan models it as always
     * defined and is wrong about that). Reading absence as a refusal would
     * reject the very fetch the next test uses.
     */
    public function testOnlyASuccessfulStatusLineLetsTheBodyThrough(): void
    {
        $this->assertTrue(deprecatedApiIsSuccessfulStatus(null));
        $this->assertTrue(deprecatedApiIsSuccessfulStatus(''));
        $this->assertTrue(deprecatedApiIsSuccessfulStatus('HTTP/1.1 200 OK'));
        $this->assertTrue(deprecatedApiIsSuccessfulStatus('HTTP/2 204 No Content'));

        $this->assertFalse(deprecatedApiIsSuccessfulStatus('HTTP/1.1 404 Not Found'));
        $this->assertFalse(deprecatedApiIsSuccessfulStatus('HTTP/1.1 502 Bad Gateway'));
        $this->assertFalse(deprecatedApiIsSuccessfulStatus('HTTP/1.1 301 Moved Permanently'));
        // Not a status line at all: refused rather than read as a success,
        // because a body arriving without one from an HTTP fetch is not
        // something this gate should trust.
        $this->assertFalse(deprecatedApiIsSuccessfulStatus('200 OK'));
    }

    public function testFetchingReturnsTheBodyOfAReadableUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/tests/fixtures/mdn-document-compat.json';
        $body = deprecatedApiFetch('file://' . $path);

        $this->assertIsString($body);
        $this->assertSame('ok', deprecatedApiVerdict(json_decode($body, true))['status']);
    }

    public function testFetchingReturnsNullWhenTheDocumentCannotBeRead(): void
    {
        $this->assertNull(deprecatedApiFetch('file:///nonexistent/mdn-document-compat.json'));
    }

    // ————— What release.sh reads afterwards —————

    public function testTheReportLineIsWrittenWhereTheReleaseScriptReadsIt(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'gate');
        $verdict = deprecatedApiVerdict(self::document(['chrome' => ['version_added' => '1']]));

        try {
            deprecatedApiWriteReport($verdict, $file);
            $this->assertSame($verdict['report'], (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    /**
     * Run by hand, with no gate around it, there is nowhere to write — and
     * that is not an error.
     */
    public function testNoReportFileMeansNothingIsWritten(): void
    {
        $verdict = deprecatedApiVerdict(self::document(['chrome' => ['version_added' => '1']]));

        deprecatedApiWriteReport($verdict, false);
        deprecatedApiWriteReport($verdict, '');

        $this->expectNotToPerformAssertions();
    }

    /**
     * The one asymmetry in this file, asserted so it cannot be tidied away:
     * a removal blocks the release, and a check that could not run does not.
     */
    public function testOnlyARemovalBlocksTheRelease(): void
    {
        $this->assertSame(2, deprecatedApiExitCode(deprecatedApiVerdict(self::document([
            'chrome' => ['version_added' => '1', 'version_removed' => '142'],
        ]))));
        $this->assertSame(0, deprecatedApiExitCode(deprecatedApiVerdict(self::document([
            'chrome' => ['version_added' => '1'],
        ]))));
        $this->assertSame(0, deprecatedApiExitCode(deprecatedApiUnverified('anything at all')));
    }

    // ————— How many engines actually answered —————

    public function testEnginesAreCountedOnlyWhenTheyCarryAVerdict(): void
    {
        $this->assertSame(0, deprecatedApiEnginesInspected([]));
        $this->assertSame(1, deprecatedApiEnginesInspected(['chrome' => ['version_added' => '1']]));
        // "mirror" defers to another entry, which is counted on its own line.
        $this->assertSame(1, deprecatedApiEnginesInspected([
            'chrome' => ['version_added' => '1'],
            'chrome_android' => 'mirror',
        ]));
        // An engine this gate does not watch answers nothing it asked.
        $this->assertSame(0, deprecatedApiEnginesInspected(['ie' => ['version_added' => '4']]));
        $this->assertSame(1, deprecatedApiEnginesInspected([
            'firefox' => [['version_added' => '69'], ['version_added' => '1', 'version_removed' => '69']],
        ]));
    }

    /**
     * THE REGRESSION TEST FOR THE GATE'S WORST FAILURE MODE.
     *
     * `support` present but empty passes the shape check, yields no removals,
     * and used to come back as « supporté par 7 moteurs » — the size of a
     * constant, with nothing read. Reporting seven verdicts from zero data is
     * the exact thing this gate's own header says it refuses to do.
     */
    public function testDataCarryingNoWatchedEngineIsUnverifiedRatherThanSupported(): void
    {
        foreach ([
            'support present but empty' => self::document([]),
            'only engines this gate does not watch' => self::document([
                'ie' => ['version_added' => '4'],
                'opera' => ['version_added' => '9'],
            ]),
            'every entry a mirror, so nothing of its own' => self::document([
                'chrome_android' => 'mirror',
                'safari_ios' => 'mirror',
            ]),
        ] as $label => $data) {
            $verdict = deprecatedApiVerdict($data);
            $this->assertSame('unverified', $verdict['status'], $label);
            $this->assertStringContainsString('no verdict for any of the', $verdict['message'], $label);
        }
    }

    // ————— The per-command entries MDN publishes —————

    /**
     * `api.Document.execCommand` is not one entry: MDN gives several
     * individual commands a `__compat` of their own as sibling keys. The
     * first version of this gate read the generic one alone, so an engine
     * dropping `insertHTML` — which the mass-mail chip insertion depends on —
     * would have been reported as « supporté ».
     */
    public function testACommandTheProductIssuesIsReadFromItsOwnEntry(): void
    {
        $verdict = deprecatedApiVerdict(self::document(
            ['chrome' => ['version_added' => '1']],
            ['insertHTML' => ['chrome' => ['version_added' => '1', 'version_removed' => '150']]]
        ));

        $this->assertSame('blocked', $verdict['status']);
        $this->assertStringContainsString("document.execCommand('insertHTML') in chrome (150)", $verdict['message']);
        $this->assertStringContainsString('insertHTML', $verdict['report']);
    }

    /**
     * And the other half of that rule: a per-command entry for a command
     * NOTHING here issues must not block.
     *
     * Not hypothetical — it is today's data. `defaultParagraphSeparator`
     * carries `version_removed: 79` for Edge (the EdgeHTML lineage ending at
     * the Chromium switch) and `version_added: false` for Chrome and Safari.
     * Reading every sibling entry rather than the product's own commands
     * would abort every release over a capability the editors never ask for.
     */
    public function testACommandTheProductNeverIssuesDoesNotBlock(): void
    {
        $verdict = deprecatedApiVerdict(self::document(
            ['chrome' => ['version_added' => '1']],
            ['defaultParagraphSeparator' => ['edge' => ['version_added' => '≤18', 'version_removed' => '79']]]
        ));

        $this->assertSame('ok', $verdict['status']);
    }

    public function testTheFeatureWalkReportsWhatItReadAndHowMuch(): void
    {
        $read = deprecatedApiFeatureRemovals([
            '__compat' => ['support' => ['chrome' => ['version_added' => '1']]],
            'insertHTML' => ['__compat' => ['support' => ['firefox' => ['version_added' => '1']]]],
            // No `__compat`, so nothing to read and nothing claimed.
            'copy' => ['something_else' => true],
            // A `__compat` with no `support` in it: the same answer, reached
            // by the other route.
            'insertText' => ['__compat' => ['status' => ['deprecated' => true]]],
        ]);

        $this->assertSame([], $read['removals']);
        $this->assertSame(2, $read['inspected']);
        $this->assertSame(
            ['document.execCommand', "document.execCommand('insertHTML')"],
            $read['features']
        );
    }

    /**
     * A recorded sample of the upstream document, so the parser is exercised
     * against the real shape rather than only against shapes written by
     * hand here — three of them nest differently from anything above.
     *
     * tests/fixtures/mdn-document-compat.json is MDN's api/Document.json,
     * reduced to the `execCommand` entry, as published on 2026-09-26.
     *
     * **It cannot detect upstream drift, and does not claim to.** It is
     * frozen, so a restructured api/Document.json leaves it green; what
     * answers that case is the gate itself, which reports « non vérifié »
     * rather than « supporté » when the shape is not where it was, and
     * says so in the release notes. Calling this a canary would be the
     * mistake it exists to avoid.
     */
    public function testTheRecordedUpstreamSampleIsReadCorrectly(): void
    {
        $verdict = deprecatedApiVerdict(json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/mdn-document-compat.json'),
            true
        ));

        $this->assertSame('ok', $verdict['status'], (string) json_encode($verdict));

        // What the real document actually offers this gate today: the generic
        // entry plus `copy` and `insertHTML`, the two commands the product
        // issues that MDN publishes an entry for. A number, so that MDN
        // adding a per-command entry for a command the editors use shows up
        // here rather than passing unnoticed.
        $read = deprecatedApiFeatureRemovals(
            json_decode(
                (string) file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/mdn-document-compat.json'),
                true
            )['api']['Document']['execCommand']
        );
        $this->assertSame(
            ['document.execCommand', "document.execCommand('copy')", "document.execCommand('insertHTML')"],
            $read['features']
        );
        $this->assertSame(12, $read['inspected']);
    }
}
