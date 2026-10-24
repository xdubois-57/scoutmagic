<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * `@mass_mail/unsubscribe.html.twig`, the public page a mail's
 * unsubscribe link opens. A suppressed address stays suppressed until the
 * unit lifts it — no member can undo it from their own space — so the
 * page must not promise a self-service way back.
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

    public function testTheConfirmationSendsThePersonToTheUnitToComeBack(): void
    {
        $html = $this->render('confirm');

        $this->assertStringContainsString("adressez-vous à l'unité", $html);
        $this->assertStringNotContainsString('réinscrire', $html);
        $this->assertStringNotContainsString('espace animés', $html);
    }
}
