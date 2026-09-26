<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every `document.execCommand` command this product issues is watched by
 * the end-to-end alarm, and the list of files that reach for the API is
 * the one the documentation describes.
 *
 * WHY THIS TEST EXISTS
 * ---------------------------------------------------------------------
 * Issue #379: `document.execCommand` is deprecated with no standard
 * replacement, and the maintainer asked for « un test qui échoue si jamais
 * le navigateur arrête de supporter ». That alarm is
 * tests/e2e/specs/rich-text-commands.spec.js, and it can only ring for the
 * commands it happens to know about.
 *
 * An alarm with a hand-written list of what to watch goes out of date the
 * first time somebody adds a toolbar button, and goes out of date
 * SILENTLY — the suite stays green, the new command is simply not watched,
 * and the file that says "twelve commands, six files" starts lying. This
 * test is what makes that impossible: it reads the commands out of the
 * product and fails until the alarm covers each one.
 *
 * It is a source-text test, so it costs nothing and runs everywhere. It
 * cannot replace the alarm — no static read can say whether an engine
 * still performs a command — and the alarm cannot replace it either.
 */
class DeprecatedBrowserApiIsWatchedTest extends TestCase
{
    /** The alarm that has to cover every command found below. */
    private const ALARM = '/tests/e2e/specs/rich-text-commands.spec.js';

    /**
     * The files that reach for `document.execCommand`, pinned.
     *
     * Adding one is a decision, not a detail: a seventh call site means a
     * seventh thing that breaks silently the day an engine drops the API,
     * and both the alarm and the release gate's documentation name this
     * list. Changing it here is the reminder to change them too.
     *
     * The two clipboard entries are deliberately in the same list as the
     * rest even though their exposure differs — both reach `copy` only
     * after `navigator.clipboard` has been found missing, so what they
     * lose is an HTTP install's copy button rather than an editor. The
     * difference is written down in AGENTS.md § Releases; it is not a
     * reason to stop watching them.
     */
    private const CALL_SITES = [
        'mass-mail-compose.js',
        'news-form-builder.js',
        'retro-board.js',
        'rich-text-form-field.js',
        'rich-text-link.js',
        'setup.js',
    ];

    public function testEveryCommandTheProductIssuesIsCoveredByTheAlarm(): void
    {
        $alarm = self::alarm();
        $unwatched = [];

        foreach (self::commandsIssuedByTheProduct() as $command => $sources) {
            if (!str_contains($alarm, "command: '{$command}'")) {
                $unwatched[] = "{$command} (issued from " . implode(', ', $sources) . ')';
            }
        }

        $this->assertSame(
            [],
            $unwatched,
            "These execCommand commands are issued by the product and watched by nothing:\n  "
            . implode("\n  ", $unwatched)
            . "\nAdd each to TOOLBAR_COMMANDS or DIRECT_COMMANDS in " . self::ALARM
            . ", so the day an engine drops the API the suite says so (issue #379)."
        );
    }

    /**
     * The `formatBlock` arguments too: a « H4 » button added to a toolbar
     * is a block the alarm would not be asking for.
     */
    public function testEveryFormatBlockArgumentIsCoveredByTheAlarm(): void
    {
        $alarm = self::alarm();
        $unwatched = [];

        foreach (self::formatBlockArguments() as $value => $sources) {
            if (!str_contains($alarm, "value: '{$value}'")) {
                $unwatched[] = "{$value} (offered by " . implode(', ', $sources) . ')';
            }
        }

        $this->assertSame([], $unwatched, "These formatBlock arguments are offered to the user"
            . " and asserted nowhere:\n  " . implode("\n  ", $unwatched)
            . "\nAdd each to TOOLBAR_COMMANDS in " . self::ALARM . '.');
    }

    public function testTheListOfFilesReachingForTheDeprecatedApiHasNotChanged(): void
    {
        $found = [];
        foreach (self::clientScripts() as $path) {
            if (preg_match('/\bdocument\.execCommand\s*\(/', self::code($path)) === 1) {
                $found[] = basename($path);
            }
        }
        sort($found);

        $expected = self::CALL_SITES;
        sort($expected);

        $this->assertSame(
            $expected,
            $found,
            "The set of files calling document.execCommand has changed.\n"
            . "This is not a formality: the deprecated API has no standard replacement, so each\n"
            . "call site is something that stops working silently the day an engine removes it.\n"
            . "Update self::CALL_SITES, make sure " . self::ALARM . " covers the commands the\n"
            . "new or removed file issued, and keep AGENTS.md § Releases' count in step with it."
        );
    }

    /**
     * Every command name the product can pass to `document.execCommand`,
     * mapped to where it comes from.
     *
     * Three sources, because the product issues them three ways:
     *   - a literal first argument, `document.execCommand('insertText', …)`;
     *   - `data-command="bold"` in a template, which the shared toolbar
     *     dispatches through a variable — so no literal exists to find;
     *   - `command: 'bold'` in news-form-builder.js, which builds its own
     *     toolbar in JavaScript instead of in a template.
     *
     * @return array<string, list<string>>
     */
    private static function commandsIssuedByTheProduct(): array
    {
        /** @var array<string, list<string>> $commands */
        $commands = [];

        $remember = static function (string $command, string $source) use (&$commands): void {
            if (!isset($commands[$command])) {
                $commands[$command] = [];
            }
            if (!in_array($source, $commands[$command], true)) {
                $commands[$command][] = $source;
            }
        };

        // Both quote styles, everywhere below. This codebase writes single
        // quotes throughout, but a guard that only sees its own house style
        // has a blind spot the size of one keystroke — and this one had it:
        // a mutation adding `document.execCommand("styleWithCSS")` was
        // reported as a new call site and NOT as an unwatched command.
        foreach (self::clientScripts() as $path) {
            $code = self::code($path);
            preg_match_all('/document\.execCommand\s*\(\s*[\'"]([a-zA-Z]+)[\'"]/', $code, $literal);
            foreach ($literal[1] as $command) {
                $remember($command, basename($path));
            }
            // A toolbar declared in JavaScript rather than in a template.
            preg_match_all('/\bcommand:\s*[\'"]([a-zA-Z]+)[\'"]/', $code, $declared);
            foreach ($declared[1] as $command) {
                $remember($command, basename($path));
            }
        }

        foreach (self::templates() as $path) {
            preg_match_all('/data-command=["\']([a-zA-Z]+)["\']/', (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $command) {
                $remember($command, basename($path));
            }
        }

        ksort($commands);

        return $commands;
    }

    /**
     * The block names the toolbars offer, from the templates and from the
     * JavaScript-built toolbar alike.
     *
     * @return array<string, list<string>>
     */
    private static function formatBlockArguments(): array
    {
        /** @var array<string, list<string>> $values */
        $values = [];

        foreach (self::templates() as $path) {
            preg_match_all(
                '/data-command=["\']formatBlock["\']\s+data-value=["\']([a-zA-Z0-9]+)["\']/',
                (string) file_get_contents($path),
                $matches
            );
            foreach ($matches[1] as $value) {
                $values[$value][] = basename($path);
            }
        }

        foreach (self::clientScripts() as $path) {
            preg_match_all(
                '/command:\s*[\'"]formatBlock[\'"],\s*value:\s*[\'"]([a-zA-Z0-9]+)[\'"]/',
                self::code($path),
                $matches
            );
            foreach ($matches[1] as $value) {
                $values[$value][] = basename($path);
            }
        }

        // The toolbox's own default, for a button that carries no
        // data-value at all: `button.dataset.value || 'p'`.
        $values['p'][] = 'rich-text-link.js (default)';

        ksort($values);

        return array_map(static fn (array $sources): array => array_values(array_unique($sources)), $values);
    }

    /**
     * A script's code with its comment lines removed, so that an example
     * written in a comment is not read as a call.
     *
     * Only whole comment lines are dropped — stripping `//` anywhere would
     * cut a `https://` inside a string literal, and every comment in this
     * codebase that mentions a command puts it on a line of its own.
     */
    private static function code(string $path): string
    {
        $lines = array_filter(
            explode("\n", (string) file_get_contents($path)),
            static function (string $line): bool {
                $trimmed = ltrim($line);

                return !str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*');
            }
        );

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function clientScripts(): array
    {
        $root = dirname(__DIR__, 2);

        return array_merge(
            glob($root . '/public/assets/js/*.js') ?: [],
            glob($root . '/modules/*/assets/js/*.js') ?: []
        );
    }

    /** @return list<string> */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 2);

        return array_merge(
            glob($root . '/core/View/templates/partials/*.html.twig') ?: [],
            glob($root . '/modules/*/views/**/*.html.twig') ?: []
        );
    }

    private static function alarm(): string
    {
        $path = dirname(__DIR__, 2) . self::ALARM;
        self::assertFileExists($path, 'the end-to-end alarm for issue #379 is gone');

        return (string) file_get_contents($path);
    }
}
