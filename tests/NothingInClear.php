<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Assert;

/**
 * « This fragment is stored nowhere in the clear » — asked value by value,
 * never of a `json_encode()` of the whole thing.
 *
 * The encoding is the trap, and it is issue #533. `json_encode` writes
 * every non-ASCII byte as `\uXXXX`, so the four hex digits of an escape
 * spell a four-character needle that the data does not contain. A
 * ciphertext is random bytes, so it produces such escapes constantly:
 *
 *     bin2hex("Ѹ")        → d1b8, contains "0478": no
 *     json_encode(["Ѹ"])  → ["Ѹ"], contains "0478": YES
 *
 * A needle is at risk exactly when an escape could spell it — when it
 * matches `u?[0-9a-f]{1,4}`, because the `\` and the `u` of the NEXT
 * escape break anything longer. « 0478 » (a phone fragment) and « aaaa »
 * (an installation identifier) are both that shape; « Marie » is not.
 * `Tests\Architecture\TestsDoNotFailForReasonsOfTheirOwnTest` refuses a
 * new one rather than trusting anybody to remember this.
 *
 * Searching per value is not merely steadier, it is STRICTER: nothing is
 * escaped, so a fragment cannot hide behind an encoding either, and the
 * failure names where it was found.
 *
 * One shared reader rather than a copy per test, for the reason issue #535
 * gives: the two copies of a temporary-directory watch were fixed one at a
 * time, months apart.
 */
final class NothingInClear
{
    /**
     * **A list of pairs, not a path => value map**, and the difference is a
     * false pass this class used to give.
     *
     * Two different leaves can produce the same path — `['a.b' => 'safe']`
     * beside `['a' => ['b' => 'secret']]` both flatten to `a.b` — and a map
     * keeps one of them. A reviewer found it: `assertAbsent('secret')`
     * passed because the leaf carrying the secret had been dropped on the
     * way in. In a class whose whole job is to say « this is not stored in
     * clear anywhere », losing a value silently is the one failure that
     * must not be possible.
     *
     * @param list<array{path: string, value: string}> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Every column of every row of the given tables, keyed `table.column`.
     */
    public static function inTables(\PDO $pdo, string ...$tables): self
    {
        $values = [];
        foreach ($tables as $table) {
            // A table is an identifier, and PDO binds values, never
            // identifiers — so the name goes into the statement as text or
            // not at all. What CAN be done is refuse anything that is not
            // an identifier, which is what this does: every caller passes a
            // literal from test source, and the day one passes something
            // built at runtime, it stops here rather than in the database.
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
                throw new \InvalidArgumentException("« {$table} » is not a table name.");
            }

            $statement = $pdo->query('SELECT * FROM ' . $table);
            if ($statement === false) {
                throw new \RuntimeException("« {$table} » could not be read.");
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $index => $row) {
                foreach ($row as $column => $value) {
                    $values[] = [
                        'path' => $table . '.' . $column . '[' . $index . ']',
                        'value' => (string) $value,
                    ];
                }
            }
        }

        return new self($values);
    }

    /**
     * Every scalar of a nested structure, keyed by where it sits.
     *
     * @param mixed $structure
     */
    public static function inValuesOf($structure): self
    {
        return new self(self::flatten($structure, ''));
    }

    /**
     * @return list<array{path: string, value: string}>
     */
    private static function flatten($structure, string $prefix): array
    {
        if (is_object($structure)) {
            $structure = get_object_vars($structure);
        }
        if (!is_array($structure)) {
            return is_scalar($structure)
                ? [['path' => $prefix === '' ? 'value' : $prefix, 'value' => (string) $structure]]
                : [];
        }

        $flat = [];
        foreach ($structure as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            // Appended, never merged on the path: two leaves can spell the
            // same one, and a merge keeps only one of them — see the
            // constructor.
            foreach (self::flatten($value, $path) as $leaf) {
                $flat[] = $leaf;
            }
        }

        return $flat;
    }

    /**
     * Fails naming the needle AND the place it was found in.
     *
     * Reading nothing back is itself a failure: an empty structure would
     * otherwise satisfy every absence asked of it.
     */
    public function assertAbsent(string ...$needles): void
    {
        Assert::assertNotSame([], $this->values, 'nothing was read back, so no absence below is tested');

        foreach ($needles as $needle) {
            foreach ($this->values as ['path' => $path, 'value' => $value]) {
                Assert::assertStringNotContainsString($needle, $value, "« {$needle} » is stored in clear in {$path}.");
            }
        }
    }

    /**
     * For the case where NOTHING is expected to remain — a purge that
     * emptied its table, a delete that removed the only record.
     *
     * Its own method rather than a tolerated case of {@see assertAbsent()},
     * because the two claims are not the same strength: in an empty store
     * every absence is true for free, so a test that means « the store is
     * empty » must say so, and one that means « the store still holds
     * things, none of them this » must not be satisfied by an accident
     * that emptied it.
     */
    public function assertEverythingIsGone(string $message = ''): void
    {
        Assert::assertSame(
            [],
            $this->values,
            $message !== '' ? $message : 'something survived that was supposed to be gone'
        );
    }

    /**
     * The counterpart: a value that is DELIBERATELY readable, named by the
     * place it is expected in, so the assertion says where it looked.
     */
    public function assertReadableIn(string $path, string $needle): void
    {
        $matching = array_values(array_map(
            static fn (array $pair): string => $pair['value'],
            array_filter(
                $this->values,
                static fn (array $pair): bool => $pair['path'] === $path
                    || str_starts_with($pair['path'], $path . '[')
            )
        ));

        Assert::assertNotSame([], $matching, "nothing sits at {$path}, so « {$needle} » cannot be read from it");
        Assert::assertContains($needle, $matching, "« {$needle} » is no longer readable at {$path}.");
    }
}
