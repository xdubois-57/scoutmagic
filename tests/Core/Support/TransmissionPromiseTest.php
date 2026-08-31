<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use PHPUnit\Framework\TestCase;

/**
 * The codebase promised, in four places, that the diagnostic archive is
 * never transmitted. Roadmap IT-26 makes it transmittable — by one
 * explicit act — and this test is what keeps the four documents from
 * drifting back to the old sentence.
 *
 * It is deliberately about the WORDS. A promise a user reads is not
 * enforced by a unit test of a service; it is enforced by the sentence
 * still being true, and the only mechanical thing available is that the
 * absolute form of it is gone and the conditional form is present.
 */
final class TransmissionPromiseTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function documentProvider(): array
    {
        return [
            'the architecture note' => ['ARCHITECTURE.md', ['administrator', 'explicit']],
            'the security note' => ['SECURITY.md', ['IT-26', 'server-side']],
            'the specification' => ['specifications.md', ['archive non transmise']],
            "the archive's own README" => ['core/Support/SupportPackageService.php', ['Configuration > Support']],
            'the RGPD default content' => ['core/View/rgpd_default.html', ['sous-traitante']],
            'the RGPD prompt' => ['core/View/RgpdContentService.php', ['sous-traitante']],
            'the support page' => ['core/View/templates/config/support.html.twig', ['Contacter le support']],
            'the help topic' => ['docs/help/support.md', ['archive non transmise']],
        ];
    }

    /**
     * @param list<string> $mustMention
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('documentProvider')]
    public function testEachDocumentDescribesTheOneWayAnArchiveCanLeave(string $path, array $mustMention): void
    {
        $content = (string) file_get_contents(self::root() . '/' . $path);

        foreach ($mustMention as $needle) {
            $this->assertStringContainsString(
                $needle,
                $content,
                $path . ' must describe the deliberate transmission (roadmap IT-26), not only forbid an automatic one.'
            );
        }
    }

    /**
     * The absolute claims, gone. Each of these said something that is no
     * longer true, and each was read by somebody deciding whether to
     * trust the feature.
     *
     * @return array<string, array{string, string}>
     */
    public static function retiredClaimProvider(): array
    {
        return [
            'architecture' => ['ARCHITECTURE.md', '**Nothing is ever transmitted, in any form**'],
            'security' => ['SECURITY.md', 'an administrator sends it by hand or it goes nowhere'],
            'specification' => ['specifications.md', '**Nothing is ever transmitted automatically**'],
            'readme' => ['core/Support/SupportPackageService.php', "Rien n'a été transmis automatiquement"],
            'rgpd' => ['core/View/rgpd_default.html', "seule une action manuelle d'un administrateur peut l'envoyer"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retiredClaimProvider')]
    public function testTheAbsoluteClaimIsGone(string $path, string $claim): void
    {
        $this->assertStringNotContainsString(
            $claim,
            (string) file_get_contents(self::root() . '/' . $path),
            $path . ' still carries a claim IT-26 made untrue.'
        );
    }
}
