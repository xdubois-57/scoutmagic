<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental;

use PHPUnit\Framework\TestCase;

/**
 * design.md §7.4 has four button variants and no others. Rental and
 * leadership had thirty-three controls outside them — twenty-seven
 * `btn-outline-primary`, four `btn-outline-success`, one
 * `btn-outline-warning` — which is not a fifth and sixth variant so much as
 * three modules each inventing their own weight scale: « Générer le
 * contrat » and « Accepter » looked equally important, and « Archiver »
 * looked more dangerous than « Supprimer définitivement » next to it.
 *
 * A two-way ratchet, like `Tests\Core\View\UxConventionsTest`: fixing a
 * template listed below without removing it from the list fails the test.
 * The list is the honest record of what is left, not a place to park a new
 * one.
 */
final class ButtonVocabularyTest extends TestCase
{
    /**
     * The only variants §7.4 knows: primary action, neutral, destructive
     * trigger, destructive confirmation.
     */
    private const ALLOWED = [
        'btn-primary',
        'btn-outline-secondary',
        'btn-outline-danger',
        'btn-danger',
    ];

    /**
     * Empty, and meant to stay that way: `_pricing.html.twig` was the last
     * entry, and it left when its section forms became dialogs. Shrink
     * this list, never grow it.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [];

    /** @return list<string> */
    private function templateRoots(): array
    {
        $root = dirname(__DIR__, 3) . '/modules';

        return [$root . '/rental/views', $root . '/leadership/views'];
    }

    public function testEveryButtonUsesOneOfTheFourVariants(): void
    {
        $modulesDir = dirname(__DIR__, 3) . '/modules/';
        $offenders = [];

        foreach ($this->templateRoots() as $dir) {
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                preg_match_all('/btn-(?:outline-)?[a-z]+/', $contents, $matches);

                $bad = array_values(array_unique(array_diff(
                    array_filter($matches[0], static fn (string $c): bool => $c !== 'btn-sm' && $c !== 'btn-lg'),
                    self::ALLOWED
                )));
                if ($bad !== []) {
                    $relative = str_replace($modulesDir, '', $file->getPathname());
                    $offenders[$relative] = $bad;
                }
            }
        }

        foreach (self::ALLOWLIST as $allowed) {
            $this->assertArrayHasKey(
                $allowed,
                $offenders,
                $allowed . ' no longer breaks the button vocabulary — remove it from ALLOWLIST in the same commit.'
            );
            unset($offenders[$allowed]);
        }

        $this->assertSame(
            [],
            $offenders,
            "design.md §7.4 has four button variants. These use something else:\n"
                . print_r($offenders, true)
        );
    }
}
