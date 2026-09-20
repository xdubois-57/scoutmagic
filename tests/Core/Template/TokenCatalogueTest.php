<?php

declare(strict_types=1);

namespace Tests\Core\Template;

use Core\Template\TokenCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The declared, closed list a rich-text field turns into a variable
 * palette (ARCHITECTURE.md §8.114).
 */
class TokenCatalogueTest extends TestCase
{
    private function catalogue(): TokenCatalogue
    {
        return TokenCatalogue::of([
            'prix_total' => 'Le total à payer',
            'bien' => 'Le nom du bien loué',
        ]);
    }

    public function testThePaletteCarriesTheShapeTheRichTextFieldReads(): void
    {
        $this->assertSame(
            [
                ['keyword' => 'prix_total', 'placeholder' => '{{ prix_total }}', 'description' => 'Le total à payer'],
                ['keyword' => 'bien', 'placeholder' => '{{ bien }}', 'description' => 'Le nom du bien loué'],
            ],
            $this->catalogue()->palette()
        );
    }

    public function testThePaletteKeepsDeclarationOrder(): void
    {
        $this->assertSame(['prix_total', 'bien'], $this->catalogue()->keywords());
    }

    public function testTheListIsClosed(): void
    {
        $this->assertTrue($this->catalogue()->has('bien'));
        $this->assertFalse($this->catalogue()->has('prix_ttc'));
        $this->assertNull($this->catalogue()->describe('prix_ttc'));
    }

    public function testADescriptionIsWhatTheEditorShowsBesideTheKeyword(): void
    {
        $this->assertSame('Le total à payer', $this->catalogue()->describe('prix_total'));
    }
}
