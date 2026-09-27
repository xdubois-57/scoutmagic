<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\File\Held\HeldDocument;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * The page the installed application gets instead of a file (issue #502),
 * assembled through base.html.twig as it is served: its three ways on,
 * none of which navigates the window to the file.
 */
final class DocumentViewerTemplateTest extends TestCase
{
    private const BROWSER = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
    private const APP = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    public function testAHeldPdfOffersTheBrowserTheDownloadAndTheWayBack(): void
    {
        $html = $this->render(new HeldDocument(1, self::BROWSER, self::APP, 'Fiche santé.pdf', 'application/pdf', 2048));

        $this->assertStringContainsString('Fiche santé.pdf', $html);
        $this->assertStringContainsString('Document PDF · 2 Ko', $html);
        $this->assertMatchesRegularExpression(
            '~<a href="/document/' . self::BROWSER . '"[^>]*data-document-viewer-browser>\s*<i[^>]*></i> Ouvrir dans le navigateur~',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~<a href="/document/telecharger/' . self::APP . '"[^>]*download="Fiche santé.pdf"[^>]*data-document-viewer-download~',
            $html
        );
        // file-viewer.js runs on this page too, and in the installed app it
        // turns a `download` link into a navigation — here to the raw file,
        // which strands the window. The opt-out is what keeps it off.
        $this->assertMatchesRegularExpression(
            '~<a href="/document/telecharger/' . self::APP . '"[^>]*data-file-link-raw~',
            $html,
            '« Télécharger » lost data-file-link-raw: file-viewer.js would navigate the installed window to the raw file.'
        );
        $this->assertMatchesRegularExpression('~<a href="/members/7"[^>]*data-document-viewer-back>\s*<i[^>]*></i> Retour~', $html);
        $this->assertStringContainsString('/assets/js/document-viewer.js', $html);
        $this->assertStringNotContainsString('<img src="/document/', $html, 'A PDF has no preview: only an image does.');
    }

    public function testAnImageIsPreviewedThroughTheApplicationKeyNeverTheBrowserOne(): void
    {
        $html = $this->render(new HeldDocument(1, self::BROWSER, self::APP, 'photo.jpg', 'image/jpeg', 10));

        $this->assertStringContainsString('<img src="/document/telecharger/' . self::APP . '"', $html);
        $this->assertStringNotContainsString('<img src="/document/' . self::BROWSER . '"', $html, 'The preview would spend the browser key.');
    }

    public function testAFileTooLargeToHoldSaysSoAndKeepsOnlyTheWayBack(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString('trop volumineux', $html);
        $this->assertStringNotContainsString('data-document-viewer-browser', $html);
        $this->assertStringNotContainsString('data-document-viewer-download', $html);
        $this->assertStringContainsString('data-document-viewer-back', $html);
    }

    public function testAVisitorWhoIsNotSignedInIsSentToThePublicAddressWhichStaysUsable(): void
    {
        $html = $this->render(null, '/locations/suivi/1/abc/calendrier.ics', 'unavailable');

        $this->assertMatchesRegularExpression(
            '~<a href="/locations/suivi/1/abc/calendrier.ics"[^>]*data-document-viewer-browser data-document-viewer-reusable>~',
            $html
        );
        $this->assertStringNotContainsString('data-document-viewer-download', $html);
        $this->assertStringNotContainsString('trop volumineux', $html);
    }

    public function testNothingHeldAndNoAddressSaysSo(): void
    {
        $html = $this->render(null, null, 'unavailable');

        $this->assertStringContainsString('ne peut pas être ouvert depuis l&#039;application installée', $html);
        $this->assertStringNotContainsString('data-document-viewer-browser', $html);
    }

    private function render(?HeldDocument $document, ?string $directUrl = null, string $reason = 'too_large'): string
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('current_path', '/members/7/autorisation-parentale');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');

        return $twig->render('document_viewer.html.twig', [
            'document' => $document,
            'direct_url' => $directUrl,
            'reason' => $reason,
            'name' => $document?->name ?? 'album.zip',
            'type_label' => $document === null ? 'Archive ZIP' : 'Document PDF',
            'size_bytes' => $document?->sizeBytes ?? 700 * 1024 * 1024,
            'back_url' => '/members/7',
        ]);
    }
}
