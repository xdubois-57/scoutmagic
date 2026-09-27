<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health;

use Core\Config\SettingService;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableKeys;
use Core\Scheduler\CronHealth;
use Core\Scheduler\CronStatus;
use Core\System\ExecutableLocator;
use Core\System\ShellExecutor;

/**
 * What the site depends on at the host, one line each — the page
 * Configuration › Maintenance › Santé de l'hébergement (issue #619,
 * docs/chantiers/CHANTIER-maintenance.md IT-02).
 *
 * Two halves on purpose: detect() measures, checks() words. Every
 * measurement reuses the probe the feature itself relies on, so this page
 * can never say « présent » about something the feature then fails to
 * find:
 *
 * - ffmpeg and ffprobe through Core\System\ExecutableLocator, as
 *   Core\Support\Collector\CommandsCollector does. Core may not name
 *   Modules\Gallery (ARCHITECTURE.md §7.5), and the gallery's own rule is
 *   « the setting and both binaries »: the binaries are the host's half.
 * - archive encryption through BackupService::supportsZipEncryption(),
 *   the check that hides the full backup form;
 * - sodium through PortableKeys::hasSodium(), the check that picks the key
 *   derivation of a portable backup.
 *
 * **Disk space is deliberately absent.** It is measured on Configuration ›
 * Stockage, volume by volume; a figure here and another there would be two
 * answers to the same question, one of them wrong. The page links there.
 * The automatic update status is absent too: that is the state of the
 * update, not of the host, and it lives on the Mise à jour page.
 */
final class HostHealth
{
    /** Required by composer.json; below it the code does not even load. */
    public const PHP_MINIMUM = '8.4.0';

    /** The two engines CI runs the whole suite on (README.md § Tests). */
    public const MYSQL_TESTED = '8.0.0';
    public const MARIADB_TESTED = '10.11.0';

    /**
     * What webklex/php-imap needs beyond PHP itself — the incoming mail
     * module's client (Modules\InboundMail). Not ext-imap: the library
     * speaks IMAP over its own sockets.
     */
    public const MAIL_EXTENSIONS = ['mbstring', 'openssl', 'iconv', 'fileinfo', 'libxml', 'zip'];

    public function __construct(
        private readonly string $storagePath,
        private readonly SettingService $settingService,
        private readonly BackupService $backupService,
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * Measures this host. Spawns a process or two for ffmpeg and ffprobe,
     * and writes then deletes a small zip: called for the one page that
     * shows the result, never from the context the six sub-pages share.
     */
    public function detect(): HostFacts
    {
        $shell = ShellExecutor::isAvailable();

        return new HostFacts(
            cron: (new CronHealth($this->storagePath, $this->settingService))->status(),
            shellAvailable: $shell,
            ffmpegPath: $shell ? ExecutableLocator::find('ffmpeg') : null,
            ffprobePath: $shell ? ExecutableLocator::find('ffprobe') : null,
            zipEncryption: $this->backupService->supportsZipEncryption(),
            sodium: PortableKeys::hasSodium(),
            gd: extension_loaded('gd'),
            missingMailExtensions: array_values(array_filter(
                self::MAIL_EXTENSIONS,
                static fn(string $extension): bool => !extension_loaded($extension)
            )),
            phpVersion: PHP_VERSION,
            databaseDriver: $this->pdoAttribute(\PDO::ATTR_DRIVER_NAME),
            databaseVersion: $this->pdoAttribute(\PDO::ATTR_SERVER_VERSION),
            storageWritable: self::isWritableDirectory($this->storagePath),
        );
    }

    /** @return list<HostCheck> in the order the page shows them */
    public static function checks(HostFacts $facts): array
    {
        return [
            self::cronCheck($facts->cron),
            self::video($facts),
            self::archiveEncryption($facts->zipEncryption),
            self::sodium($facts->sodium),
            self::gd($facts->gd),
            self::mail($facts->missingMailExtensions),
            self::php($facts->phpVersion),
            self::database($facts->databaseDriver, $facts->databaseVersion),
            self::storage($facts->storageWritable),
        ];
    }

    /**
     * Writable by the test that matters — creating a file — rather than by
     * is_writable() alone, which an ACL, a read-only mount or a quota can
     * each contradict.
     */
    public static function isWritableDirectory(string $path): bool
    {
        if (!is_dir($path) || !is_writable($path)) {
            return false;
        }

        $probe = rtrim($path, '/') . '/.host-health-' . bin2hex(random_bytes(6));
        if (@file_put_contents($probe, 'ok') !== 2) {
            return false;
        }
        @unlink($probe);

        return true;
    }

    public static function cronCheck(CronStatus $status): HostCheck
    {
        $seconds = $status->secondsSinceLastSeen() ?? 0;
        [$state, $text] = match ($status->state) {
            CronStatus::STATE_ACTIVE => [
                HostCheck::STATE_OK,
                "Active — dernier passage il y a {$seconds} s" . self::cadence($status->medianIntervalSeconds),
            ],
            CronStatus::STATE_STALE => [
                HostCheck::STATE_MISSING,
                'Plus détectée — dernier passage il y a ' . intdiv($seconds, 60) . ' min',
            ],
            default => [HostCheck::STATE_MISSING, 'Jamais détectée'],
        };

        return new HostCheck(
            'cron',
            'Tâche cron',
            $state,
            $text,
            'Sans elle, rien ne tourne en dehors des visites : ni sauvegarde automatique, ni mise à jour, '
                . 'ni rappel, ni notification.',
            'Ajouter la ligne ci-dessous dans les tâches planifiées de l\'hébergement (rubrique « Tâches '
                . 'planifiées » ou « Cron »).'
        );
    }

    private static function cadence(?int $medianSeconds): string
    {
        if ($medianSeconds === null || $medianSeconds <= 0) {
            return '';
        }

        return ', cadence ~' . ($medianSeconds < 60 ? $medianSeconds . ' s' : intdiv($medianSeconds, 60) . ' min');
    }

    private static function video(HostFacts $facts): HostCheck
    {
        $consequence = 'Sans eux, la galerie refuse le téléversement de vidéos. Les photos ne sont pas concernées.';
        $install = 'Installer ffmpeg (le paquet fournit aussi ffprobe), exécutable par l\'utilisateur qui fait '
            . 'tourner PHP.';

        if (!$facts->shellAvailable) {
            return new HostCheck(
                'ffmpeg',
                'ffmpeg et ffprobe',
                HostCheck::STATE_MISSING,
                'Introuvables : PHP n\'a le droit de lancer aucun programme',
                $consequence,
                'Autoriser une des fonctions exec(), shell_exec(), system() ou passthru(), aujourd\'hui '
                    . 'désactivées (disable_functions), puis installer ffmpeg.'
            );
        }

        $missing = array_keys(array_filter(
            ['ffmpeg' => $facts->ffmpegPath, 'ffprobe' => $facts->ffprobePath],
            static fn(?string $path): bool => $path === null
        ));
        if ($missing === []) {
            return new HostCheck(
                'ffmpeg',
                'ffmpeg et ffprobe',
                HostCheck::STATE_OK,
                'Présents',
                $consequence,
                $install
            );
        }

        return new HostCheck(
            'ffmpeg',
            'ffmpeg et ffprobe',
            HostCheck::STATE_MISSING,
            (count($missing) === 2 ? 'Absents' : 'Absent : ' . $missing[0]),
            $consequence,
            $install
        );
    }

    private static function archiveEncryption(bool $supported): HostCheck
    {
        return new HostCheck(
            'archive_encryption',
            'Chiffrement des archives',
            $supported ? HostCheck::STATE_OK : HostCheck::STATE_MISSING,
            $supported ? 'Disponible (AES-256)' : 'Indisponible',
            'Sans lui, la sauvegarde complète et la sauvegarde portable sont indisponibles : une archive '
                . 'qui contient toutes les données ne quitte jamais le serveur en clair. La sauvegarde de '
                . 'la base de données seule reste possible.',
            'Une extension PHP zip construite avec une libzip qui chiffre (libzip 1.2 ou plus récente, '
                . 'avec OpenSSL).'
        );
    }

    private static function sodium(bool $available): HostCheck
    {
        return new HostCheck(
            'sodium',
            'libsodium',
            $available ? HostCheck::STATE_OK : HostCheck::STATE_DEGRADED,
            $available ? 'Disponible (Argon2id)' : 'Absente — repli sur PBKDF2',
            'Sans elle, les sauvegardes portables se chiffrent quand même, mais leur mot de passe résiste '
                . 'moins bien aux essais en série ; et une sauvegarde portable faite sur un serveur qui '
                . 'l\'a ne peut pas être restaurée ici.',
            'Activer l\'extension PHP sodium, livrée avec PHP.'
        );
    }

    private static function gd(bool $loaded): HostCheck
    {
        return new HostCheck(
            'gd',
            'GD',
            $loaded ? HostCheck::STATE_OK : HostCheck::STATE_MISSING,
            $loaded ? 'Disponible' : 'Absente',
            'Sans elle, aucune image n\'est transformée : ni vignettes de la galerie, ni icônes de '
                . 'l\'application installée sur téléphone, ni photos de section.',
            'Activer l\'extension PHP gd, avec la prise en charge de JPEG, PNG et WebP.'
        );
    }

    /** @param list<string> $missing */
    private static function mail(array $missing): HostCheck
    {
        return new HostCheck(
            'mail',
            'Courrier entrant (IMAP)',
            $missing === [] ? HostCheck::STATE_OK : HostCheck::STATE_MISSING,
            $missing === [] ? 'Disponible' : 'Extensions absentes : ' . implode(', ', $missing),
            'Sans elles, le module Courrier entrant ne relève plus aucune boîte aux lettres.',
            'Activer les extensions PHP ' . implode(', ', $missing) . '.'
        );
    }

    private static function php(string $version): HostCheck
    {
        $supported = version_compare($version, self::PHP_MINIMUM, '>=');

        return new HostCheck(
            'php',
            'PHP',
            $supported ? HostCheck::STATE_OK : HostCheck::STATE_MISSING,
            'PHP ' . $version,
            'ScoutMagic demande PHP 8.4 ou plus récent : sur une version plus ancienne, la prochaine mise '
                . 'à jour ne démarre plus.',
            'Passer le site en PHP 8.4 dans le panneau de l\'hébergement.'
        );
    }

    private static function database(string $driver, string $version): HostCheck
    {
        $consequence = 'Sans elle, plus rien ne s\'affiche : toutes les données du site y vivent.';

        if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $mariadb) === 1) {
            return self::versionedDatabase('MariaDB', $mariadb[1], self::MARIADB_TESTED, '10.11', $consequence);
        }
        if ($driver === 'mysql' && preg_match('/^(\d+\.\d+\.\d+)/', $version, $mysql) === 1) {
            return self::versionedDatabase('MySQL', $mysql[1], self::MYSQL_TESTED, '8.0', $consequence);
        }

        return new HostCheck(
            'database',
            'Base de données',
            HostCheck::STATE_OK,
            trim($driver . ' ' . $version),
            $consequence,
            ''
        );
    }

    private static function versionedDatabase(
        string $engine,
        string $version,
        string $tested,
        string $testedLabel,
        string $consequence
    ): HostCheck {
        $current = version_compare($version, $tested, '>=');

        return new HostCheck(
            'database',
            'Base de données',
            $current ? HostCheck::STATE_OK : HostCheck::STATE_DEGRADED,
            $engine . ' ' . $version . ($current ? '' : ' — version que ScoutMagic ne teste pas'),
            $consequence,
            "Passer à {$engine} {$testedLabel} ou plus récent, la version sur laquelle "
                . 'chaque mise à jour de ScoutMagic est testée.'
        );
    }

    private static function storage(bool $writable): HostCheck
    {
        return new HostCheck(
            'storage',
            'Écriture dans storage/',
            $writable ? HostCheck::STATE_OK : HostCheck::STATE_MISSING,
            $writable ? 'Possible' : 'Impossible',
            'Sans elle, rien ne s\'enregistre : ni fichier téléversé, ni sauvegarde, ni journal, ni cache.',
            'Rendre le dossier storage/ et son contenu inscriptibles par l\'utilisateur qui fait tourner PHP.'
        );
    }

    private function pdoAttribute(int $attribute): string
    {
        try {
            $value = $this->pdo->getAttribute($attribute);
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
