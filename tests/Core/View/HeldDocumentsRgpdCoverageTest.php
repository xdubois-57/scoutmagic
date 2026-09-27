<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\File\Held\HeldDocumentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documents the installed application puts aside (issue #502) are
 * personal data kept for a while — a health sheet, a parental
 * authorisation — so both the default RGPD notice and the generation
 * prompt say so, and say for how long (AGENTS.md § RGPD page maintenance).
 *
 * The durations are pinned to the constants that apply them: a lifetime
 * changed in HeldDocumentService without the notice is a notice that lies.
 */
final class HeldDocumentsRgpdCoverageTest extends TestCase
{
    private const NOTICE = 'core/View/rgpd_default.html';
    private const PROMPT = 'core/View/RgpdContentService.php';

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function factProvider(): array
    {
        return [
            'it exists, and where' => [['application installée']],
            'it is encrypted at rest' => [['chiffré au repos']],
            'the browser address works once, for five minutes' => [['cinq minutes']],
            'it is gone within the hour and a half, opened or not' => [['une heure et demie']],
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
                $this->assertStringContainsStringIgnoringCase(
                    $needle,
                    $content,
                    $path . ' no longer says this about the documents the installed application puts aside.'
                );
            }
        }
    }

    /**
     * « cinq minutes » and « une heure et demie » are what the code does:
     * the browser key's lifetime, and the document's lifetime plus the
     * hourly purge that deletes it.
     */
    public function testTheDurationsWrittenAreTheOnesApplied(): void
    {
        $this->assertSame(5, HeldDocumentService::BROWSER_LIFETIME_MINUTES, 'Update « cinq minutes » in both RGPD documents.');
        $this->assertSame(
            90,
            HeldDocumentService::LIFETIME_MINUTES + 60,
            'The lifetime plus the hourly purge is no longer « une heure et demie »: update both RGPD documents.'
        );
    }
}
