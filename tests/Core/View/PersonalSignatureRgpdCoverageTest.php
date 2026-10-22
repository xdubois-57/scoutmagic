<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The personal signature an account may keep (issue #926) is personal data
 * held without limit until its owner removes it, so both the default RGPD
 * notice and the generation prompt say what is kept, on what basis, and
 * when it goes (AGENTS.md § RGPD page maintenance; ticket §6c).
 */
final class PersonalSignatureRgpdCoverageTest extends TestCase
{
    private const NOTICE = 'core/View/rgpd_default.html';
    private const PROMPT = 'core/View/RgpdContentService.php';

    /**
     * The file with its line breaks folded to spaces: the prompt is wrapped
     * at 120 columns, and a fact must not depend on where a line ends.
     */
    private static function read(string $path): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function factProvider(): array
    {
        return [
            'it exists, by that name' => [['Signature personnelle']],
            'the image only, never the stroke' => [['image', 'vitesse, pression, ordre des traits', 'biométrique']],
            'it is encrypted at rest' => [['chiffrée au repos']],
            'its owner alone, no administrator' => [["ne peut la lire ni l'apposer"]],
            'never applied without a confirmation per document' => [['confirmation explicite']],
            'its legal basis is consent' => [['consentement']],
            'erased at deactivation and with the account' => [['compte est désactivé', 'avec votre compte|avec le compte']],
            'a signed document follows its module\'s retention' => [['durée de conservation du module']],
        ];
    }

    /**
     * @param list<string> $needles
     */
    #[DataProvider('factProvider')]
    public function testBothDocumentsCarryTheFact(array $needles): void
    {
        foreach ([self::NOTICE, self::PROMPT] as $path) {
            $content = self::read($path);
            foreach ($needles as $needle) {
                // A needle may name alternatives (« votre compte » on the
                // page, « le compte » in the prompt), separated by « | ».
                $found = array_filter(
                    explode('|', $needle),
                    static fn (string $one): bool => mb_stripos($content, $one) !== false
                );
                $this->assertNotSame(
                    [],
                    $found,
                    $path . ' no longer says « ' . $needle . ' » about the personal signature.'
                );
            }
        }
    }

    /** Both sections the rule names carry it: what is collected (2.1) and how long it is kept (3.1). */
    public function testTheNoticeDescribesItInBothSections(): void
    {
        $notice = self::read(self::NOTICE);
        $collected = (string) strstr((string) strstr($notice, '<h3>2.1.'), '<h3>2.2.', true);
        $retained = (string) strstr((string) strstr($notice, '<h3>3.1.'), '<h3>3.2.', true);

        $this->assertStringContainsString('Signature personnelle', $collected);
        $this->assertStringContainsString('Signature personnelle', $retained);
    }
}
