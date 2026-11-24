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
 *   always did, and the request is then held until a deadline drawn at random
 *   between 500 ms and 2 s, **whether or not there was any work**. The wait is
 *   what is left of that deadline, never a fixed sleep stacked on top of the
 *   work: stacked, a send of a second and a half would still put the two
 *   answers in different places. Padded, an address with an account and one
 *   without both answer at the deadline for as long as the send fits inside
 *   it. **A send that takes longer than the deadline still shows** — nothing
 *   here can make a slow relay fast — and an attacker averaging many answers
 *   still sees the rest; the per-address and per-IP limits of the two forms
 *   bound how many answers one source can average. It is documented as what
 *   it is (SECURITY.md § 2), not as a guarantee.
 *
 * What a piece of work does must never reach the visitor, so `flush()` and
 * `run()` swallow what it throws: the work journals its own failures, and a
 * throwable that escaped it is written to the PHP error log — its class and a
 * fixed sentence, never its message.
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

    private bool $flushed = false;

    private ?\Closure $finishRequest;
    private \Closure $sleepMicroseconds;
    private \Closure $randomMilliseconds;
    private \Closure $report;
    private \Closure $clock;

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
     * @param \Closure(string): void|null $report Where a failure that escaped the
     *        work is written; null is the PHP error log.
     * @param \Closure(): float|null $clock Seconds, with a fraction, from any fixed
     *        origin — what the padding measures the work against. Null is
     *        microtime(true).
     * @param bool $detectHost False pins « no early finish » when none is given,
     *        whatever the host can do — how a test builds the inline path on a
     *        host that has fastcgi_finish_request().
     */
    public function __construct(
        ?\Closure $finishRequest = null,
        ?\Closure $sleepMicroseconds = null,
        ?\Closure $randomMilliseconds = null,
        bool $detectHost = true,
        ?\Closure $report = null,
        ?\Closure $clock = null
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
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->report = $report ?? static function (string $line): void {
            error_log($line);
        };
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
     * account — and is still a call, because without early finish the answer
     * must come at the same deadline whether or not there was work.
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

        // The deadline is drawn before the work, and the wait is what the work
        // left of it: a send that took 300 ms of a 900 ms deadline waits 600 ms.
        $started = ($this->clock)();
        $deadlineMilliseconds = ($this->randomMilliseconds)(
            self::JITTER_MIN_MILLISECONDS,
            self::JITTER_MAX_MILLISECONDS
        );

        if ($work !== null) {
            $this->attempt($work);
        }

        $elapsedMilliseconds = (int) ceil((($this->clock)() - $started) * 1000);
        $remainingMilliseconds = $deadlineMilliseconds - $elapsedMilliseconds;
        if ($remainingMilliseconds > 0) {
            ($this->sleepMicroseconds)($remainingMilliseconds * 1000);
        }
    }

    /**
     * Finish the answer, then run what was kept. Called once, after the
     * response has been sent. Without early finish it does nothing.
     *
     * **It finishes the answer even when nothing was kept.** An address with no
     * account hands over nothing; if its answer stayed open while the rest of
     * the request's tail runs (the operational checks, the housekeeping), it
     * would be the slower of the two on the requests that trigger them —
     * exactly the difference this class exists to remove.
     */
    public function flush(): void
    {
        if ($this->finishRequest === null || $this->flushed) {
            return;
        }
        $this->flushed = true;

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
        } catch (\Throwable $e) {
            // The work journals its own failures, so what reaches here is a
            // failure of the journal itself, or something nobody expected. It
            // goes to the PHP error log — a fixed sentence and the class,
            // never the message, which can carry an address.
            ($this->report)(self::class . ': work failed (' . $e::class . ')');
        }
    }
}
