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
     * @return array<string, mixed>
     */
    private static function document(array $support): array
    {
        return ['api' => ['Document' => ['execCommand' => ['__compat' => ['support' => $support]]]]];
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
    }
}
