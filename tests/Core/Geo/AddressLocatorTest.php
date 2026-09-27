<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\AddressLocator;
use Core\Geo\GeocodingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The carpool map's live address lookup (issue #642): what reaches
 * Nominatim, how often, and what a refusal looks like to the caller.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AddressLocatorTest extends TestCase
{
    private \PDO $pdo;

    /** @var list<string> the lines that actually left for Nominatim */
    private array $sent = [];

    /** @var list<int> every pause the locator asked for, in microseconds */
    private array $pauses = [];

    /** @var array{latitude: float, longitude: float}|null */
    private ?array $answer = ['latitude' => 50.1234, 'longitude' => 4.5678];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
    }

    public function testAFoundAddressIsReturnedAndOnlyItsFingerprintIsKept(): void
    {
        $point = $this->locator()->locate('  Gîte de Han,   rue des Grottes 12 ', 7);

        $this->assertNotNull($point);
        $this->assertSame(50.1234, $point->latitude);
        $this->assertSame(4.5678, $point->longitude);
        $this->assertSame(['Gîte de Han, rue des Grottes 12'], $this->sent, 'sent once, whitespace collapsed');

        $row = $this->pdo->query('SELECT * FROM geocoding_cache')->fetch();
        $this->assertSame(64, strlen((string) $row['fingerprint']));
        $this->assertStringNotContainsString('Grottes', implode(' ', array_map('strval', $row)));
    }

    public function testTheSameAddressAskedAgainIsServedFromTheCache(): void
    {
        $this->locator()->locate('Rue des Grottes 12, Han', 7);
        $again = $this->locator()->locate('rue des grottes 12,  HAN', 8);

        $this->assertNotNull($again);
        $this->assertCount(1, $this->sent, 'the folded address is the same place');
        $this->assertSame(1, $this->rows('geocoding_lookups'), 'a cached answer is not counted');
    }

    public function testNothingFoundIsCachedForHoursNotForMonths(): void
    {
        $this->answer = null;
        $this->assertNull($this->locator()->locate('Le pré de Jules', 7));
        $this->assertNull($this->locator()->locate('Le pré de Jules', 7));
        $this->assertCount(1, $this->sent);

        $this->pdo->exec("UPDATE geocoding_cache SET looked_up_at = '"
            . (new \DateTimeImmutable('-' . (AddressLocator::NOT_FOUND_TTL_HOURS + 1) . ' hours'))->format('Y-m-d H:i:s')
            . "'");
        $this->answer = ['latitude' => 50.5, 'longitude' => 4.5];

        $this->assertNotNull($this->locator()->locate('Le pré de Jules', 7));
        $this->assertCount(2, $this->sent);
    }

    public function testAFoundPointIsServedFromTheCacheForMonths(): void
    {
        $this->locator()->locate('Rue des Grottes 12, Han', 7);
        $this->pdo->exec("UPDATE geocoding_cache SET looked_up_at = '"
            . (new \DateTimeImmutable('-' . (AddressLocator::FOUND_TTL_DAYS - 1) . ' days'))->format('Y-m-d H:i:s')
            . "'");

        $this->assertNotNull($this->locator()->locate('Rue des Grottes 12, Han', 7));
        $this->assertCount(1, $this->sent);
    }

    public function testALineTooShortOrTooLongToMeanAPlaceIsNeverSent(): void
    {
        $this->assertNull($this->locator()->locate('   ab  ', 7));
        $this->assertNull($this->locator()->locate(str_repeat('a', 256), 7));

        $this->assertSame([], $this->sent);
        $this->assertSame(0, $this->rows('geocoding_lookups'));
    }

    public function testAnAccountOverItsQuotaGetsNoAnswerAndSendsNothing(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO geocoding_lookups (user_account_id, created_at) VALUES (?, ?)');
        for ($i = 0; $i < AddressLocator::QUOTA_PER_WINDOW; $i++) {
            $stmt->execute([7, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        }

        $this->assertNull($this->locator()->locate('Rue des Grottes 12, Han', 7));
        $this->assertSame([], $this->sent);

        // Another account is not affected, and old rows no longer count.
        $this->assertNotNull($this->locator()->locate('Rue des Grottes 12, Han', 8));
        $this->pdo->exec("UPDATE geocoding_lookups SET created_at = '"
            . (new \DateTimeImmutable('-' . (AddressLocator::QUOTA_WINDOW_MINUTES + 1) . ' minutes'))->format('Y-m-d H:i:s')
            . "' WHERE user_account_id = 7");
        $this->assertNotNull($this->locator()->locate('Place du Marché 1, Namur', 7));
    }

    public function testTheLockIsHeldForAFullSecondAfterTheCallBegan(): void
    {
        // The clock reads 100.0 when the call begins and 100.25 after it.
        $readings = [100.0, 100.25];
        $locator = $this->locator(static function () use (&$readings): float {
            return array_shift($readings) ?? 100.25;
        });

        $locator->locate('Rue des Grottes 12, Han', 7);

        $this->assertSame([750_000], $this->pauses);
    }

    public function testASlowCallIsNotPausedFurther(): void
    {
        $readings = [100.0, 101.5];
        $locator = $this->locator(static function () use (&$readings): float {
            return array_shift($readings) ?? 101.5;
        });

        $locator->locate('Rue des Grottes 12, Han', 7);

        $this->assertSame([], $this->pauses);
    }

    /** @param (\Closure(): float)|null $clock */
    private function locator(?\Closure $clock = null): AddressLocator
    {
        $test = $this;
        $geocoder = new class ($test) extends GeocodingService {
            public function __construct(private AddressLocatorTest $test)
            {
                parent::__construct('https://unit.test');
            }

            public function geocodeLine(?string $line): ?array
            {
                return $this->test->answer((string) $line);
            }
        };

        return new AddressLocator(
            $this->pdo,
            $geocoder,
            function (int $microseconds): void {
                $this->pauses[] = $microseconds;
            },
            $clock ?? static fn(): float => 100.0
        );
    }

    /**
     * The fake geocoder's answer, recording what was sent.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function answer(string $line): ?array
    {
        $this->sent[] = $line;

        return $this->answer;
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
