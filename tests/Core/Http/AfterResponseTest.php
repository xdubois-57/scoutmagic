<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\AfterResponse;
use PHPUnit\Framework\TestCase;

/**
 * The visitor must not be able to tell, from the clock, whether an e-mail was
 * sent (issue #1267).
 */
class AfterResponseTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @var list<int> */
    private array $sleeps = [];

    /** @var list<array{int, int}> */
    private array $randomCalls = [];

    /** The fake clock, in seconds: what the work spends is added to it. */
    private float $now = 100.0;

    protected function setUp(): void
    {
        $this->events = [];
        $this->sleeps = [];
        $this->randomCalls = [];
        $this->now = 100.0;
    }

    private function finishingEarly(): AfterResponse
    {
        return new AfterResponse(
            function (): void {
                $this->events[] = 'finish';
            },
            function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
            function (int $min, int $max): int {
                $this->randomCalls[] = [$min, $max];

                return 1234;
            }
        );
    }

    private function runningInline(): AfterResponse
    {
        return new AfterResponse(
            null,
            function (int $microseconds): void {
                $this->sleeps[] = $microseconds;
            },
            function (int $min, int $max): int {
                $this->randomCalls[] = [$min, $max];

                return 750;
            },
            false,
            null,
            fn(): float => $this->now
        );
    }

    /** An instance with no early finish, whatever the host this test runs on. */
    private function withoutEarlyFinish(): AfterResponse
    {
        return $this->runningInline();
    }

    public function testWorkWaitsForTheFlushWhenTheAnswerCanBeFinishedFirst(): void
    {
        $after = $this->finishingEarly();

        $after->run(function (): void {
            $this->events[] = 'work';
        });
        $this->assertSame([], $this->events, 'nothing runs before the answer');

        $after->flush();

        $this->assertSame(['finish', 'work'], $this->events, 'the answer ends first, then the work runs');
    }

    public function testNoWaitAndNoWorkIsAddedWhenTheAnswerIsFinishedFirst(): void
    {
        $after = $this->finishingEarly();

        $after->run(function (): void {
        });
        $after->run(null);

        $this->assertSame([], $this->sleeps);
        $this->assertSame([], $this->randomCalls);
    }

    /**
     * An address with no account hands over nothing. Its answer must still be
     * closed before the rest of the request's tail runs, or it is the slower
     * one on the requests that trigger that tail.
     */
    public function testAFlushWithNothingKeptStillFinishesTheRequest(): void
    {
        $after = $this->finishingEarly();

        $after->run(null);
        $after->flush();

        $this->assertSame(['finish'], $this->events);
    }

    public function testWithoutEarlyFinishAFlushDoesNothing(): void
    {
        $after = $this->runningInline();

        $after->flush();

        $this->assertSame([], $this->events);
    }

    public function testEveryPieceOfWorkRunsInTheOrderItWasHandedOver(): void
    {
        $after = $this->finishingEarly();

        foreach (['a', 'b', 'c'] as $name) {
            $after->run(function () use ($name): void {
                $this->events[] = $name;
            });
        }
        $after->flush();

        $this->assertSame(['finish', 'a', 'b', 'c'], $this->events);
    }

    public function testAFlushRunsTheWorkOnlyOnce(): void
    {
        $after = $this->finishingEarly();
        $after->run(function (): void {
            $this->events[] = 'work';
        });

        $after->flush();
        $after->flush();

        $this->assertSame(['finish', 'work'], $this->events);
    }

    public function testWorkThatThrowsNeverReachesTheCallerAndDoesNotStopTheNext(): void
    {
        $after = $this->finishingEarly();
        $after->run(function (): void {
            throw new \RuntimeException('mail down');
        });
        $after->run(function (): void {
            $this->events[] = 'second';
        });

        $after->flush();

        $this->assertSame(['finish', 'second'], $this->events);
    }

    /** What escapes the work is written down — as a class and a fixed sentence, never the message. */
    public function testWhatEscapesTheWorkIsReportedWithoutItsMessage(): void
    {
        $reports = [];
        $after = new AfterResponse(
            static function (): void {
            },
            static function (int $microseconds): void {
            },
            static fn(int $min, int $max): int => $min,
            true,
            static function (string $line) use (&$reports): void {
                $reports[] = $line;
            }
        );
        $after->run(function (): void {
            throw new \RuntimeException('SMTP refused jean@example.org');
        });

        $after->flush();

        $this->assertCount(1, $reports);
        $this->assertStringContainsString('RuntimeException', $reports[0]);
        $this->assertStringNotContainsString('jean@example.org', $reports[0]);
        $this->assertStringNotContainsString('SMTP', $reports[0]);
    }

    public function testAFinishThatFailsDoesNotCostTheWork(): void
    {
        $after = new AfterResponse(
            static function (): void {
                throw new \RuntimeException('connection gone');
            },
            static function (int $microseconds): void {
            },
            static fn(int $min, int $max): int => $min
        );
        $ran = false;
        $after->run(function () use (&$ran): void {
            $ran = true;
        });

        $after->flush();

        $this->assertTrue($ran);
    }

    public function testItCanFinishEarlyExactlyWhenTheHostHasAWayToDoIt(): void
    {
        $this->assertTrue($this->finishingEarly()->canFinishEarly());
        $this->assertSame(function_exists('fastcgi_finish_request'), (new AfterResponse())->canFinishEarly());
    }

    public function testWithoutEarlyFinishTheWorkRunsAtOnceThenTheRequestWaitsForTheDeadline(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(function (): void {
            $this->events[] = 'work';
        });

        $this->assertSame(['work'], $this->events);
        $this->assertSame([750 * 1000], $this->sleeps, 'work that took no time leaves the whole deadline');
        $this->assertSame([[500, 2000]], $this->randomCalls);
    }

    public function testWithoutEarlyFinishAnAddressWithNoWorkWaitsJustTheSame(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(null);

        $this->assertSame([], $this->events);
        $this->assertSame([750 * 1000], $this->sleeps, 'the wait does not depend on there being work');
    }

    /**
     * The wait is what the work left of the deadline, not a sleep stacked on
     * top of it (issue #1267): an address whose send took 300 ms and one with
     * nothing to send both answer at the deadline.
     */
    public function testTheWaitIsWhatTheWorkLeftOfTheDeadline(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(function (): void {
            $this->now += 0.3;
        });

        $this->assertSame([450 * 1000], $this->sleeps, '750 ms deadline, 300 ms spent sending');
    }

    public function testWorkThatTakesLongerThanTheDeadlineIsNotWaitedForAgain(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(function (): void {
            $this->now += 1.2;
        });

        $this->assertSame([], $this->sleeps, 'the deadline already passed: no sleep on top of the work');
    }

    public function testWithoutEarlyFinishWorkThatThrowsStillGetsItsWait(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(function (): void {
            $this->now += 0.1;
            throw new \RuntimeException('mail down');
        });

        $this->assertSame([650 * 1000], $this->sleeps);
    }

    public function testTheWaitIsBetweenHalfASecondAndTwoSeconds(): void
    {
        $this->assertSame(500, AfterResponse::JITTER_MIN_MILLISECONDS);
        $this->assertSame(2000, AfterResponse::JITTER_MAX_MILLISECONDS);
    }
}
