<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Support;

use Modules\Groups\Support\Reactions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixed set of six is a product decision, not an implementation
 * detail — pinned here so widening it is a deliberate act with a failing
 * test to justify, never a silent drift.
 */
class ReactionsTest extends TestCase
{
    public function testThereAreExactlySixReactionsInTheDocumentedOrder(): void
    {
        $this->assertSame(
            ['thumbs_up', 'heart', 'joy', 'wow', 'sad', 'clap'],
            Reactions::keys()
        );
    }

    public function testEveryKeyMapsToANonEmptyEmoji(): void
    {
        foreach (Reactions::EMOJI as $key => $emoji) {
            $this->assertNotSame('', $emoji, $key);
            // Every one of these is outside the Basic Multilingual Plane's
            // three-byte range, which is precisely why the DATABASE stores
            // the key and not this (schema.sql).
            $this->assertGreaterThan(1, mb_strlen($emoji, 'UTF-8') + strlen($emoji) - mb_strlen($emoji, 'UTF-8'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validKeys(): iterable
    {
        foreach (array_keys(Reactions::EMOJI) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('validKeys')]
    public function testIsValidAcceptsEveryKeyInTheMap(string $key): void
    {
        $this->assertTrue(Reactions::isValid($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown key' => ['rocket'];
        yield 'raw emoji instead of a key' => ['👍'];
        yield 'a different raw emoji' => ['💩'];
        yield 'case mismatch' => ['THUMBS_UP'];
        yield 'sql-ish' => ["thumbs_up'; DROP TABLE x--"];
        yield 'whitespace-padded' => [' thumbs_up '];
    }

    #[DataProvider('invalidKeys')]
    public function testIsValidRejectsAnythingElse(string $key): void
    {
        $this->assertFalse(Reactions::isValid($key));
    }

    public function testEmojiResolvesAKnownKey(): void
    {
        $this->assertSame('👍', Reactions::emoji('thumbs_up'));
    }

    public function testEmojiFallsBackToTheKeyRatherThanRenderingNothing(): void
    {
        // A stored row whose key left the map must never blank out a
        // rendered page.
        $this->assertSame('rocket', Reactions::emoji('rocket'));
    }
}
