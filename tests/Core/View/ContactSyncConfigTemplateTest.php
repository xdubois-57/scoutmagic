<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * Full render of core/View/templates/config/contact_sync.html.twig — the
 * superadmin's « Synchronisation des contacts » page — against the real
 * template directories.
 *
 * Tests\Core\Contact\Controller\ContactSyncConfigControllerTest renders a
 * one-line stand-in for the page, which is right for what the controller
 * does and blind to what the page says. Issue #1378: the page opened on the
 * technical « Coupe-circuit » card with no word on what the feature is for
 * and no road to one's OWN device, which lives in Mon compte.
 */
class ContactSyncConfigTemplateTest extends TestCase
{
    private function render(bool $syncEnabled = true): string
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'super@example.org');
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('current_path', '/config/synchronisation-contacts');
        $twig->addGlobal('config_mode', true);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');

        return $twig->render('config/contact_sync.html.twig', [
            'sync_enabled' => $syncEnabled,
            'live_count' => 0,
            'credentials' => [],
            'owners' => [],
        ]);
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    public function testAnIntroductionCardComesBeforeTheCutOutCard(): void
    {
        $html = $this->render();

        $intro = strpos($html, 'Configurer la synchronisation dans Mon compte');
        $cutOut = strpos($html, 'Coupe-circuit</h2>');
        $this->assertNotFalse($intro, 'the introduction card is missing');
        $this->assertNotFalse($cutOut);
        $this->assertLessThan($cutOut, $intro, 'the introduction must precede « Coupe-circuit »');
    }

    public function testTheIntroductionExplainsWhatTheFeatureIsFor(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            'Retrouvez automatiquement les coordonnées des animateurs et du Staff d\'U dans le carnet d\'adresses '
            . 'de votre téléphone ou de votre ordinateur.',
            preg_replace('/\s+/', ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5))
        );
    }

    /**
     * Straight to the page that creates a CardDAV access, not to an
     * intermediate one, and named for what it is — Mon compte — so the
     * global administration below is not mistaken for it.
     */
    public function testTheLinkGoesStraightToTheOwnDevicesPageAndNamesMonCompte(): void
    {
        $xpath = $this->dom($this->render());

        $links = $xpath->query("//a[@href='/account/devices']");
        $this->assertNotFalse($links);
        $this->assertSame(1, $links->length, 'exactly one road to the personal page');
        $this->assertStringContainsString(
            'Configurer la synchronisation dans Mon compte',
            preg_replace('/\s+/', ' ', trim((string) $links->item(0)?->textContent)) ?? ''
        );
    }

    public function testTheLinkIsARealAnchorSoTheKeyboardReachesIt(): void
    {
        $xpath = $this->dom($this->render());

        $link = $xpath->query("//a[@href='/account/devices']")->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame('a', $link->nodeName);
        $this->assertNotSame('-1', $link->getAttribute('tabindex'));
        $this->assertSame('', $link->getAttribute('aria-hidden'));
    }

    /**
     * The new card must not touch the two existing ones: same order, same
     * forms, the cut-out still posting to its own route.
     */
    public function testTheExistingCardsAndTheirFormsAreUnchanged(): void
    {
        $html = $this->render();

        $cutOut = strpos($html, 'Coupe-circuit</h2>');
        $devices = strpos($html, 'Appareils autorisés</h2>');
        $this->assertNotFalse($cutOut);
        $this->assertNotFalse($devices);
        $this->assertLessThan($devices, $cutOut);
        $this->assertStringContainsString('action="/config/synchronisation-contacts/switch"', $html);
        $this->assertStringContainsString('Couper la synchronisation', $html);
    }

    /**
     * The link opens the personal page; it does not switch anything on, and
     * it must not suggest it gets round a closed cut-out.
     */
    public function testWhenTheCutOutIsClosedTheIntroductionSaysNoDeviceSynchronisesYet(): void
    {
        $open = $this->render(true);
        $closed = $this->render(false);

        $this->assertStringNotContainsString('actuellement coupée', $open);
        $this->assertStringContainsString('actuellement coupée', $closed);
        $this->assertStringContainsString('/account/devices', $closed);
    }
}
