<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Phone;

use Core\Phone\MessagingApps;
use PHPUnit\Framework\TestCase;

/**
 * WhatsApp and Signal are named in three languages that cannot check each
 * other — the links in JavaScript, the default RGPD text and the rule the
 * generated policy keeps — as the map's tile provider is
 * (Tests\Core\Geo\MapTilesTest).
 *
 * A policy that names a service the site no longer opens, or fails to name
 * one it does, is a compliance defect no test of the links would reveal.
 */
final class MessagingAppsTest extends TestCase
{
    public function testTheScriptOpensTheHostsTheRgpdPageNames(): void
    {
        $js = $this->read('public/assets/js/phone-link.js');

        $this->assertStringContainsString("'" . MessagingApps::WHATSAPP_ORIGIN . "/'", $js);
        $this->assertStringContainsString("'" . MessagingApps::SIGNAL_ORIGIN . "/#p/+'", $js);
    }

    public function testNoOtherScriptWritesTheseLinks(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/public/assets/js/*.js') ?: [] as $file) {
            if (basename($file) === 'phone-link.js') {
                continue;
            }
            $code = (string) file_get_contents($file);
            $this->assertStringNotContainsString('wa.me/', $code, basename($file) . ' writes a WhatsApp link of its own.');
            $this->assertStringNotContainsString('signal.me/', $code, basename($file) . ' writes a Signal link of its own.');
        }
    }

    public function testTheDefaultRgpdPageNamesBothServicesAndSaysTheyAreNoSubProcessors(): void
    {
        $rgpd = (string) preg_replace('/\s+/u', ' ', $this->read('core/View/rgpd_default.html'));

        foreach ([MessagingApps::WHATSAPP_NAME, MessagingApps::SIGNAL_NAME] as $name) {
            $this->assertStringContainsString($name, $rgpd);
        }
        $this->assertStringContainsString('wa.me', $rgpd);
        $this->assertStringContainsString('signal.me', $rgpd);
        $this->assertStringContainsString('ne sont donc pas des sous-traitants', $rgpd);
        $this->assertStringContainsString('Aucun serveur, ni celui du site ni un autre, n\'intervient', $rgpd);
    }

    public function testNeitherServiceIsListedAmongTheSubProcessors(): void
    {
        $rgpd = $this->read('core/View/rgpd_default.html');
        $start = strpos($rgpd, '<h2>4.');
        $end = strpos($rgpd, '<h2>6.');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $sections = substr($rgpd, (int) $start, (int) $end - (int) $start);
        $this->assertStringNotContainsString('wa.me', $sections);
        $this->assertStringNotContainsString('signal.me', $sections);
    }

    public function testTheGeneratedPolicyKeepsTheSection(): void
    {
        $prompt = (string) preg_replace('/\s+/u', ' ', $this->read('core/View/RgpdContentService.php'));

        $this->assertStringContainsString('Appeler ou écrire depuis un numéro de téléphone', $prompt);
        $this->assertStringContainsString('WhatsApp et Signal ne sont **PAS des sous-traitants**', $prompt);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }
}
