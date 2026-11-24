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

    protected function setUp(): void
    {
        $this->events = [];
        $this->sleeps = [];
        $this->randomCalls = [];
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
            false
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

    public function testAFlushWithNothingKeptDoesNotEvenFinishTheRequest(): void
    {
        $after = $this->finishingEarly();

        $after->run(null);
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

    public function testWithoutEarlyFinishTheWorkRunsAtOnceThenTheRequestWaitsARandomTime(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(function (): void {
            $this->events[] = 'work';
        });

        $this->assertSame(['work'], $this->events);
        $this->assertSame([750 * 1000], $this->sleeps);
        $this->assertSame([[500, 2000]], $this->randomCalls);
    }

    public function testWithoutEarlyFinishAnAddressWithNoWorkWaitsJustTheSame(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(null);

        $this->assertSame([], $this->events);
        $this->assertSame([750 * 1000], $this->sleeps, 'the wait does not depend on there being work');
    }

    public function testWithoutEarlyFinishWorkThatThrowsStillGetsItsWait(): void
    {
        $after = $this->withoutEarlyFinish();

        $after->run(function (): void {
            throw new \RuntimeException('mail down');
        });

        $this->assertSame([750 * 1000], $this->sleeps);
    }

    public function testTheWaitIsBetweenHalfASecondAndTwoSeconds(): void
    {
        $this->assertSame(500, AfterResponse::JITTER_MIN_MILLISECONDS);
        $this->assertSame(2000, AfterResponse::JITTER_MAX_MILLISECONDS);
    }
}
