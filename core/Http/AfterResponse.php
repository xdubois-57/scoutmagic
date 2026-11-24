<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * Work a request starts and the visitor does not wait for (issue #1267).
 *
 * The case it exists for: a public form that sends an e-mail only when the
 * address has an account (the sign-in link, the « mot de passe oublié » link).
 * Sent during the request, the SMTP connection makes an address that has an
 * account answer measurably later than one that has none, and anybody able to
 * type an address can read that difference. The page says the same thing
 * either way; the clock must not say otherwise.
 *
 * **Two ways to run, chosen by what the host can do.**
 *
 * - **The answer can be finished first** (`fastcgi_finish_request()`, PHP-FPM):
 *   the work is kept, and `flush()` — called once by `public/index.php`, after
 *   the response is sent — closes the connection and then runs it. The request
 *   itself did no work that depends on the account, so every address answers in
 *   the same time, and the e-mail leaves as fast as before.
 * - **It cannot** (CGI, mod_php): the work runs inside the request, as it
 *   always did, and then the request waits a random 500 ms to 2 s, **whether or
 *   not there was any work**. That does not hide the difference — an attacker
 *   averaging many answers still sees it — but it drowns one answer in noise,
 *   and the per-address and per-IP limits of the two forms bound how many
 *   answers one source can average. It is documented as what it is
 *   (SECURITY.md § 2), not as a guarantee.
 *
 * What a piece of work does must never reach the visitor, so `flush()` and
 * `run()` swallow what it throws: the work journals its own failures, and a
 * throwable that escaped it has nowhere to go but the PHP error log.
 *
 * Built once in `public/index.php` and handed to the services that need it. A
 * service with none runs its work at once and without any wait, which is what a
 * test, the CLI and the scheduler want.
 */
final class AfterResponse
{
    public const JITTER_MIN_MILLISECONDS = 500;
    public const JITTER_MAX_MILLISECONDS = 2000;

    /** @var list<\Closure(): void> */
    private array $pending = [];

    private ?\Closure $finishRequest;
    private \Closure $sleepMicroseconds;
    private \Closure $randomMilliseconds;

    /**
     * Every collaborator is a seam for the tests; production passes none.
     *
     * @param \Closure(): mixed|null $finishRequest What ends the answer to the
     *        browser while the script goes on. Null reads the host: it is
     *        fastcgi_finish_request() where PHP has it, and there is none
     *        elsewhere.
     * @param \Closure(int): void|null $sleepMicroseconds
     * @param \Closure(int, int): int|null $randomMilliseconds A number between
     *        the two bounds, inclusive.
     * @param bool $detectHost False pins « no early finish » when none is given,
     *        whatever the host can do — how a test builds the inline path on a
     *        host that has fastcgi_finish_request().
     */
    public function __construct(
        ?\Closure $finishRequest = null,
        ?\Closure $sleepMicroseconds = null,
        ?\Closure $randomMilliseconds = null,
        bool $detectHost = true
    ) {
        $this->finishRequest = $finishRequest ?? ($detectHost && function_exists('fastcgi_finish_request')
            ? static function (): void {
                fastcgi_finish_request();
            }
            : null);
        $this->sleepMicroseconds = $sleepMicroseconds ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->randomMilliseconds = $randomMilliseconds
            ?? static fn(int $min, int $max): int => random_int($min, $max);
    }

    /** Whether the answer can reach the browser before this work runs. */
    public function canFinishEarly(): bool
    {
        return $this->finishRequest !== null;
    }

    /**
     * Hand over what the request would otherwise do before answering.
     *
     * `null` says « there is nothing to do for this one » — the address has no
     * account — and is still a call, because without early finish the wait that
     * follows must not depend on whether there was work.
     *
     * @param \Closure(): void|null $work
     */
    public function run(?\Closure $work): void
    {
        if ($this->finishRequest !== null) {
            if ($work !== null) {
                $this->pending[] = $work;
            }

            return;
        }

        if ($work !== null) {
            $this->attempt($work);
        }

        ($this->sleepMicroseconds)(
            ($this->randomMilliseconds)(self::JITTER_MIN_MILLISECONDS, self::JITTER_MAX_MILLISECONDS) * 1000
        );
    }

    /**
     * Finish the answer, then run what was kept. Called once, after the
     * response has been sent; a call with nothing kept does nothing at all.
     */
    public function flush(): void
    {
        if ($this->pending === [] || $this->finishRequest === null) {
            return;
        }

        $work = $this->pending;
        $this->pending = [];

        try {
            ($this->finishRequest)();
        } catch (\Throwable) {
            // The answer is already on its way: what is left is still to do.
        }

        foreach ($work as $piece) {
            $this->attempt($piece);
        }
    }

    /** @param \Closure(): void $work */
    private function attempt(\Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable) {
            // The work journals its own failures; see the class comment.
        }
    }
}
