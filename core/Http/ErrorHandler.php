<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Journal\JournalService;

/**
 * Last-resort handler for uncaught throwables and fatal errors.
 *
 * This app targets shared/FTP hosting whose php.ini the operator cannot
 * edit (see public/.user.ini), where display_errors=On is common. Without
 * this, any uncaught exception — most importantly a PDOException from the
 * database connect, whose stack trace frames carry the DSN and the DB
 * password as arguments, and which surfaces before any controller runs —
 * would be printed straight into the response body. This forces
 * display_errors off in production, logs the real detail server-side, and
 * returns a fixed, dependency-free 500 page instead.
 *
 * Deliberately self-contained: it renders a hardcoded HTML string and never
 * touches Twig, the database, the session, or the container, because any of
 * those may be exactly what failed. It is registered as the very first thing
 * public/index.php and public/cron.php do, before the config file is even
 * read (loading it can itself throw), then re-armed with the real debug flag
 * once configuration is available.
 *
 * ONE deliberate exception to that self-containment: once the database is
 * up, the composition root hands this class a JournalService
 * (setJournalService()) and every uncaught throwable is ALSO written to
 * the site journal, at level `error`, so it is consultable from
 * /admin/journal instead of only from a file. That file is the reason:
 * on shared hosting the operator does not choose where error_log() writes
 * — one real incident put the whole trace in
 * /var/www/.../log/error.log, invisible from the site, and it took
 * generating a support package to find it.
 *
 * The order and the guarantees are not negotiable:
 *   - error_log() stays the source of truth and runs FIRST, always;
 *   - the journal write is a SECONDARY attempt, wrapped in a catch-all
 *     that swallows everything — it may never turn one error into two;
 *   - CONSEQUENCE, accepted and stated: an error raised before the
 *     database is available (a PDOException at connect time, a fault in
 *     the composition root before setJournalService() is reached) will
 *     never appear in the site journal. There is nothing to write to. The
 *     support package (Core\Support, ARCHITECTURE.md §8.48), which ships
 *     the PHP error log itself, stays the recourse for those.
 *
 * The stack goes in the journal entry's JSON context, one line per frame,
 * and NEVER via getTraceAsString(): that spelling includes each frame's
 * call ARGUMENTS, and the trace of the incident above carried a member's
 * email address through them. sanitizeTrace() keeps class, method, file
 * and line, and nothing else (AGENTS.md § Security checklist, point 4).
 */
final class ErrorHandler
{
    /** Journal category every entry written here belongs to. */
    public const JOURNAL_CATEGORY = 'core';

    /** Journal event type of an uncaught throwable. */
    public const JOURNAL_EVENT_TYPE = 'uncaught_error';

    /** Journal level of an uncaught throwable (event_log.level). */
    public const JOURNAL_LEVEL = 'error';

    /**
     * Hard ceiling of event_log.description — a VARCHAR(500). A longer
     * description would be REFUSED by the database in strict mode, i.e.
     * the journal write would fail exactly on the errors with the most to
     * say, so the description is cut here rather than at the column.
     */
    public const DESCRIPTION_MAX_LENGTH = 500;

    /**
     * Frames kept in the JSON context. A runaway recursion produces a
     * trace thousands of frames deep; the first few dozen are what says
     * where it went wrong, and the rest would only bloat every row of the
     * journal page.
     */
    public const TRACE_MAX_FRAMES = 50;

    /**
     * The Content-Security-Policy of the fixed 500 page.
     *
     * A constant rather than a literal inside emit500() so a test can read
     * it: headers_list() is empty under the CLI SAPI, so the only way to
     * assert on this header from PHPUnit is to assert on the value the
     * handler sends. See emit500() for why this policy is parallel to
     * Core\Http\Response::buildCsp() rather than equal to it, and which
     * half of it must never drift.
     */
    public const CSP = "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    /**
     * The CSP directives that do NOT fall back to `default-src`: absent,
     * they are simply not enforced, whatever `default-src` says. They are
     * the reason self::CSP above spells anything out at all, and the list
     * OWASP ZAP rule 10055 checks.
     *
     * @var array<int, string>
     */
    public const NO_FALLBACK_CSP_DIRECTIVES = ['frame-ancestors', 'base-uri', 'form-action'];

    private static bool $debug = false;
    private static bool $registered = false;

    /**
     * The site journal, once the database is up — null before that, and
     * null for ever on an installation whose database never came up. See
     * the class docblock: null is a supported, documented state here, not
     * a wiring mistake.
     */
    private static ?JournalService $journal = null;

    /**
     * Arm the handler. Safe to call twice: the first call (before config
     * exists) installs the production-safe defaults; the second, once
     * AppConfig::isDebug() is known, only adjusts the debug flag.
     */
    public static function register(bool $debug): void
    {
        self::$debug = $debug;

        // Never leak internals to the browser in production; always keep a
        // server-side log. In debug, let PHP surface detail as usual.
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_exception_handler([self::class, 'handleThrowable']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Give the handler somewhere to write, once the database exists.
     *
     * Called by the composition roots (public/index.php, public/cron.php)
     * at the earliest point a PDO connection is open — the same
     * set-it-afterwards shape as SessionRevalidator::setJournalService(),
     * and for the same reason: this class is constructed by nobody and
     * must keep working with nothing wired. Passing null disarms the
     * journal write again, which is what the tests do between cases.
     */
    public static function setJournalService(?JournalService $journal): void
    {
        self::$journal = $journal;
    }

    /**
     * Run $callback, turning any throwable it raises into the generic 500
     * response instead of letting it bubble to PHP's default handler. Used
     * to wrap the front controller dispatch so a controller-level fault is
     * caught even though set_exception_handler already covers the rest.
     */
    public static function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::handleThrowable($e);
            exit;
        }
    }

    public static function handleThrowable(\Throwable $e): void
    {
        // FIRST, and unconditionally: the destination that needs nothing
        // else to be working. getTraceAsString() with its arguments is
        // admissible here and only here — this goes to a file the server
        // operator owns, never to a page or a database row.
        error_log(
            'Uncaught ' . $e::class . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString()
        );

        self::writeToJournal($e);

        self::emit500($e);
    }

    /**
     * The secondary attempt: make the error consultable from the site.
     *
     * Everything is caught, including an Error: the database may be
     * exactly what failed, the event_log table may not exist yet on a
     * half-installed site, and the journal write must never be the reason
     * a 500 page becomes a blank page. error_log() has already run, so
     * nothing is lost by giving up here — silently and on purpose.
     */
    private static function writeToJournal(\Throwable $e): void
    {
        if (self::$journal === null) {
            return;
        }

        try {
            self::$journal->log(
                self::JOURNAL_CATEGORY,
                self::JOURNAL_EVENT_TYPE,
                self::JOURNAL_LEVEL,
                self::describe($e),
                [
                    'exception' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => self::sanitizeTrace($e),
                ],
                // No acting user: resolving one means reading the session,
                // which is one of the things this class refuses to touch.
                null
            );
        } catch (\Throwable) {
            // Swallowed deliberately. See the class docblock.
        }
    }

    /**
     * The journal description of a throwable: class, message, file and
     * line, within event_log.description's 500 characters.
     *
     * The MESSAGE is what gets cut, never the location: a truncation that
     * ate "in /path/File.php:120" would leave an entry saying something
     * broke somewhere. Newlines are collapsed because this is rendered as
     * one cell of a table.
     *
     * The message itself is technical text this application wrote, which
     * is why it belongs in a journal that forbids personal data
     * (SECURITY.md §11) — the leak that actually happened was in the
     * ARGUMENTS, and those are what sanitizeTrace() removes.
     */
    public static function describe(\Throwable $e): string
    {
        $prefix = 'Erreur non interceptée : ' . $e::class
            . ' dans ' . $e->getFile() . ':' . $e->getLine();

        $message = trim((string) preg_replace('/\s+/u', ' ', $e->getMessage()));
        if ($message === '') {
            return self::truncate($prefix, self::DESCRIPTION_MAX_LENGTH);
        }

        $separator = ' — ';
        $budget = self::DESCRIPTION_MAX_LENGTH - mb_strlen($prefix) - mb_strlen($separator);

        if ($budget < 1) {
            // A pathologically long class name or file path: the location
            // alone already fills the column.
            return self::truncate($prefix, self::DESCRIPTION_MAX_LENGTH);
        }

        return $prefix . $separator . self::truncate($message, $budget);
    }

    /**
     * The stack, one readable line per frame, with the call ARGUMENTS
     * removed — the whole reason this exists rather than a call to
     * getTraceAsString(). PHP puts every argument of every frame in that
     * string, so a single mis-typed email address on a login form ends up
     * in a table any chef d'unité can read (AGENTS.md § Security
     * checklist, point 4).
     *
     * @return array<int, string>
     */
    public static function sanitizeTrace(\Throwable $e): array
    {
        $frames = [];

        foreach ($e->getTrace() as $frame) {
            if (count($frames) >= self::TRACE_MAX_FRAMES) {
                $frames[] = '… (trace tronquée)';
                break;
            }

            // Never $frame['args'] — that is the whole point.
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];

            // A frame with no file is an internal call (a callback invoked
            // by call_user_func(), a shutdown function): it has a name, and
            // that is all it has.
            $where = isset($frame['file'])
                ? $frame['file'] . ':' . ($frame['line'] ?? 0)
                : '[internal]';

            $frames[] = $call . '() ' . $where;
        }

        return $frames;
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 1) . '…';
    }

    /**
     * A fatal error (E_ERROR / E_PARSE / E_COMPILE_ERROR / …) is not a
     * throwable and never reaches set_exception_handler — it only surfaces
     * here. PHP has already printed nothing yet if display_errors is off,
     * so this is where the generic page has to come from.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (($error['type'] & $fatal) === 0) {
            return;
        }

        // error_log() only, no journal write: a fatal error is most often
        // an exhausted memory limit or an exhausted execution time, and
        // the one thing a process in that state must not do is go and ask
        // the database for more of either. Uncaught THROWABLES, which is
        // what an application fault looks like in practice, are the ones
        // this iteration makes consultable from the site.
        error_log(
            'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        );

        self::emit500(null);
    }

    private static function emit500(?\Throwable $e): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        // Even the failure page carries the baseline security headers — a
        // 500 must not become the one response an attacker can frame or
        // MIME-sniff. Kept in sync with Core\Http\Response::getSecurityHeaders().
        //
        // This policy is deliberately parallel to Response::buildCsp()
        // rather than a copy of it: this page loads no script, no
        // stylesheet, no image and no font of its own, so everything that
        // grants a source is unwanted here — `default-src 'self'` and
        // nothing else is the strongest thing to say. What it MUST keep in
        // step with is the other list: the three directives that do NOT
        // fall back to `default-src` and therefore have to be spelled out
        // or they are simply absent — `frame-ancestors`, `base-uri` and
        // `form-action`. `form-action` was missing here for as long as
        // this handler has existed, and nothing noticed because no test
        // had ever made a scanner visit an error page: the first
        // end-to-end scenario to provoke a 500 on purpose
        // (specs/journal-uncaught-error.spec.js) is what surfaced it, as
        // OWASP ZAP rule 10055, "CSP: Failure to Define Directive with No
        // Fallback", Medium, blocking the dynamic security gate. Add a
        // non-fallback directive to Response::buildCsp() and it belongs
        // here too.
        //
        // `object-src 'none'` is deliberately NOT here: it falls back to
        // `default-src 'self'`, so it is not part of that list, and
        // Response::buildCsp() does not set it either. A directive on one
        // side and not the other is the exact divergence this comment
        // exists to prevent.
        header('Content-Security-Policy: ' . self::CSP);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        $detail = '';
        // Deliberately outside the Core\Exception\UserFacingMessage policy: printing the class, message and stack trace
        // verbatim is this block's entire purpose, and it is gated on self::$debug.
        if (self::$debug && $e !== null) {
            $detail = '<pre '
                . 'style="text-align:left;white-space:pre-wrap;overflow:auto;'
                . 'background:rgba(127,127,127,.12);padding:1rem;border-radius:.5rem;">'
                . htmlspecialchars($e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES)
                . '</pre>';
        }

        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Erreur serveur</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:40rem;margin:4rem auto;padding:0 '
            . '1.5rem;text-align:center;color-scheme:light dark;}h1{font-size:1.25rem;}p{opacity:.8;}</style>'
            . '</head><body>'
            . '<h1>Une erreur est survenue</h1>'
            . '<p>Le site a rencontré un problème inattendu. Réessayez dans un instant ; '
            . 'si le problème persiste, contactez un(e) responsable de l\'unité.</p>'
            . $detail
            . '</body></html>';
    }
}
