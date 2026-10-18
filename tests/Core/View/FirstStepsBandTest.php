<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * base.html.twig's « Premiers pas » band (issue #718 § A), through the real
 * layout: public/index.php sets `first_steps` only when the band is owed
 * (Core\Config\FirstStepsService), so the two states are "absent" and
 * "there, with the three steps in their order".
 */
final class FirstStepsBandTest extends TestCase
{
    /**
     * @param array<string, mixed> $globals
     */
    private function render(array $globals = []): string
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.be');
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'nonce123');

        foreach ($globals as $key => $value) {
            $twig->addGlobal($key, $value);
        }

        return $twig->render('base.html.twig');
    }

    public function testNoBandWithoutTheGlobal(): void
    {
        $this->assertStringNotContainsString('first-steps-band', $this->render());
        $this->assertStringNotContainsString('first-steps-band', $this->render(['first_steps' => false]));
    }

    public function testTheBandLinksTheThreeStepsInOrderAndCanBeHidden(): void
    {
        $html = $this->render(['first_steps' => true]);

        $this->assertStringContainsString('id="first-steps-band"', $html);
        $modules = strpos($html, 'href="/config/modules"');
        $health = strpos($html, 'href="/config/maintenance"');
        $attention = strpos($html, 'href="/admin/points-attention"');
        $this->assertNotFalse($modules);
        $this->assertNotFalse($health);
        $this->assertNotFalse($attention);
        $this->assertLessThan($health, $modules);
        $this->assertLessThan($attention, $health);

        $this->assertStringContainsString('href="/aide/premiers-pas"', $html);
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/config/premiers-pas/masquer"[^>]*>\s*<input type="hidden" name="_csrf_token"#',
            $html
        );
    }
}
