<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * `@mass_mail/unsubscribe.html.twig`, the public page a mail's
 * unsubscribe link opens. The way back is « Réactiver » on the member's
 * page for a member's address, the unit for anybody else's — there is no
 * « espace animés » to re-subscribe from, which the page used to promise.
 */
final class UnsubscribePageRenderingTest extends TestCase
{
    private function render(string $state): string
    {
        return TestTwig::create(['mass_mail'])->render('@mass_mail/unsubscribe.html.twig', [
            'state' => $state,
            'delivery_id' => 10,
            'token' => 'abc',
            'site_name' => 'Unité test',
        ]);
    }

    public function testTheConfirmationSaysHowToComeBack(): void
    {
        $html = $this->render('confirm');

        $this->assertStringContainsString("réactivez l'adresse sur la page de l'animé", $html);
        $this->assertStringContainsString("demandez à l'unité de le faire", $html);
        $this->assertStringContainsString('La réactivation vaut pour cette adresse dans toutes les listes.', $html);
        $this->assertStringNotContainsString('réinscrire', $html);
        $this->assertStringNotContainsString('espace animés', $html);
    }
}
