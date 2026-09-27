<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A module stores the household the core hands it, and never makes one
 * (issue #630).
 *
 * `Core\Member\Household\HouseholdKey` is the blind index of a normalized
 * address, and the maintainer's decision on #630 is that the core alone
 * knows that. The registration module used to know it too: it normalized
 * the address with `Core\Member\AddressNormalizer` and blind-indexed it
 * with the purpose `'address'`, deriving the core's key a second time. A
 * blind index never throws when two derivations drift apart, it simply
 * finds nobody — so nothing would have said so.
 *
 * PHP has no package-private constructor to keep `HouseholdKey::fromStorable()`
 * inside the core, so this test does: no module source names it, reaches
 * for the address normalizer, or blind-indexes under the core's `'address'`
 * purpose.
 */
final class ModulesNeverMintAHouseholdKeyTest extends TestCase
{
    /** The floor: an empty verdict over no files would mean nothing. */
    private const AT_LEAST_THIS_MANY_FILES_ARE_READ = 300;

    private const FORBIDDEN = [
        'HouseholdKey::' => 'mints a household key; ask Core\\Member\\Household\\HouseholdRepository::keyForAddress()',
        'AddressNormalizer' => 'normalizes an address the way the core keys households; the core owns that',
        "'address')" => "blind-indexes under the core's 'address' purpose",
    ];

    public function testNoModuleDerivesOrMintsAHouseholdKey(): void
    {
        $offenders = [];
        $read = 0;

        foreach (self::moduleSources() as $path => $source) {
            ++$read;
            $code = self::withoutComments($source);
            foreach (self::FORBIDDEN as $needle => $why) {
                if (str_contains($code, $needle)) {
                    $offenders[] = $path . ' ' . $why;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_FILES_ARE_READ,
            $read,
            'the scan found almost no module sources, so its empty verdict means nothing'
        );
        $this->assertSame(
            [],
            $offenders,
            "a module derives the core's household key itself (issue #630):\n  " . implode("\n  ", $offenders) . "\n"
        );
    }

    /** The reader sees the call, and not a comment describing it. */
    public function testTheReaderSeesCodeAndNotProse(): void
    {
        $code = '<?php $k = HouseholdKey::fromStorable($x);';
        $prose = "<?php\n// This used to call HouseholdKey::fromStorable() and no longer does.\n\$y = 1;";

        $this->assertStringContainsString('HouseholdKey::', self::withoutComments($code));
        $this->assertStringNotContainsString('HouseholdKey::', self::withoutComments($prose));
    }

    /**
     * Every PHP file under modules/, tests excluded (they live in tests/).
     *
     * @return array<string, string> relative path => source
     */
    private static function moduleSources(): array
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/modules', \FilesystemIterator::SKIP_DOTS)
        );
        $sources = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source !== false) {
                $sources[str_replace($root . '/', '', $file->getPathname())] = $source;
            }
        }

        return $sources;
    }

    private static function withoutComments(string $source): string
    {
        $kept = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }
}
