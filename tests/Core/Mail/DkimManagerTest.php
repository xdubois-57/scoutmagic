<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use PHPUnit\Framework\TestCase;

class DkimManagerTest extends TestCase
{
    private string $tempDir;
    private DkimManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/dkim_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->manager = new DkimManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testHasKeyReturnsFalseWhenNoKeyExists(): void
    {
        $this->assertFalse($this->manager->hasKey());
    }

    public function testGenerateKeyCreatesPrivateKeyFileAndReturnsPublicKey(): void
    {
        $publicKey = $this->manager->generateKey();

        $this->assertTrue($this->manager->hasKey());
        $this->assertFileExists($this->manager->getPrivateKeyPath());
        $this->assertNotEmpty($publicKey);
        // Public key should be a base64 string
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $publicKey);
    }

    public function testGenerateKeyThrowsWhenKeyAlreadyExists(): void
    {
        $this->manager->generateKey();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->manager->generateKey();
    }

    public function testGetPublicKeyReturnsSameKeyAsGenerate(): void
    {
        $generatedKey = $this->manager->generateKey();
        $retrievedKey = $this->manager->getPublicKey();

        $this->assertSame($generatedKey, $retrievedKey);
    }

    public function testDeleteKeyRemovesFile(): void
    {
        $this->manager->generateKey();
        $this->assertTrue($this->manager->hasKey());

        $this->manager->deleteKey();
        $this->assertFalse($this->manager->hasKey());
    }

    public function testGenerateKeyAfterDeleteKeyWorks(): void
    {
        $key1 = $this->manager->generateKey();
        $this->manager->deleteKey();
        $key2 = $this->manager->generateKey();

        $this->assertTrue($this->manager->hasKey());
        $this->assertNotEmpty($key2);
    }

    /**
     * `replaceKey()`'s reason to exist is that the site keeps signing
     * whatever happens, so its write path is where the assertions belong.
     *
     * The regeneration test on the controller side cannot supply them: it
     * overrides `replaceKey()` on a double to throw, so none of the real
     * write, chmod, read-back, rename or cleanup runs there, and its « the
     * old key survived » is true of a method that was never called. Caught
     * in review on the pull request that added this method.
     */
    public function testReplaceKeyMintsANewKeyAndLeavesTheDirectoryAsItFoundIt(): void
    {
        $before = $this->manager->generateKey();
        $beforeBytes = (string) file_get_contents($this->manager->getPrivateKeyPath());

        $after = $this->manager->replaceKey();

        $this->assertNotSame($before, $after, 'a rotation that returns the same key rotated nothing');
        $this->assertNotSame($beforeBytes, file_get_contents($this->manager->getPrivateKeyPath()));
        // The half returned is the half on disk — the point of reading the
        // file back instead of trusting the in-memory resource.
        $this->assertSame($after, $this->manager->getPublicKey());
        $this->assertSame('0600', $this->permissionsOfTheKey());
        $this->assertSame([], $this->leftoverTemporaries(), 'a *.new file survived a SUCCESSFUL rotation');
    }

    /**
     * A failure at the last step: the key is written, restricted and read
     * back, and then `rename()` refuses.
     *
     * The lever is a **directory** where the live key belongs. Making the
     * key directory read-only would not do it — the suite runs as root in
     * CI and in the dev container, and root writes into a 0500 directory
     * without blinking, so that test would pass by not failing. `rename()`
     * onto a directory is refused for everybody, which is why this is the
     * one forced failure that means the same thing wherever it runs.
     *
     * What it pins is the `finally`: whatever goes wrong, no half-written
     * private key is left lying in `storage/dkim` — and the live path is
     * exactly as it was.
     */
    public function testAFailedRenameLeavesNoHalfWrittenKeyBehind(): void
    {
        $inTheWay = $this->manager->getPrivateKeyPath();
        mkdir($inTheWay, 0700, true);

        $warning = $this->refusing(
            fn() => $this->manager->replaceKey(),
            'Cannot put the new DKIM key in place.'
        );

        // And the warning really was the rename refusing — not some other
        // failure this test would otherwise have counted as its own.
        $this->assertStringContainsString('Is a directory', (string) $warning);

        $this->assertDirectoryExists($inTheWay, 'the live path was disturbed by a failed rotation');
        $this->assertSame([], $this->leftoverTemporaries(), 'a *.new file survived a FAILED rotation');
    }

    /**
     * A file where the key DIRECTORY belongs — a deployment that unpacked
     * over `storage/`, or a `dkim` that was once something else.
     *
     * Reachable, unlike the four refusals around it (OpenSSL unavailable, a
     * short write, a `chmod` that fails): each of those needs a machine
     * state this suite cannot produce, and `mkdir` over an existing name
     * fails for everybody.
     */
    public function testTheKeyDirectoryCannotBeCreatedOverAFileOfThatName(): void
    {
        file_put_contents($this->tempDir . '/dkim', 'not a directory');

        $this->refusing(
            fn() => $this->manager->generateKey(),
            'Cannot create the DKIM key directory: ' . $this->tempDir . '/dkim'
        );
    }

    /**
     * `getPublicKey()` is where `publicHalfOf()`'s refusals become
     * reachable, and the reason it delegates to it: from the write path the
     * file being reloaded was written a line earlier, so it is readable and
     * it parses, and neither `throw` could be exercised at all.
     *
     * **It is the PARSE that refuses a directory, not the read** — which is
     * the opposite of what this test first asserted. `file_get_contents()`
     * on a directory does not return `false`: it raises a NOTICE
     * (`errno=21 Is a directory`) and hands back an EMPTY STRING, which then
     * fails to parse as a key. So the `=== false` branch above it stays
     * unreachable from both callers, and is kept as the guard on a contract
     * `file_get_contents()` still has rather than as a path anything here
     * covers.
     */
    public function testGetPublicKeyRefusesAKeyPathThatHasBecomeADirectory(): void
    {
        mkdir($this->manager->getPrivateKeyPath(), 0700, true);

        $warning = $this->refusing(
            fn() => $this->manager->getPublicKey(),
            'The DKIM private key at ' . $this->manager->getPrivateKeyPath() . ' cannot be parsed.'
        );

        $this->assertStringContainsString('Is a directory', (string) $warning);
    }

    public function testGetPublicKeyRefusesAFileThatIsNotAKey(): void
    {
        $this->manager->generateKey();
        file_put_contents($this->manager->getPrivateKeyPath(), "-----BEGIN PRIVATE KEY-----\ntruncated\n");

        $this->refusing(
            fn() => $this->manager->getPublicKey(),
            'The DKIM private key at ' . $this->manager->getPrivateKeyPath() . ' cannot be parsed.'
        );
    }

    /**
     * Runs `$attempt`, requires it to refuse with exactly `$message`, and
     * returns the PHP warning it swallowed on the way, if any.
     *
     * They are swallowed HERE rather than with an `@` in the manager:
     * `rename()`, `mkdir()` and `file_get_contents()` each name the offending
     * path in theirs, which is worth having in a production log — and
     * phpunit.xml says in as many words that the six warnings left in this
     * suite are a debt someone means to pay down, not a number to add to.
     *
     * **`E_NOTICE` is in the mask, and it has to be.** The three do not agree
     * on a level: `rename()` and `mkdir()` raise `E_WARNING`, while
     * `file_get_contents()` reporting `errno=21 Is a directory` raises an
     * `E_NOTICE`. A mask of `E_WARNING` alone let that one through to the
     * suite's output and returned null here, which read as « no diagnostic
     * was raised » — the opposite of what happened.
     *
     * @param callable():mixed $attempt
     */
    private function refusing(callable $attempt, string $message): ?string
    {
        $warning = null;
        set_error_handler(function (int $level, string $raised) use (&$warning): bool {
            $warning = $raised;

            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $attempt();
            $this->fail('Expected a RuntimeException: ' . $message);
        } catch (\RuntimeException $refusal) {
            $this->assertSame($message, $refusal->getMessage());
        } finally {
            restore_error_handler();
        }

        return $warning;
    }

    /** @return string[] the temporary names `writeNewKey()` writes under */
    private function leftoverTemporaries(): array
    {
        $entries = glob(dirname($this->manager->getPrivateKeyPath()) . '/*.new');

        return $entries === false ? [] : $entries;
    }

    private function permissionsOfTheKey(): string
    {
        clearstatcache(true, $this->manager->getPrivateKeyPath());

        return substr(sprintf('%o', (int) fileperms($this->manager->getPrivateKeyPath())), -4);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
