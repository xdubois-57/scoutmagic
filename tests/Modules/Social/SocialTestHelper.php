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
