<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use PHPUnit\Framework\TestCase;

/**
 * `rental` consuming `inbound_mail`, in both directions of disabling
 * (§7.5).
 *
 * The same shape as the calendar circularity one iteration earlier, and
 * pinned the same way: the parts that live in the procedural bootstrap get
 * a source-level assertion, because no unit test loads that file.
 *
 * The asymmetry worth naming: this dependency is **not** circular.
 * `inbound_mail` never names `rental`, in either its code or its wiring —
 * only the composition root knows both. So the only thing to prove here is
 * that `rental` degrades, and that it reaches the other module through its
 * published API rather than its internals.
 */
class RentalInboundMailWiringTest extends TestCase
{
    private static function indexSource(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        return $contents;
    }

    public function testRentalsConsumerIsRegisteredOnlyWhenInboundMailAndRentalAreBothEnabled(): void
    {
        // The registry now lives in the sync handler's lazy factory in
        // public/scheduler-bootstrap.php; the rental consumer joins it
        // only when both halves of the pair are enabled.
        $bootstrap = file_get_contents(dirname(__DIR__, 4) . '/public/scheduler-bootstrap.php');
        $this->assertNotFalse($bootstrap);

        $this->assertMatchesRegularExpression(
            '/if \(\$inboundMail !== null && in_array\(\'rental\', \$enabledModuleIds, true\)\) \{/',
            $bootstrap,
            'The bootstrap must only register the rental consumer when inbound_mail resolves and rental is enabled.'
        );
        $this->assertStringContainsString('new \\Modules\\Rental\\Mail\\RentalMessageConsumer(', $bootstrap);
    }

    /**
     * `learnFrom()` says a failed write in the journal (#1221), and it only
     * runs for a link a person or the model made. The model's links come
     * from the scheduler's registry, not from the web one: a consumer built
     * there without the journal fell back to the server's log alone, and
     * nothing in the code said so. Both constructions name it.
     *
     * @return array<string, array{string}>
     */
    public static function consumerConstructions(): array
    {
        return [
            'the web request' => ['public/index.php'],
            'the scheduler and its sync' => ['public/scheduler-bootstrap.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('consumerConstructions')]
    public function testEveryRentalConsumerIsBuiltWithTheJournal(string $file): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
        $this->assertNotFalse($source);

        $construction = self::constructionOf($source, '\\Modules\\Rental\\Mail\\RentalMessageConsumer');

        $this->assertNotSame('', $construction, "$file must build the rental consumer.");
        $this->assertMatchesRegularExpression('/journalService:\s*\$journalService/', $construction);
    }

    /**
     * The source of the first `new $class(…)`, read from the tokens so a
     * parenthesis in a comment or a string does not count.
     */
    private static function constructionOf(string $source, string $class): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }
            $name = '';
            $j = $i + 1;
            while ($j < $count && (!is_string($tokens[$j]) || $tokens[$j] !== '(')) {
                $name .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $j++;
            }
            if (trim($name) !== $class) {
                continue;
            }

            $depth = 0;
            $text = '';
            for (; $j < $count; $j++) {
                $text .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($tokens[$j] === '(') {
                    $depth++;
                } elseif ($tokens[$j] === ')' && --$depth === 0) {
                    return $text;
                }
            }
        }

        return '';
    }

    public function testRentalReachesInboundMailThroughItsPublishedApiOnly(): void
    {
        // The §7.5 shape: a null-seeded handle to the module's Api
        // interface, never its repositories or its sync service.
        $source = self::indexSource();

        $this->assertStringContainsString('$inboundMailForOthers = null;', $source);

        $rentalBlock = substr($source, (int) strpos($source, 'Inbound mail (§7.6)'));
        $rentalBlock = substr($rentalBlock, 0, 2000);

        $this->assertStringNotContainsString('InboundMailboxRepository', $rentalBlock);
        $this->assertStringNotContainsString('MailboxSyncService', $rentalBlock);
    }

    public function testTheCommunicationServiceIsSeededNullSoTheTabSimplyDisappears(): void
    {
        $this->assertStringContainsString('$rentalCommunicationService = null;', self::indexSource());
    }

    /**
     * The one asymmetry with the calendar case: `inbound_mail` does not
     * consume `rental` at all, so there is no second direction to wire and
     * none to break.
     */
    public function testInboundMailNeverReachesBackIntoRental(): void
    {
        foreach (glob(dirname(__DIR__, 4) . '/modules/inbound_mail/src/*/*.php') ?: [] as $file) {
            $code = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            $this->assertStringNotContainsString(
                'Modules\\Rental',
                $code,
                basename($file) . ' must not name the rental module.'
            );
        }
    }

    /**
     * And `rental` never reaches into `inbound_mail`'s internals either:
     * everything it touches lives under that module's `Api` namespace, so
     * disabling the module leaves `rental` with a null dependency rather
     * than an autoload failure.
     */
    public function testRentalOnlyEverNamesTheInboundMailApi(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__, 4) . '/modules/rental/src/*/*.php') ?: [],
            glob(dirname(__DIR__, 4) . '/modules/rental/src/*.php') ?: []
        );
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all('/Modules\\\\InboundMail\\\\(\w+)/', $contents, $matches) === 0) {
                continue;
            }

            foreach (array_unique($matches[1]) as $namespace) {
                $this->assertSame(
                    'Api',
                    $namespace,
                    basename($file) . ' must only reach inbound_mail through its Api namespace.'
                );
            }
        }
    }
}
