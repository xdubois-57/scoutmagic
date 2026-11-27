<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * The weekly host security check (issue #1358) is a new outbound flow:
 * the site now asks php.net, endoflife.date and the NIST's NVD about the
 * versions it runs. AGENTS.md § « RGPD — a new outbound flow is a
 * documentation change » requires the default notice and the generation
 * prompt to say so, even with no personal data involved — and to say that
 * part truthfully, without turning these services into processors.
 */
final class HostSecurityCheckRgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testBothDocumentsNameTheThreeSourcesAndWhatTheyReceive(): void
    {
        foreach (['core/View/rgpd_default.html', 'core/View/RgpdContentService.php'] as $path) {
            $content = self::read($path);
            foreach (['php.net', 'endoflife.date', 'NVD', 'NIST', 'Contrôle de sécurité de l\'hébergement'] as $needle) {
                $this->assertStringContainsString($needle, $content, $path . ' must name ' . $needle);
            }
        }
    }

    /** No personal data, so not a processor — and the prompt keeps the section out of sections 4 and 5.2. */
    public function testTheFlowIsDescribedAsCarryingNoPersonalData(): void
    {
        $this->assertMatchesRegularExpression(
            '~<h3>1\.7\. Contrôle de sécurité de l\'hébergement</h3>\s*<p>[^<]*Aucune donnée personnelle[^<]*pas des sous-traitants~u',
            self::read('core/View/rgpd_default.html')
        );
        $this->assertMatchesRegularExpression(
            '~section 1\.7 "Contrôle de sécurité de l\'hébergement".*?jamais en section 4 ni en section 5\.2~s',
            self::read('core/View/RgpdContentService.php')
        );
    }
}
