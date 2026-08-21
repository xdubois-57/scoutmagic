<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use PHPUnit\Framework\TestCase;

/**
 * The two wiring facts that live in the procedural bootstrap, where no unit
 * test reaches them.
 *
 * The polling task is the one module task that cannot be auto-resolved from
 * its manifest: it needs the consumer registry, and only a composition root
 * can build one. So it is registered by hand — and a handler registered in
 * only ONE of the two entry points fails unconditionally under the other
 * with "No handler registered". This codebase has shipped that exact bug
 * before (ARCHITECTURE.md §8.17/§8.20: `create_backup` was absent from
 * cron.php's hand-maintained list, and every backup under a real crontab
 * failed silently), which is why both call sites are pinned here rather
 * than trusted to review.
 */
class CompositionRootWiringTest extends TestCase
{
    private static function source(string $file): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/public/' . $file);
        self::assertNotFalse($contents);

        return $contents;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        return [
            'web' => ['index.php'],
            'cron' => ['cron.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testTheSyncHandlerIsRegisteredInBothEntryPoints(string $file): void
    {
        $this->assertStringContainsString(
            'Modules\\InboundMail\\Task\\SyncMailboxesHandler::TASK_KEY',
            self::source($file),
            $file . ' must register the inbound-mail sync handler.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsGuardOnTheModuleBeingEnabled(string $file): void
    {
        $this->assertMatchesRegularExpression(
            "/in_array\\('inbound_mail', \\\$moduleManager->getEnabledModuleIds\\(\\), true\\)/",
            self::source($file),
            $file . ' must only wire the module when it is enabled.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testTheHandlerAlwaysGetsAConsumerRegistry(string $file): void
    {
        // Constructed without one it connects to nothing — every message
        // would be fetched and discarded unclaimed — so a registration that
        // forgot it would look wired and collect nothing.
        $this->assertMatchesRegularExpression(
            '/new \\\\Modules\\\\InboundMail\\\\Task\\\\SyncMailboxesHandler\\(\\$inboundMailConsumerRegistry\\)/',
            self::source($file),
            $file . ' must pass the consumer registry to the handler.'
        );
    }

    public function testTheRegistryIsSeededNullBeforeTheModuleBlockDecidesToBuildIt(): void
    {
        $source = self::source('index.php');

        $seeded = strpos($source, '$inboundMailConsumerRegistry = null;');
        $built = strpos($source, '$inboundMailConsumerRegistry = new \\Modules\\InboundMail\\Service\\MessageConsumerRegistry();');

        $this->assertIsInt($seeded);
        $this->assertIsInt($built);
        $this->assertLessThan($built, $seeded, 'The registry must be readable whether or not the module block ran.');
    }

    public function testTheApiIsPublishedThroughANullSeededHandleLikeEveryOtherCrossModuleDependency(): void
    {
        // §7.5: a consumer takes it as a nullable constructor dependency
        // and degrades to "no communications" when it stays null.
        $this->assertStringContainsString('$inboundMailForOthers = null;', self::source('index.php'));
    }

    public function testTheFirstRunIsBootstrappedOnTheWebPath(): void
    {
        // Without the initial nudge the self-rescheduling chain never
        // starts, and the box is polled exactly never.
        $this->assertStringContainsString(
            'Modules\\InboundMail\\Task\\SyncMailboxesHandler::bootstrap($schedulerService)',
            self::source('index.php')
        );
    }

    /**
     * The module must not name any consumer, in either direction: doing so
     * would make disabling the consumer break `inbound_mail` at autoload
     * time rather than leaving its registry one entry shorter.
     */
    public function testTheModuleNeverNamesAConsumingModule(): void
    {
        $files = self::moduleSourceFiles();
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $code = self::codeWithoutComments($file);

            foreach (['Modules\\Rental', 'Modules\\Finance', 'Modules\\Registration'] as $consumer) {
                $this->assertStringNotContainsString(
                    $consumer,
                    $code,
                    basename($file) . ' must not name a consuming module.'
                );
            }
        }
    }

    /**
     * And no rental vocabulary either — a `LOC-` prefix in this module's
     * *code* would be exactly the consumer-specific logic §7.6 keeps out of
     * it.
     *
     * Comments are excluded on purpose: the docblocks use a booking
     * reference as an illustration of what a consumer might recognise, and
     * that is documentation of the contract rather than an implementation
     * of it. What must not exist is a line of code that acts on the shape.
     */
    public function testTheModuleKnowsNothingAboutWhatAReferenceLooksLike(): void
    {
        foreach (self::moduleSourceFiles() as $file) {
            $this->assertStringNotContainsString(
                'LOC-',
                self::codeWithoutComments($file),
                basename($file) . ' must not know a consumer\'s reference format.'
            );
        }
    }

    /**
     * @return string[]
     */
    private static function moduleSourceFiles(): array
    {
        return glob(dirname(__DIR__, 3) . '/modules/inbound_mail/src/*/*.php') ?: [];
    }

    /**
     * A file's code with every comment and docblock removed, so an
     * assertion about what the module *does* is not defeated by an example
     * in a docblock explaining what it deliberately does not do.
     */
    private static function codeWithoutComments(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
