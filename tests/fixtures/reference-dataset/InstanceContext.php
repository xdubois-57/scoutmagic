<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;

/**
 * The installation the builder is about to write into.
 *
 * Opens the instance the same way `public/index.php` does — its own
 * `storage/keys/master.key` and `storage/config/secrets.enc` through
 * SecretManager, its own database credentials, its own encryption and blind
 * index keys.
 *
 * **This is the whole reason the dataset is a recipe and not a dump.** The
 * keys never travel: whatever the builder writes is encrypted with the keys of
 * the machine it is running on, so there is no restore step that could produce
 * unreadable personal data or blind indexes matching nothing. A ScoutMagic
 * backup cannot do that — BackupService::createFileBackup() excludes
 * `storage/keys/` and `storage/config/` precisely so secrets never leave the
 * server, which is correct for a backup and fatal for a portable fixture.
 *
 * It also carries the refusal to run somewhere it should not. That check is
 * not a formality: this builder writes hundreds of rows and creates login
 * accounts whose passwords are published in a README.
 */
final class InstanceContext
{
    private ?\PDO $pdo = null;

    /** @var array<string, mixed>|null */
    private ?array $secrets = null;

    private function __construct(private readonly string $rootPath)
    {
    }

    /** @param string $rootPath the installation root (the directory holding storage/) */
    public static function at(string $rootPath): self
    {
        return new self(rtrim($rootPath, '/'));
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function storagePath(): string
    {
        return $this->rootPath . '/storage';
    }

    /**
     * @throws \RuntimeException when the installation is not set up yet
     */
    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $secrets = $this->secrets();

        $connection = new Connection(
            (string) ($secrets['db_host'] ?? 'localhost'),
            (int) ($secrets['db_port'] ?? 3306),
            (string) ($secrets['db_name'] ?? ''),
            (string) ($secrets['db_user'] ?? ''),
            (string) ($secrets['db_password'] ?? ''),
        );

        return $this->pdo = $connection->getPdo();
    }

    public function encryption(): EncryptionService
    {
        $secrets = $this->secrets();

        return EncryptionService::fromEncodedKeys(
            (string) ($secrets['encryption_key'] ?? ''),
            (string) ($secrets['blind_index_key'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function secrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }

        $manager = new SecretManager(
            $this->storagePath() . '/keys/master.key',
            $this->storagePath() . '/config/secrets.enc',
        );

        if (!$manager->isInitialized()) {
            throw new \RuntimeException(
                "Cette installation n'est pas encore configurée : storage/config/secrets.enc est absent. "
                . 'Terminez l\'assistant d\'installation avant de construire le jeu de données.'
            );
        }

        return $this->secrets = $manager->readSecrets();
    }

    /**
     * Reasons this installation must not be built into, in the order a human
     * would notice them.
     *
     * The builder is destructive by design (see README.md §8): it requires a
     * database with no members in it, rather than trying to merge into one.
     * "Idempotent or frankly destructive, never in between" — a builder that
     * half-rejoins an existing roster is the trap.
     *
     * @return list<string> empty when the installation is safe to build into
     */
    public function refusalReasons(): array
    {
        $reasons = [];

        $members = $this->countOf('members');
        if ($members > 0) {
            $reasons[] = sprintf(
                'la base contient déjà %d membre(s) : le builder exige une installation vierge et ne fusionne rien.',
                $members,
            );
        }

        $transactions = $this->countOf('finance_transactions');
        if ($transactions > 0) {
            $reasons[] = sprintf('la base contient déjà %d mouvement(s) financier(s).', $transactions);
        }

        $accounts = $this->countOf('user_accounts');
        if ($accounts > 1) {
            $reasons[] = sprintf(
                'la base contient %d comptes utilisateurs : au-delà du superadministrateur de l\'installation, '
                . 'cette instance a servi.',
                $accounts,
            );
        }

        return $reasons;
    }

    /**
     * A missing table counts as zero rather than as an error: a module can be
     * disabled on the target instance, and the builder simply has nothing to
     * check there.
     */
    private function countOf(string $table): int
    {
        try {
            $statement = $this->pdo()->query('SELECT COUNT(*) AS n FROM ' . $table);
            if ($statement === false) {
                return 0;
            }
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            return (int) ($row['n'] ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }
}
