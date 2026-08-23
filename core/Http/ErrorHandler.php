<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

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
 */
final class ErrorHandler
{
    private static bool $debug = false;
    private static bool $registered = false;

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
        error_log(
            'Uncaught ' . $e::class . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString()
        );

        self::emit500($e);
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
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        $detail = '';
        // Deliberately outside the Core\Exception\UserFacingMessage policy: printing the class, message and stack trace verbatim is this block's entire purpose, and it is gated on self::$debug.
        if (self::$debug && $e !== null) {
            $detail = '<pre style="text-align:left;white-space:pre-wrap;overflow:auto;background:rgba(127,127,127,.12);padding:1rem;border-radius:.5rem;">'
                . htmlspecialchars($e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES)
                . '</pre>';
        }

        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Erreur serveur</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1.5rem;text-align:center;color-scheme:light dark;}h1{font-size:1.25rem;}p{opacity:.8;}</style>'
            . '</head><body>'
            . '<h1>Une erreur est survenue</h1>'
            . '<p>Le site a rencontré un problème inattendu. Réessayez dans un instant ; '
            . 'si le problème persiste, contactez un(e) responsable de l\'unité.</p>'
            . $detail
            . '</body></html>';
    }
}
