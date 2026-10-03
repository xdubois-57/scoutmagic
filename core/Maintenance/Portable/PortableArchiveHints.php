<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Service\DateInput;

/**
 * What a portable archive says about itself in clear, in its zip comment
 * (#719): the ScoutMagic version that wrote it, the site it came from,
 * when, which kind of backup it is, and — for an off-site one — which
 * passphrase generation opens it.
 *
 * **Hints, never established facts.** The comment is readable and
 * editable by anyone holding the file, so nothing here is trusted for a
 * security decision. The bootstrap reads it from the tail of the file,
 * before anything is uploaded, to pick the release to install and to let
 * the operator confirm it is the right archive; the restore then compares
 * {@see $version} with the encrypted manifest, which is what counts, and
 * refuses on any mismatch ({@see PortableArchive::open()}). Every value is
 * therefore bounded and stripped of control characters on the way in,
 * and shown as plain text, never as a link.
 *
 * The site URL is readable by whoever holds the archive — Google Drive
 * included. That is stated in docs/help/sauvegarde-portable.md.
 */
final class PortableArchiveHints
{
    public const KIND_MANUAL = 'manual';
    public const KIND_REMOTE = 'remote';

    /** UTC, written « 2026-09-30T21:15:00Z ». */
    private const DATE_FORMAT = 'Y-m-d\TH:i:sp';

    /** Longest value kept for any free-text hint. */
    public const MAX_TEXT_LENGTH = 200;

    /** A ScoutMagic version: `1.2.3`, a pre-release suffix, or `dev`-like labels. */
    private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,31}$/';

    public function __construct(
        public readonly string $version,
        public readonly string $siteUrl,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $kind,
        public readonly ?int $passphraseGeneration,
    ) {
    }

    /**
     * The hints of an archive being written now.
     */
    public static function now(string $version, string $siteUrl, string $kind, ?int $passphraseGeneration): self
    {
        return new self(
            $version,
            $siteUrl,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $kind,
            $kind === self::KIND_REMOTE ? $passphraseGeneration : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scoutmagic_version' => $this->version,
            'site_url' => $this->siteUrl,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT),
            'kind' => $this->kind,
            'passphrase_generation' => $this->passphraseGeneration,
        ];
    }

    /**
     * Reads the hints back from a comment document, refusing an archive
     * that lacks one of them: every field is mandatory in this format.
     *
     * @param array<string, mixed> $document the decoded comment
     * @throws BackupException
     */
    public static function fromArray(array $document): self
    {
        $version = self::text($document['scoutmagic_version'] ?? null);
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            throw self::missing();
        }

        $siteUrl = self::text($document['site_url'] ?? null);
        // `p` reads the trailing « Z » as UTC and writes it back the same,
        // so DateInput's exact round trip holds.
        $createdAt = DateInput::parse(self::DATE_FORMAT, self::text($document['created_at'] ?? null));
        $kind = $document['kind'] ?? null;
        $generation = $document['passphrase_generation'] ?? null;

        if (
            $createdAt === null
            || !in_array($kind, [self::KIND_MANUAL, self::KIND_REMOTE], true)
            || !array_key_exists('site_url', $document)
            || !array_key_exists('passphrase_generation', $document)
            || ($generation !== null && (!is_int($generation) || $generation < 1))
        ) {
            throw self::missing();
        }

        return new self($version, $siteUrl, $createdAt, $kind, $generation);
    }

    /** « Automatique, hors site » or « Manuelle » — the type as an operator reads it. */
    public function kindLabel(): string
    {
        return $this->kind === self::KIND_REMOTE ? 'Automatique, hors site' : 'Manuelle';
    }

    /**
     * A plain-text value, bounded, with every control character removed
     * — the comment is attacker-editable, and these strings end up on a
     * page.
     */
    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value);

        return mb_substr(trim($clean), 0, self::MAX_TEXT_LENGTH);
    }

    private static function missing(): BackupException
    {
        return new BackupException(
            'L\'en-tête de cette sauvegarde portable est incomplet : elle ne dit pas quelle version l\'a écrite, '
            . 'ni d\'où et quand elle vient.'
        );
    }
}
