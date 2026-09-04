<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `iconv('ASCII//TRANSLIT')` may not be used to fold accents.
 *
 * Core\Service\TextNormalizerService::fold()'s docblock has said so for a
 * long time, in as many words — "**never `iconv('ASCII//TRANSLIT')`**,
 * whose output depends on the C library and the locale". A docblock is
 * not a check, and the rule has now been broken four times by four
 * different pieces of work:
 *
 *   - Modules\SupportDashboard's ticket search (fixed 2026-09-01), where
 *     it was a real production defect: the support search silently
 *     stopped ignoring accents on macOS and musl/Alpine hosts.
 *   - Modules\News\Service\ScanService and
 *     Modules\Finance\Service\ReceivableSearchService (fixed 2026-09-04),
 *     both found only because a release refused.
 *
 * Every one of them passed CI, because CI runs glibc and glibc is the
 * half that works. That is exactly why a rule nobody can run is worth
 * less than a test: the failure is invisible from where the rule is read.
 *
 * The one sanctioned exception is a caller that KNOWS about the split and
 * repairs it explicitly — Modules\Rental\Service\RentalSlugGenerator does
 * that, in eighteen lines of comment naming the "'e" / "^e" / "\"e"
 * spellings libiconv produces and stripping them. It is listed below by
 * name rather than by pattern, so a new exception has to be argued for in
 * a diff rather than acquired by accident.
 */
class AccentFoldingTest extends TestCase
{
    /**
     * Files allowed to name the call, and why each one may.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Repairs libiconv's output itself, deliberately, in eighteen
        // lines of comment naming the spellings it produces.
        'modules/rental/src/Service/RentalSlugGenerator.php'
            => 'handles the libiconv/glibc split explicitly',
    ];

    public function testNothingFoldsAccentsWithIconvTranslit(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['core', 'modules', 'public', 'scripts'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace($root . '/', '', $file->getPathname());
                if (array_key_exists($relative, self::ALLOWED)) {
                    continue;
                }

                if (self::callsIconvTranslit((string) file_get_contents($file->getPathname()))) {
                    $offenders[] = $relative;
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "These files fold accents with iconv('ASCII//TRANSLIT'), whose output depends on the C "
            . "library: « arrête » becomes « arrete » on glibc and « arr^ete » on the libiconv macOS "
            . "and musl ship, so the comparison silently stops ignoring accents on those hosts while "
            . "CI stays green. Use Core\\Service\\TextNormalizerService::fold(), which applies an "
            . 'explicit map on every platform.'
        );
    }

    /**
     * Whether the file really CALLS it, as opposed to mentioning it.
     *
     * A plain substring search cannot tell the two apart, and this rule is
     * one people write about: every fix for it quotes the call it
     * replaced, and the helper that forbids it names it in order to. An
     * allowlist growing an entry per comment would end up excusing more
     * files than it guards, and the next real offender would sit behind
     * one of those entries unnoticed.
     *
     * PHP's own tokeniser draws the line for free: comments and docblocks
     * are their own token types, so only a string literal counts.
     */
    private static function callsIconvTranslit(string $contents): bool
    {
        foreach (token_get_all($contents) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (str_contains($token[1], 'ASCII//TRANSLIT')) {
                return true;
            }
        }

        return false;
    }

    /**
     * An allowlist whose entries no longer exist is an allowlist nobody
     * has read in a while, and it is how a real offender eventually slips
     * in behind a stale path.
     */
    public function testEveryAllowedFileStillExists(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (self::ALLOWED as $relative => $why) {
            $this->assertFileExists(
                $root . '/' . $relative,
                "{$relative} is allowed to name ASCII//TRANSLIT ({$why}) but no longer exists — "
                . 'remove the entry.'
            );
        }
    }
}
