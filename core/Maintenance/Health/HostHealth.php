<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health;

use Core\Config\SettingService;
use Core\Http\InsecureBrowserAccess;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableKeys;
use Core\Scheduler\CronHealth;
use Core\Scheduler\CronStatus;
use Core\Pdf\PdfCompressor;
use Core\System\CronExecutionFacts;
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
 * - ffmpeg and ffprobe as the CRON found them (Core\System\
 *   CronExecutionFacts, #700): the video is transcoded by a task, and the
 *   gallery's video switch reads the same stored facts. The web PHP's own
 *   ability to run a program is a separate line, since the two differ on
 *   shared hosting;
 * - PDF compression through Core\Pdf\PdfCompressor's own detection.
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

    /** The two engines CI runs the whole suite on (docs/developpement.md § Développement). */
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
        // Demonstrated, not declared (ShellExecutor::probe()): a host can
        // leave exec() out of disable_functions and still run nothing —
        // and then « ffmpeg absent » would send the operator to install a
        // package that may well be there.
        $shell = ShellExecutor::probe();

        return new HostFacts(
            cron: (new CronHealth($this->storagePath, $this->settingService))->status(),
            shellDeclared: $shell['declared'],
            shellWorks: $shell['works'],
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
            shellFunction: $shell['function'],
            shellDetail: $shell['detail'],
            // Measured by the cron, read here (#700): video runs there.
            cronExecution: CronExecutionFacts::read($this->settingService),
            lastInsecureAccessAt: (new InsecureBrowserAccess($this->settingService))->lastObservedAt(),
            measuredAt: time(),
        );
    }

    /** @return list<HostCheck> in the order the page shows them */
    public static function checks(HostFacts $facts): array
    {
        return [
            self::cronCheck($facts->cron),
            self::secureConnection($facts->lastInsecureAccessAt, $facts->measuredAt),
            self::webExecution($facts),
            self::cronExecution($facts),
            self::video($facts),
            self::pdfCompression($facts),
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
        $written = @file_put_contents($probe, 'ok');
        // Removed whatever the outcome: a write cut short by a full quota
        // still leaves the file behind, one per page view.
        @unlink($probe);

        return $written === 2;
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

    /**
     * The web PHP's ability to run a program (#700) — shown on its own line,
     * because the cron's can differ, and what to ask depends on which one a
     * feature needs. Video needs the cron's: when the cron runs commands,
     * there is nothing to ask for this one.
     */
    private static function webExecution(HostFacts $facts): HostCheck
    {
        $cronWorks = $facts->cronExecution?->shellWorks === true;

        return new HostCheck(
            'shell_web',
            'Exécution de commandes (PHP web)',
            $facts->shellWorks ? HostCheck::STATE_OK : HostCheck::STATE_DEGRADED,
            self::shellStatus($facts->shellDeclared, $facts->shellWorks, $facts->shellFunction, $facts->shellDetail),
            'Ce PHP répond aux visiteurs. Les vérifications de cette page en dépendent ; la vidéo et les '
                . 'tâches de fond dépendent du PHP du cron, sur la ligne suivante.'
                . (!$facts->shellWorks && $cronWorks
                    ? ' Rien à demander pour la vidéo : le PHP du cron exécute les commandes.'
                    : ''),
            // Empty when the cron covers it, so the page never prints « À demander
            // à l'hébergeur » above a sentence saying there is nothing to ask.
            $cronWorks ? '' : self::shellAsk('le PHP web', $facts->shellDeclared, $facts->shellDetail)
        );
    }

    /** The cron's PHP, measured by public/cron.php, with when (#700). */
    private static function cronExecution(HostFacts $facts): HostCheck
    {
        $cron = $facts->cronExecution;
        if ($cron === null) {
            return new HostCheck(
                'shell_cron',
                'Exécution de commandes (PHP du cron)',
                HostCheck::STATE_DEGRADED,
                'Pas encore vérifiée : la tâche planifiée ne l\'a jamais mesurée',
                'Ce PHP fait tourner les tâches de fond, dont la conversion des vidéos.',
                'Attendre le prochain passage du cron (ligne « Tâche cron » ci-dessus).'
            );
        }

        return new HostCheck(
            'shell_cron',
            'Exécution de commandes (PHP du cron)',
            $cron->shellWorks ? HostCheck::STATE_OK : HostCheck::STATE_MISSING,
            self::shellStatus($cron->shellDeclared, $cron->shellWorks, $cron->shellFunction, $cron->shellDetail)
                . ' — vérifiée ' . self::ago($cron->probedAt, $facts->measuredAt),
            'Ce PHP fait tourner les tâches de fond, dont la conversion des vidéos.',
            self::shellAsk('le PHP du cron (PHP en ligne de commande)', $cron->shellDeclared, $cron->shellDetail)
        );
    }

    /**
     * Video is transcoded by a task, so this reads the CRON's facts (#700),
     * and asks the host for exactly what is missing there — never for a
     * shell on the web PHP, which the transcoding does not use.
     */
    private static function video(HostFacts $facts): HostCheck
    {
        $consequence = 'Sans eux, la galerie et les groupes refusent le téléversement de vidéos. Les photos ne '
            . 'sont pas concernées.';
        $cron = $facts->cronExecution;

        if ($cron === null) {
            return new HostCheck(
                'ffmpeg',
                'ffmpeg et ffprobe',
                HostCheck::STATE_DEGRADED,
                'Inconnus : la tâche planifiée ne les a pas encore cherchés',
                $consequence,
                'Attendre le prochain passage du cron (ligne « Tâche cron » ci-dessus).'
            );
        }
        if (!$cron->shellWorks) {
            return new HostCheck(
                'ffmpeg',
                'ffmpeg et ffprobe',
                HostCheck::STATE_MISSING,
                'Introuvables : le PHP du cron ne peut lancer aucun programme',
                $consequence,
                self::shellAsk('le PHP du cron (PHP en ligne de commande)', $cron->shellDeclared, $cron->shellDetail)
                    . ' Puis installer ffmpeg.'
            );
        }

        $missing = array_keys(array_filter(
            ['ffmpeg' => $cron->ffmpegPath, 'ffprobe' => $cron->ffprobePath],
            static fn(?string $path): bool => $path === null
        ));
        $when = ' — vérifié ' . self::ago($cron->probedAt, $facts->measuredAt);
        $install = 'Installer ffmpeg (le paquet fournit aussi ffprobe), exécutable par le PHP du cron.';

        if ($missing === []) {
            return new HostCheck(
                'ffmpeg',
                'ffmpeg et ffprobe',
                HostCheck::STATE_OK,
                'Présents pour le cron : ' . $cron->ffmpegPath . ', ' . $cron->ffprobePath . $when,
                $consequence,
                $install
            );
        }

        return new HostCheck(
            'ffmpeg',
            'ffmpeg et ffprobe',
            HostCheck::STATE_MISSING,
            (count($missing) === 2 ? 'Absents pour le cron' : 'Absent pour le cron : ' . $missing[0]) . $when,
            $consequence,
            $install
        );
    }

    /**
     * PDF compression (#700, #804), read from what the CRON's PHP measured —
     * never a detection here: the web PHP may be forbidden to launch any
     * program while the cron compresses very well, and a line measured in
     * the wrong PHP says « Impossible » about a machine that works.
     * **Not blocking**: a degraded line at worst, since nothing is refused
     * without it. The installation advice lives here and only here; the staff
     * page sends its reader to this page.
     */
    public static function pdfCompression(HostFacts $facts): HostCheck
    {
        $consequence = 'Sans outil de compression, les PDF téléversés ne sont pas compressés. Rien n\'est refusé.';
        $tools = [
            PdfCompressor::BACKEND_GHOSTSCRIPT => 'Ghostscript',
            PdfCompressor::BACKEND_QPDF => 'qpdf',
            PdfCompressor::BACKEND_PDFTOCAIRO => 'pdftocairo',
        ];
        $cron = $facts->cronExecution;

        // Includes a measurement stored before the cron looked at PDFs: it
        // will repeat within ten minutes, so this is not a verdict.
        if ($cron === null || !$cron->pdfMeasured()) {
            return new HostCheck(
                'pdf_compression',
                'Compression des PDF',
                HostCheck::STATE_DEGRADED,
                'Pas encore vérifiée : la tâche planifiée ne l\'a jamais mesurée',
                $consequence,
                'Attendre le prochain passage du cron (ligne « Tâche cron » ci-dessus).'
            );
        }

        $when = ' — vérifiée ' . self::ago($cron->probedAt, $facts->measuredAt);
        if (!$cron->pdfProcOpen) {
            return new HostCheck(
                'pdf_compression',
                'Compression des PDF',
                HostCheck::STATE_DEGRADED,
                'Impossible : la fonction proc_open est désactivée pour le PHP du cron' . $when,
                $consequence,
                'Retirer proc_open de la liste disable_functions du PHP du cron (PHP en ligne de commande), '
                    . 'puis installer Ghostscript si ce n\'est pas fait.'
            );
        }
        if (!isset($tools[(string) $cron->pdfBackend])) {
            return new HostCheck(
                'pdf_compression',
                'Compression des PDF',
                HostCheck::STATE_DEGRADED,
                'Aucun outil trouvé pour le PHP du cron (Ghostscript, qpdf ou pdftocairo)' . $when,
                $consequence,
                'Installer Ghostscript (paquet « ghostscript », commande gs), exécutable par le PHP du cron.'
            );
        }

        return new HostCheck(
            'pdf_compression',
            'Compression des PDF',
            HostCheck::STATE_OK,
            'Disponible pour le cron : ' . $tools[(string) $cron->pdfBackend] . $when,
            $consequence,
            'Installer Ghostscript (paquet « ghostscript », commande gs), exécutable par le PHP du cron.'
        );
    }

    /** « Possible (exec) », or what failed, in the probe's own words. */
    private static function shellStatus(bool $declared, bool $works, ?string $function, string $detail): string
    {
        if ($works) {
            return 'Possible (' . ($function ?? 'exec') . ')';
        }

        return ($declared ? 'Échoue (' . ($function ?? '?') . ') : ' : 'Interdite : ') . $detail;
    }

    /** What to ask the host for one PHP, with the exact error to copy. */
    private static function shellAsk(string $which, bool $declared, string $detail): string
    {
        return $declared
            ? 'Demander pourquoi une commande lancée par ' . $which . ' n\'aboutit pas (module de sécurité, compte '
                . 'sans shell, montage « noexec », PATH vide). Erreur exacte : ' . $detail . '.'
            : 'Autoriser pour ' . $which . ' une des fonctions exec(), shell_exec(), system() ou passthru(), '
                . 'aujourd\'hui désactivées (disable_functions).';
    }

    /** « il y a 3 min » from two Unix timestamps. */
    private static function ago(int $then, int $now): string
    {
        $seconds = max(0, $now - $then);

        return match (true) {
            $seconds < 60 => 'il y a moins d\'une minute',
            $seconds < 3600 => 'il y a ' . intdiv($seconds, 60) . ' min',
            $seconds < 86400 => 'il y a ' . intdiv($seconds, 3600) . ' h',
            default => 'il y a ' . intdiv($seconds, 86400) . ' j',
        };
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

        // The connection answered — this page could not render otherwise —
        // but it would not say what it is: an unknown engine is not a
        // supported one.
        if ($driver === '' || $version === '') {
            return new HostCheck(
                'database',
                'Base de données',
                HostCheck::STATE_DEGRADED,
                'Moteur ou version illisible',
                $consequence,
                'Vérifier que la base est bien MySQL 8.0 ou MariaDB 10.11 ou plus récente, les deux versions sur '
                    . 'lesquelles ScoutMagic est testé.'
            );
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

    /**
     * The same state as the « Connexion sécurisée » attention point and
     * notification (#751): what browsers observed, read from
     * {@see InsecureBrowserAccess}, never the scheme PHP sees — which,
     * behind a host terminating TLS, is HTTP on a perfectly secure site.
     */
    public static function secureConnection(?int $lastInsecureAccessAt, int $now): HostCheck
    {
        $active = InsecureBrowserAccess::activeAt($lastInsecureAccessAt, $now);

        return new HostCheck(
            'secure_connection',
            'Connexion sécurisée',
            $active ? HostCheck::STATE_MISSING : HostCheck::STATE_OK,
            match (true) {
                $active && $lastInsecureAccessAt !== null => 'Accès non sécurisé observé '
                    . InsecureBrowserAccess::ago($lastInsecureAccessAt, $now),
                $lastInsecureAccessAt !== null => 'Aucun accès non sécurisé depuis plus de 24 h',
                default => 'Aucun accès non sécurisé observé',
            },
            'Sans elle, les mots de passe et les données des membres circulent en clair entre le '
                . 'navigateur et le serveur.',
            'Activer le certificat HTTPS de l\'hébergement et rediriger l\'adresse en http:// vers https://.'
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
