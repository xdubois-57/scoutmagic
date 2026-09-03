<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use PHPUnit\Framework\TestCase;

/**
 * The wiring facts that live in the composition roots, where no unit test
 * reaches them.
 *
 * The polling task is the one module task that cannot be auto-resolved
 * from its manifest: it needs the message-consumer registry, whose
 * consumers belong to OTHER modules — only a composition root can build
 * one. That composition now lives in public/scheduler-bootstrap.php, the
 * ONE file both entry points call identically, as a lazy factory; what
 * this file pins is the factory's own load-bearing facts (the registry is
 * passed, the camps consumer is registered last) and that neither entry
 * point re-grows a private copy of the wiring.
 */
class CompositionRootWiringTest extends TestCase
{
    private static function source(string $file): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/public/' . $file);
        self::assertNotFalse($contents);

        return $contents;
    }

    public function testTheSharedBootstrapRegistersTheSyncHandlerAsALazyFactory(): void
    {
        $bootstrap = self::source('scheduler-bootstrap.php');

        $this->assertStringContainsString(
            "registerHandlerFactory(\n            'inbound_mail',\n            \\Modules\\InboundMail\\Task\\SyncMailboxesHandler::TASK_KEY",
            $bootstrap,
            'The shared bootstrap must register the sync handler as a lazy factory.'
        );
        // Constructed without a registry it connects to nothing — every
        // message would be stored unrecognised, none of it associated —
        // so a factory that forgot it would look wired and classify
        // nothing. What must hold is that the registry the factory built
        // reaches the handler; how the argument list is spelled, and
        // whether the registry is passed directly or through a local, is
        // not this test's business.
        $this->assertStringContainsString('$inboundConsumerRegistry($context)', $bootstrap);
        $this->assertMatchesRegularExpression(
            '/new \\\\Modules\\\\InboundMail\\\\Task\\\\SyncMailboxesHandler\(\s*\$(inboundConsumerRegistry\(\$context\)|registry)/',
            $bootstrap
        );
    }

    /**
     * The deferred, content-level pass gets the SAME consumers as the
     * arrival pass, from one shared builder.
     *
     * Two copies of that three-module wiring would be two places for it to
     * drift — and the way it drifts is silently: a consumer registered for
     * one pass and forgotten for the other simply never proposes anything,
     * with nothing to show for it.
     */
    public function testTheDeferredAnalysisTaskSharesTheSyncsConsumerGraph(): void
    {
        $bootstrap = self::source('scheduler-bootstrap.php');

        $this->assertMatchesRegularExpression(
            '/new \\\\Modules\\\\InboundMail\\\\Task\\\\AnalyzeStoredMessagesHandler\(\s*\$inboundConsumerRegistry\(\$context\)/',
            $bootstrap
        );
        // And it is seeded, or the self-rescheduling chain never starts and
        // the pass runs exactly never.
        $this->assertStringContainsString(
            'Modules\\InboundMail\\Task\\AnalyzeStoredMessagesHandler::bootstrap($schedulerService)',
            $bootstrap
        );
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
    public function testNoEntryPointAssemblesTheSyncGraphItself(string $file): void
    {
        // A private SYNC registry re-grown in one entry point is exactly
        // the drift that once ran the real crontab's sync with an EMPTY
        // registry: every message unclaimed, none stored, the cursor
        // advancing past mail that was never coming back. What that
        // forbids is building the handler, or registering a consumer
        // eagerly, anywhere but the shared bootstrap.
        $source = self::source($file);

        $this->assertStringNotContainsString(
            'new \\Modules\\InboundMail\\Task\\SyncMailboxesHandler(',
            $source,
            $file . ' must leave the sync handler to the shared scheduler bootstrap.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/->register\(\s*new \\\\Modules\\\\[A-Za-z]+\\\\Mail\\\\/',
            $source,
            $file . ' must not register a message consumer eagerly — that is the shared bootstrap\'s job.'
        );
    }

    /**
     * The one registry the web path is allowed, and the shape it must keep.
     *
     * `/files/{id}` has to ask a consumer whether a requester may read an
     * inbound attachment (§8.58, Service\InboundMessageAccessRegistry), and
     * only a composition root can supply one. Registering the consumers
     * EAGERLY there would rebuild the three-module graph on every page
     * view — precisely what the sync task's own lazy factory exists to
     * avoid — so they go in as factories and only the one an association
     * names is ever built.
     */
    public function testTheWebPathsReadRegistryIsFactoryOnly(): void
    {
        $source = self::source('index.php');

        $this->assertStringContainsString(
            'new \\Modules\\InboundMail\\Service\\InboundMessageAccessRegistry(',
            $source,
            'Without the checker, an inbound attachment is gated by its role_min floor alone.'
        );
        $this->assertStringContainsString('$inboundReadConsumers->registerFactory(', $source);
        $this->assertStringNotContainsString('$inboundReadConsumers->register(', $source);
    }

    public function testTheApiIsPublishedThroughANullSeededHandleLikeEveryOtherCrossModuleDependency(): void
    {
        // §7.5: a consumer takes it as a nullable constructor dependency
        // and degrades to "no communications" when it stays null.
        $this->assertStringContainsString('$inboundMailForOthers = null;', self::source('index.php'));
    }

    public function testTheFirstRunIsSeededByTheSharedBootstrap(): void
    {
        // Without the initial nudge the self-rescheduling chain never
        // starts, and the box is polled exactly never. Seeded in the
        // shared bootstrap, so a site reached only by its crontab still
        // polls.
        //
        // The settings go with it: bootstrap() is also what pulls a run
        // queued at an older, longer interval forward, and handed no
        // settings it silently falls back to the default interval — which
        // would make « Intervalle entre deux relèves » a field that
        // changes nothing until the site is restarted.
        $this->assertStringContainsString(
            'Modules\\InboundMail\\Task\\SyncMailboxesHandler::bootstrap($schedulerService, $settingService)',
            self::source('scheduler-bootstrap.php')
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
