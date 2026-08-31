<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Service\DateInput;

/**
 * Builds the diagnostic support package (ARCHITECTURE.md §8.48).
 *
 * The contract is deliberately one-sided: **a package is always produced**.
 * Every collector runs inside its own try/catch, and a collector that
 * throws, lacks a permission, finds no tool, or is meaningless on this
 * platform contributes its own line to `collection-status.json` and nothing
 * else. An archive missing three diagnostics still answers most support
 * questions; an archive that failed to generate answers none.
 *
 * Storage follows from what the archive contains — PHP configuration,
 * server logs, filesystem diagnostics: it is encrypted at rest
 * (Core\File\EncryptedFileStorageService), its FileRecord is `superadmin`,
 * it is served only through `/files/{id}`, exactly one is ever kept, and it
 * is purged after seven days.
 */
class SupportPackageService
{
    public const STORAGE_SUBDIRECTORY = 'core/support';
    public const RETENTION_DAYS = 7;

    private const ARCHIVE_NAME = 'scoutmagic-support.zip';

    /** @var array<int, SupportCollectorInterface> */
    private array $collectors;

    /** @var array<int, string> */
    private array $secretsToRedact;

    /**
     * @param array<int, SupportCollectorInterface> $collectors
     * @param array<int, string> $secretsToRedact literal secret values that
     *        must never appear in collection-status.json, even quoted inside
     *        an exception message. Supplied by the caller (which owns
     *        Core\Security\SecretManager); this service never reads
     *        secrets.enc itself.
     */
    public function __construct(
        private Connection $connection,
        private SettingService $settingService,
        private FileRepository $fileRepository,
        private EncryptedFileStorageService $encryptedStorage,
        private string $projectRoot,
        private string $storagePath,
        array $collectors = [],
        array $secretsToRedact = []
    ) {
        $this->collectors = $collectors;
        $this->secretsToRedact = array_values(array_filter(
            $secretsToRedact,
            static fn(string $secret): bool => strlen(trim($secret)) >= 8
        ));
    }

    /**
     * Generate a package, replacing whatever previous one exists, and
     * return its file id.
     *
     * @throws SupportPackageException when the archive itself cannot be
     *         created or stored — the only genuinely fatal case, since
     *         there is then nothing to hand the user.
     */
    public function generate(?int $requestedByUserAccountId = null): int
    {
        $tempDirectory = $this->storagePath . '/temp/support-' . bin2hex(random_bytes(8));
        if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
            throw new SupportPackageException('Impossible de créer le répertoire temporaire du paquet de support.');
        }

        $archivePath = $tempDirectory . '/' . self::ARCHIVE_NAME;

        try {
            $archive = new \ZipArchive();
            if ($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new SupportPackageException('Impossible de créer l\'archive de support.');
            }

            $context = new SupportCollectorContext(
                $archive,
                $this->connection,
                $this->settingService,
                $this->projectRoot,
                $this->storagePath,
                $this->secretsToRedact
            );

            $outcomes = [];
            foreach ($this->collectors as $collector) {
                $outcomes[] = $this->runCollector($collector, $context);
            }

            $archive->addFromString('collection-status.json', self::renderStatus($outcomes));
            $archive->addFromString('README.txt', $this->renderReadme());
            $archive->close();

            $content = @file_get_contents($archivePath);
            if ($content === false) {
                throw new SupportPackageException('Archive de support illisible après génération.');
            }

            $this->deleteCurrentPackage();

            $fileId = $this->encryptedStorage->store(
                $content,
                'application/zip',
                self::ARCHIVE_NAME,
                self::STORAGE_SUBDIRECTORY,
                'superadmin',
                null,
                $requestedByUserAccountId
            );

            $this->writeSetting(SupportPackageState::FILE_ID, (string) $fileId);
            $this->writeSetting(SupportPackageState::GENERATED_AT, self::nowIso());

            return $fileId;
        } finally {
            if (is_file($archivePath)) {
                @unlink($archivePath);
            }
            if (is_dir($tempDirectory)) {
                @rmdir($tempDirectory);
            }
        }
    }

    /**
     * Remove the currently-stored package, file and FileRecord alike. Safe
     * to call when there is none.
     */
    public function deleteCurrentPackage(): void
    {
        $fileId = (int) ($this->settingService->get(SupportPackageState::FILE_ID) ?? '0');
        if ($fileId > 0) {
            try {
                $this->encryptedStorage->delete($fileId);
            } catch (\Throwable) {
                // A record without its file (or the reverse) must not stop a
                // new package from being produced.
                $this->fileRepository->delete($fileId);
            }
        }

        $this->writeSetting(SupportPackageState::FILE_ID, '');
        $this->writeSetting(SupportPackageState::GENERATED_AT, '');
    }

    /**
     * Delete the stored package once it is older than RETENTION_DAYS.
     * Returns true when something was actually purged.
     */
    public function purgeExpired(): bool
    {
        $generatedAt = (string) ($this->settingService->get(SupportPackageState::GENERATED_AT) ?? '');
        if (trim($generatedAt) === '') {
            return false;
        }

        $generated = DateInput::fromStorage($generatedAt);
        if ($generated === null) {
            // An unreadable stamp is itself a reason to drop the archive:
            // it cannot be proven to be within retention.
            $this->deleteCurrentPackage();

            return true;
        }

        if ((time() - $generated->getTimestamp()) < self::RETENTION_DAYS * 86400) {
            return false;
        }

        $this->deleteCurrentPackage();

        return true;
    }

    public function currentPackageFileId(): ?int
    {
        $fileId = (int) ($this->settingService->get(SupportPackageState::FILE_ID) ?? '0');

        return $fileId > 0 ? $fileId : null;
    }

    public function currentPackageGeneratedAt(): ?string
    {
        $value = (string) ($this->settingService->get(SupportPackageState::GENERATED_AT) ?? '');

        return trim($value) !== '' ? trim($value) : null;
    }

    private function runCollector(SupportCollectorInterface $collector, SupportCollectorContext $context): SupportCollectionOutcome
    {
        $context->resetCollectorState();
        $startedAt = microtime(true);

        try {
            $collector->collect($context);
            $durationMs = self::elapsedMs($startedAt);

            $unavailable = $context->unavailableReason();

            return $unavailable !== null
                ? SupportCollectionOutcome::unavailable($collector->name(), $context->redact($unavailable), $durationMs, $context->notes())
                : SupportCollectionOutcome::success($collector->name(), $durationMs, $context->notes());
        } catch (\Throwable $e) {
            return SupportCollectionOutcome::failed(
                $collector->name(),
                $context->redact($e->getMessage()),
                self::elapsedMs($startedAt),
                $context->notes()
            );
        }
    }

    /**
     * The clock every other file in the archive is written against.
     *
     * Two of them — the event journal and the copied server logs — carry
     * bare `Y-m-d H:i:s` stamps in local time, while the JSON files here
     * are ISO-8601 in UTC. Nothing said so, and reading one against the
     * other is how an incident gets attributed to the wrong event: on the
     * archive that prompted this, a fatal error and the update that caused
     * it were 54 seconds apart in local time and two hours apart if the
     * log was read as UTC — far enough to blame the previous update
     * instead.
     *
     * **Two zones are reported, not one**, and the difference is the point.
     * Core\Config\AppClock pins the application to Europe/Brussels at
     * every entry point, so that is the clock the journal and everything
     * PHP writes are on. The host's own `date.timezone` may be something
     * else entirely — Europe/Paris on the installation that prompted this
     * — and lines the WEB SERVER writes into its logs are on that one.
     * Reporting only the application clock would misdate exactly the file
     * this section exists to make readable.
     *
     * @return array<string, string>
     */
    private static function timezoneInfo(): array
    {
        $now = new \DateTimeImmutable('now');
        $hostTimezone = trim((string) ini_get('date.timezone'));

        return [
            'application_timezone' => date_default_timezone_get(),
            'utc_offset' => $now->format('P'),
            'local_time_at_generation' => $now->format('Y-m-d H:i:s'),
            'host_php_timezone' => $hostTimezone !== '' ? $hostTimezone : '(non défini)',
            'note' => "Le journal des événements et tout ce qu'écrit PHP sont à l'heure de "
                . "l'application (application_timezone). Les lignes écrites par le serveur web "
                . 'lui-même peuvent suivre host_php_timezone si les deux diffèrent. Les dates de '
                . 'ce fichier et de statistics.json sont en UTC (suffixe +00:00).',
        ];
    }

    /**
     * @param array<int, SupportCollectionOutcome> $outcomes
     */
    private static function renderStatus(array $outcomes): string
    {
        return (string) json_encode(
            [
                'generated_at' => self::nowIso(),
                'clock' => self::timezoneInfo(),
                'collectors' => array_map(
                    static fn(SupportCollectionOutcome $outcome): array => $outcome->toArray(),
                    $outcomes
                ),
            ],
            // JSON_INVALID_UTF8_SUBSTITUTE as a second line after
            // SupportCollectorContext::redact()'s own coercion: this file
            // is the record of what did and did not work, and the one
            // outcome it may never have is "empty because one collector's
            // reason carried a stray byte".
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private function renderReadme(): string
    {
        $supportEmail = (string) ($this->settingService->get('support_email') ?? '');
        $supportLine = $supportEmail !== ''
            ? "Adresse du support ScoutMagic : {$supportEmail}"
            : "Adresse du support ScoutMagic : non renseignée sur ce site.";

        $clock = self::timezoneInfo();
        $clockLine = "Fuseau horaire de l'application : {$clock['application_timezone']} "
            . "(UTC{$clock['utc_offset']})\n        Fuseau horaire PHP de l'hébergement : {$clock['host_php_timezone']}";

        return <<<TXT
        ARCHIVE DE SUPPORT SCOUTMAGIC
        =============================

        Cette archive a été générée localement par votre site ScoutMagic, à la
        demande d'un administrateur. Rien n'est transmis automatiquement :
        elle se trouve uniquement sur votre serveur tant qu'un administrateur
        ne la transmet pas lui-même, depuis Configuration > Support, en la
        joignant à un ticket et en cochant explicitement qu'il en accepte le
        contenu.

        À QUOI ELLE SERT
        ----------------
        Elle rassemble, en un seul fichier, les informations dont le support a
        besoin pour comprendre ce qui tourne réellement sur votre hébergement.
        Sans elle, diagnostiquer un problème demande une longue série
        d'allers-retours par courriel.

        CE QU'ELLE CONTIENT
        -------------------
        - collection-status.json : ce qui a pu être collecté, ce qui a échoué,
          et pourquoi. Certaines rubriques sont normalement indisponibles sur
          un hébergement mutualisé : ce n'est pas une anomalie.
        - statistics.json : les mêmes informations agrégées que le rapport
          d'utilisation (version installée, modules, compteurs, environnement
          technique). Aucune donnée de membre, aucun identifiant de sécurité.
        - logs/errors-resume.txt : les erreurs des journaux serveur regroupées,
          la plus fréquente en premier. À lire avant les journaux eux-mêmes.
        - update-history.xlsx : quelle version tournait à quel moment, et ce
          qu'a fait chaque installation (y compris celles qui ont échoué).
        - event-journal-resume.txt : ce que contiennent les 48 h du journal,
          par type d'évènement, avant d'en lire le détail.
        - opcache.json : l'état réel du cache de code compilé, notamment
          combien de temps une mise à jour peut mettre à prendre effet.
        - README.txt : ce fichier.

        HEURES
        ------
        {$clockLine}

        Les horodatages du journal des événements sont à l'heure de
        l'application. Les journaux serveur suivent le fuseau de l'hébergement
        lorsqu'il diffère. Les dates des fichiers .json sont en UTC (suffixe
        +00:00). Comparer les deux sans en tenir compte fait attribuer un
        incident au mauvais évènement.

        AVERTISSEMENT — À LIRE AVANT ENVOI
        ----------------------------------
        L'archive de support générée peut contenir des informations propres à
        votre système. Elle n'a pas vocation à contenir de données
        personnelles, mais cela ne peut pas être garanti. Merci de vérifier
        son contenu avant de l'envoyer au support ScoutMagic.

        Cela vaut particulièrement pour les informations PHP (configuration
        détaillée du serveur), les journaux d'accès et d'erreurs du serveur web
        (qui contiennent des adresses IP de visiteurs) et les diagnostics de
        système de fichiers.

        ENVOI
        -----
        {$supportLine}

        Aucun envoi automatique n'est effectué, sous aucune forme : ni tâche
        planifiée, ni courriel, ni envoi déclenché par le site. Un
        administrateur peut en revanche la transmettre lui-même, depuis
        Configuration > Support, en la joignant à un ticket de support :
        la page liste alors ce que contient l'archive et sa taille, et
        demande de cocher que vous acceptez de la transmettre. C'est le seul
        chemin par lequel cette archive peut quitter votre serveur.
        TXT;
    }

    private function writeSetting(string $key, string $value): void
    {
        try {
            $this->settingService->setInternal($key, $value);
        } catch (\Throwable) {
            // Bookkeeping must never turn a produced package into a failure.
        }
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private static function nowIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
