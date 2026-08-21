<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Support\SupportPackageFactory;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `collection-status.json` is the one file in the support package built
 * from free text nobody wrote for it — a PDO driver error, a scheduled
 * task's `last_error`, a collector's exception message — and the archive is
 * built to be emailed. Everything here is about what does and does not
 * survive Core\Support\SupportCollectorContext::redact()
 * (ARCHITECTURE.md §8.48, SECURITY.md §12).
 */
class SupportRedactionTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/sm-redaction-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/config', 0700, true);
        mkdir($this->storagePath . '/keys', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['/config/secrets.enc', '/keys/master.key'] as $file) {
            if (is_file($this->storagePath . $file)) {
                unlink($this->storagePath . $file);
            }
        }
        foreach (['/config', '/keys'] as $directory) {
            if (is_dir($this->storagePath . $directory)) {
                rmdir($this->storagePath . $directory);
            }
        }
        if (is_dir($this->storagePath)) {
            rmdir($this->storagePath);
        }
    }

    /**
     * @param array<int, string> $secrets
     */
    private function context(array $secrets = []): SupportCollectorContext
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        return new SupportCollectorContext(
            new \ZipArchive(),
            $connection,
            new SettingService(new SettingRepository($this->pdo)),
            dirname(__DIR__, 3),
            $this->storagePath,
            $secrets
        );
    }

    /**
     * The regression this file was written for.
     *
     * The secrets handed to a collector context are every value of
     * `secrets.enc` — the database and SMTP passwords included — and a
     * password may perfectly well contain a space. redact() used to collapse
     * whitespace *before* substituting, so "hunter  2" in a driver error
     * became "hunter 2", which no longer matched the needle, and the
     * credential rode into an archive destined for email.
     */
    public function testASecretContainingWhitespaceIsStillRedacted(): void
    {
        $secret = "hunter  2\ttab";
        $context = $this->context([$secret]);

        $message = 'SQLSTATE[HY000] [1045] Access denied using password ' . $secret . ' for user root';

        $redacted = $context->redact($message);

        $this->assertStringNotContainsString('hunter', $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }

    public function testAnOrdinarySecretIsRedactedCaseInsensitively(): void
    {
        $context = $this->context(['SuperSecret123']);

        $this->assertSame(
            'password [REDACTED] refused',
            $context->redact('password supersecret123 refused')
        );
    }

    /**
     * Short strings are not treated as secrets: "abc" as a needle would
     * blank out half of every error message in the archive and hide the
     * diagnostic the file exists for.
     */
    public function testTooShortAValueIsNeverUsedAsARedactionNeedle(): void
    {
        $context = $this->context(['abc']);

        $this->assertSame('abcdef failed', $context->redact('abcdef failed'));
    }

    /**
     * A driver error, a log line or a command banner is routinely not valid
     * UTF-8. `preg_replace()` with the `/u` flag returns null on such a
     * subject, and the old `(string)` cast turned that into an empty
     * string — silently discarding the one clue the archive existed to
     * carry, and reporting a failed collector with no reason at all.
     */
    public function testInvalidUtf8DoesNotSilentlyEraseTheWholeReason(): void
    {
        $context = $this->context();

        $redacted = $context->redact("dump_unavailable: \xC3\x28 broken byte sequence");

        $this->assertNotSame('', $redacted);
        $this->assertStringContainsString('dump_unavailable', $redacted);
        $this->assertStringContainsString('broken byte sequence', $redacted);
    }

    public function testInvalidUtf8StillGetsItsSecretsRedacted(): void
    {
        $context = $this->context(['p@ssw0rd-with-length']);

        $redacted = $context->redact("\xFF\xFE connexion refusée avec p@ssw0rd-with-length");

        $this->assertStringNotContainsString('p@ssw0rd-with-length', $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }

    public function testControlCharactersAreCollapsedAndTheLengthIsCapped(): void
    {
        $context = $this->context();

        $this->assertSame('a b', $context->redact("a\x00\x01\x1F\x7Fb"));
        $this->assertSame(10, mb_strlen($context->redact(str_repeat('x', 500), 10)));
    }

    /**
     * Notes travel into `collection-status.json` next to the status, and
     * used to be the one string in that file nobody scrubbed. Today's notes
     * are counts and paths a collector composed itself; "the next collector
     * will remember" is not a property worth relying on.
     */
    public function testNotesAreRedactedToo(): void
    {
        $context = $this->context(['a-database-password']);
        $context->addNote('échec avec a-database-password');

        $this->assertSame(['échec avec [REDACTED]'], $context->notes());
    }

    /**
     * The whole point of the outcome plumbing: a collector that throws
     * quoting a credential must not be able to write it into the archive.
     */
    public function testACollectorThrowingWithACredentialInItsMessageIsScrubbed(): void
    {
        $secret = 'db p@ss word';
        $context = $this->context([$secret]);

        $collector = new class ($secret) implements SupportCollectorInterface {
            public function __construct(private string $secret)
            {
            }

            public function name(): string
            {
                return 'exploding';
            }

            public function collect(SupportCollectorContext $context): void
            {
                throw new \RuntimeException('connexion échouée avec ' . $this->secret);
            }
        };

        try {
            $collector->collect($context);
            $this->fail('the collector was supposed to throw');
        } catch (\Throwable $e) {
            $reason = $context->redact($e->getMessage());
        }

        $this->assertStringNotContainsString('p@ss', $reason);
        $this->assertStringContainsString('[REDACTED]', $reason);
    }

    /**
     * SupportPackageFactory is the only place that reads `secrets.enc`, and
     * only to use the values as replacement needles.
     */
    public function testTheFactoryHandsEverySecretsEncValueOverAsANeedle(): void
    {
        $secretManager = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $secretManager->generateMasterKey();
        $secretManager->writeSecrets([
            'db_password' => 'a database password',
            'smtp_password' => 'an smtp password',
        ]);

        $needles = SupportPackageFactory::secretsToRedact($secretManager);

        $this->assertContains('a database password', $needles);
        $this->assertContains('an smtp password', $needles);
    }

    public function testTheFactoryReturnsNoNeedlesBeforeTheSiteIsInitialized(): void
    {
        $secretManager = new SecretManager(
            $this->storagePath . '/keys/does-not-exist.key',
            $this->storagePath . '/config/does-not-exist.enc'
        );

        $this->assertSame([], SupportPackageFactory::secretsToRedact($secretManager));
    }
}
