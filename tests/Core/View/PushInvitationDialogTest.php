<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * base.html.twig's « Activer les notifications ? » dialog
 * (ARCHITECTURE.md §8.110) — the render-time half, tested through the
 * real layout for the same reason as HelpDiscoveryDialogTest: a Twig
 * global wired to the wrong key renders a perfectly fine page with no
 * dialog in it, and nothing else would say so.
 *
 * The script order is asserted here rather than in a Vitest spec because
 * it is a property of the LAYOUT and of nothing else: push-invitation.js
 * decides synchronously whether it is opening and help-discovery.js reads
 * that decision on its first statement, so swapping the two lines gives a
 * phone both modals at once — with both files unchanged and every unit
 * test still green.
 */
final class PushInvitationDialogTest extends TestCase
{
    /**
     * @param array<string, mixed> $globals
     */
    private function render(array $globals = []): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'parent@test.be');
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'nonce123');
        $twig->addGlobal('vapid_public_key', 'BFakeVapidPublicKey');

        foreach ($globals as $key => $value) {
            $twig->addGlobal($key, $value);
        }

        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_field',
            fn (): string => '<input type="hidden" name="_csrf_token" value="test">',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));

        return $twig->render('base.html.twig');
    }

    public function testNoDialogIsRenderedAtAllWhenTheGlobalIsAbsent(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('push-invitation-modal', $html);
        $this->assertStringNotContainsString('Activer les notifications', $html);
    }

    public function testTheDialogRendersItsThreeAnswersAndThePublicKeyTheBrowserNeeds(): void
    {
        $html = $this->render(['push_invitation' => true]);

        $this->assertStringContainsString('id="push-invitation-modal"', $html);
        $this->assertStringContainsString('Activer les notifications ?', $html);
        $this->assertStringContainsString('data-push-invitation-enable', $html);
        $this->assertStringContainsString('data-push-invitation-later', $html);
        $this->assertStringContainsString('data-push-invitation-done', $html);
        $this->assertStringContainsString('data-vapid-public-key="BFakeVapidPublicKey"', $html);
    }

    /**
     * « Plus tard » is permanent, so the dialog owes the reader the way
     * back in. Asserted on the sentence rather than on a class: what
     * would break it is somebody shortening the label to « Plus tard »,
     * which reads as a postponement and is not one.
     */
    public function testPlusTardSaysWhereToGoAfterwards(): void
    {
        $html = $this->render(['push_invitation' => true]);

        $this->assertMatchesRegularExpression(
            '/Plus tard[^<]*Mon compte/u',
            $html,
            "« Plus tard » never comes back, so the label has to name « Mon compte »."
        );
    }

    /**
     * The invitation's script runs BEFORE the tips dialog's, and that
     * order is the only thing keeping a phone from getting two modals at
     * once: push-invitation.js sets its flag synchronously, and
     * help-discovery.js reads it on its first statement.
     */
    public function testTheInvitationScriptIsLoadedBeforeTheTipsScript(): void
    {
        $html = $this->render(['push_invitation' => true]);

        $invitation = strpos($html, '/assets/js/push-invitation.js');
        $tips = strpos($html, '/assets/js/help-discovery.js');

        $this->assertIsInt($invitation);
        $this->assertIsInt($tips);
        $this->assertLessThan($tips, $invitation);
    }

    /**
     * And the toolbox both push surfaces share is loaded before the file
     * that calls into it — a defer'd script runs in document order, so
     * this IS the dependency declaration.
     */
    public function testTheSharedSubscribeToolboxIsLoadedBeforeItsCaller(): void
    {
        $html = $this->render(['push_invitation' => true]);

        $toolbox = strpos($html, '/assets/js/push-subscribe.js');
        $invitation = strpos($html, '/assets/js/push-invitation.js');

        $this->assertIsInt($toolbox);
        $this->assertIsInt($invitation);
        $this->assertLessThan($invitation, $toolbox);
    }
}
