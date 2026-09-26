<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Core\Security\EncryptionService;

/**
 * The social module's table as SQLite, mirroring modules/social/schema.sql,
 * and a transport that plays Meta's part.
 */
final class SocialTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE social_connections (
            platform TEXT NOT NULL PRIMARY KEY,
            app_id TEXT NOT NULL DEFAULT \'\',
            account_id TEXT NULL,
            account_name TEXT NULL,
            secrets BLOB NULL,
            connected_at TEXT NULL,
            token_refreshed_at TEXT NULL,
            token_expires_at TEXT NULL,
            checked_at TEXT NULL,
            check_ok INTEGER NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE social_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            file_name TEXT NOT NULL,
            blurred INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            served_count INTEGER NOT NULL DEFAULT 0
        )');
    }

    /** A real photo of people, from the reference dataset. */
    public static function groupPhoto(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/reference-dataset/photos/unite_group_002.jpg');
    }

    /**
     * How much detail the top of a card keeps — the mean luminance step
     * between horizontal neighbours, over the band above the text veil.
     * A sharp photo scores several times what a blurred one does.
     */
    public static function sharpness(string $jpeg): float
    {
        $image = imagecreatefromstring($jpeg);
        if ($image === false) {
            throw new \RuntimeException('not an image');
        }
        $sum = 0.0;
        $count = 0;
        for ($y = 20; $y < 360; $y += 4) {
            $previous = null;
            for ($x = 0; $x < imagesx($image); $x += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $luma = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($previous !== null) {
                    $sum += abs($luma - $previous);
                    $count++;
                }
                $previous = $luma;
            }
        }

        return $count === 0 ? 0.0 : $sum / $count;
    }

    public static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    /**
     * Answers each request with the first scripted answer whose URL
     * fragment it contains, and records every request.
     *
     * @param array<string, array{status: int, body: string}|null> $answers URL fragment => answer
     */
    public static function transport(array $answers): FakeMetaTransport
    {
        return new FakeMetaTransport($answers);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: string}
     */
    public static function ok(array $body): array
    {
        return ['status' => 200, 'body' => (string) json_encode($body)];
    }
}
