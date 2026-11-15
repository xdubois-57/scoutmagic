<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;
use Core\Import\MemberRepository;
use Core\Service\DateInput;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Api\EmailHistoryImportInterface;
use Modules\MassMail\Api\ImportedAttachment;
use Modules\MassMail\Api\ImportedEmail;
use Modules\MassMail\Api\ImportedRecipient;

/**
 * « Historique des courriels » — the outgoing emails of a former site, into
 * mass_mail's history, read-only for ever and sent to nobody (chantier
 * « Migration de données », lot C; format `scoutmagic.email-history`,
 * docs/formats/data-migration/email-history.md).
 *
 * The archive holds one JSON file per email under `communications/`, its
 * bodies under `content/` and its attachments under `attachments/`, each
 * named by its SHA-256. What an email must have is what makes it a message
 * at all — a subject, a content, a date and a sender (spec §6.1); everything
 * else (recipients, statuses, members, list name, attachments) is optional,
 * and an invalid optional part is dropped without costing the email.
 *
 * Everything this class decides is a reading of the archive; everything it
 * writes goes through mass_mail's Api\EmailHistoryImportInterface, which
 * writes no pending message, schedules nothing and notifies nobody. The
 * section an email hangs on (D6), the duplicate check on the provenance and
 * the attachments' accepted types are mass_mail's answers, asked through
 * that interface, so the two modules cannot disagree.
 */
final class EmailHistoryImporter implements Importer
{
    public const FORMAT = 'scoutmagic.email-history';
    public const KIND = 'email';

    private const COMMUNICATIONS = 'communications/';
    private const CONTENT = 'content/';
    private const ATTACHMENTS = 'attachments/';

    /** One communication file, decoded whole. */
    private const MAX_COMMUNICATION_BYTES = 4 * 1024 * 1024;
    /** One body, HTML or text: a newsletter, not a book. */
    private const MAX_CONTENT_BYTES = 2 * 1024 * 1024;
    /** No member of the archive larger than this: past it the whole archive is refused. */
    private const MAX_ENTRY_BYTES = 64 * 1024 * 1024;
    private const MAX_ENTRIES = 200000;
    /** Measured decompressed bytes in one slice — an attachment is read more than once per item. */
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024 * 1024;

    public const TAG_SECTION_FROM_SENDER = 'section_from_sender';
    public const TAG_SECTION_STAFF = 'section_staff';
    public const TAG_MAIL_MERGE = 'mail_merge';
    public const TAG_NO_RECIPIENT = 'no_recipient';
    public const TAG_RECIPIENT_INVALID = 'recipient_invalid';
    public const TAG_MEMBER_UNKNOWN = 'member_unknown';
    public const TAG_ATTACHMENT_INVALID = 'attachment_invalid';

    public function __construct(
        private ?EmailHistoryImportInterface $emails,
        private MemberRepository $members,
        private HtmlSanitizer $sanitizer
    ) {
    }

    public function key(): string
    {
        return 'courriels';
    }

    public function requiredModule(): string
    {
        return 'mass_mail';
    }

    public function title(): string
    {
        return 'Historique des courriels';
    }

    public function description(): string
    {
        return 'Les anciens courriels envoyés, visibles dans l\'historique d\'envoi et sur la fiche des animés '
            . 'concernés, en lecture seule.';
    }

    public function unitLabel(): string
    {
        return 'courriels';
    }

    public function archiveFormat(): ArchiveFormat
    {
        return new ArchiveFormat(
            self::FORMAT,
            1,
            // '' admits the attachments, named by their fingerprint alone.
            new ArchiveLimits(
                self::MAX_ENTRIES,
                self::MAX_ENTRY_BYTES,
                self::MAX_TOTAL_BYTES,
                ['json', 'html', 'txt', ''],
            )
        );
    }

    public function items(ImportSource $source, int $from): iterable
    {
        $files = self::communicationFiles($source);
        for ($position = max(0, $from), $count = count($files); $position < $count; $position++) {
            $file = $files[$position];
            $data = $source->jsonObject($file, self::MAX_COMMUNICATION_BYTES);
            if ($data === null) {
                yield $position => SourceItem::unreadable(self::KIND, $file, 'Fichier illisible');
                continue;
            }
            $id = SourceItem::idFrom($data['id'] ?? null);
            yield $position => new SourceItem(self::KIND, $id ?? '', $data, $id ?? $file);
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        $prepared = $this->prepare($item, $context, false);

        return $prepared['verdict'];
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        $prepared = $this->prepare($item, $context, true);
        try {
            $email = $prepared['email'];
            if ($email === null || $this->emails === null) {
                throw new \LogicException('An email the inspection did not accept reached write().');
            }
            $result = $this->emails->import($email);
        } finally {
            foreach ($prepared['files'] as $path) {
                @unlink($path);
            }
        }

        return new WrittenTarget('mass_mail_email', (string) $result->emailId);
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];

        $bySender = $summary->tag(self::TAG_SECTION_FROM_SENDER);
        $byStaff = $summary->tag(self::TAG_SECTION_STAFF);
        if ($bySender > 0) {
            $notes[] = PreviewNote::info(sprintf(
                '%s rattaché%s à une section d\'après %s adresse d\'expédition, %d au Staff d\'U. '
                . 'L\'adresse et le nom d\'origine restent affichés tels quels.',
                self::count($bySender, 'courriel est', 'courriels sont'),
                $bySender > 1 ? 's' : '',
                $bySender > 1 ? 'leur' : 'son',
                $byStaff
            ));
        } elseif ($byStaff > 0) {
            $notes[] = PreviewNote::info(sprintf(
                '%s rattaché%s au Staff d\'U : aucune adresse d\'expédition n\'est celle d\'une section. '
                . 'L\'adresse et le nom d\'origine restent affichés tels quels.',
                self::count($byStaff, 'courriel est', 'courriels sont'),
                $byStaff > 1 ? 's' : ''
            ));
        }
        if (($merges = $summary->tag(self::TAG_MAIL_MERGE)) > 0) {
            $notes[] = PreviewNote::info(sprintf(
                '%s importé%s avec %s texte modèle, sans les versions personnalisées.',
                self::count($merges, 'publipostage est', 'publipostages sont'),
                $merges > 1 ? 's' : '',
                $merges > 1 ? 'leur' : 'son'
            ));
        }
        if (($none = $summary->tag(self::TAG_NO_RECIPIENT)) > 0) {
            $notes[] = PreviewNote::info(sprintf(
                '%s aucun destinataire connu. %s importé%s quand même, sans destinataire inventé.',
                self::count($none, 'courriel n\'a', 'courriels n\'ont'),
                $none > 1 ? 'Ils sont' : 'Il est',
                $none > 1 ? 's' : ''
            ));
        }
        if (($unknown = $summary->tag(self::TAG_MEMBER_UNKNOWN)) > 0) {
            $notes[] = PreviewNote::info(sprintf(
                '%s à aucun membre du site : %s ignoré%s, sans créer de membre.',
                self::count($unknown, 'identifiant Desk ne correspond', 'identifiants Desk ne correspondent'),
                $unknown > 1 ? 'ces liens sont' : 'ce lien est',
                $unknown > 1 ? 's' : ''
            ));
        }
        if (($invalid = $summary->tag(self::TAG_RECIPIENT_INVALID)) > 0) {
            $notes[] = PreviewNote::warning(sprintf(
                '%s : %s pas reprise%s. Le reste du courriel est importé.',
                self::count(
                    $invalid,
                    'adresse de destinataire est invalide',
                    'adresses de destinataire sont invalides',
                ),
                $invalid > 1 ? 'elles ne sont' : 'elle n\'est',
                $invalid > 1 ? 's' : ''
            ));
        }
        if (($attachments = $summary->tag(self::TAG_ATTACHMENT_INVALID)) > 0) {
            $notes[] = PreviewNote::warning(sprintf(
                '%s (empreinte, type ou taille). Leur courriel est importé sans elles.',
                self::count($attachments, 'pièce jointe est invalide', 'pièces jointes sont invalides')
            ));
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Aucun courriel n\'est envoyé ni renvoyé.',
            'Les courriels importés ne peuvent être ni modifiés, ni supprimés, ni renvoyés.',
            'Les statuts restent ceux d\'origine : « Accepté par le serveur d\'envoi » ne devient jamais '
                . '« Remis ».',
        ];
    }

    /**
     * Reads one communication: the verdict, and — when `$withFiles` — the
     * email to write with its attachments copied to temporary files the
     * caller removes. The inspection and the writing read the same way, so
     * an email cannot pass the one and be shaped differently by the other.
     *
     * @return array{verdict: ItemVerdict, email: ?ImportedEmail, files: list<string>}
     */
    private function prepare(SourceItem $item, ImportContext $context, bool $withFiles): array
    {
        $ignored = static fn (string $reason): array => [
            'verdict' => ItemVerdict::ignored($reason), 'email' => null, 'files' => [],
        ];
        if ($this->emails === null) {
            return $ignored('Module « Envoi de mails » indisponible');
        }
        $data = $item->data;

        $subject = is_string($data['subject'] ?? null) ? trim($data['subject']) : '';
        if ($subject === '') {
            return $ignored('Sans sujet');
        }
        if (mb_strlen($subject) > 255) {
            return $ignored('Sujet de plus de 255 caractères');
        }

        if (!isset($data['sent_at']) || $data['sent_at'] === '') {
            return $ignored('Sans date d\'envoi');
        }
        $sentAt = self::moment($data['sent_at']);
        if ($sentAt === null) {
            return $ignored('Date d\'envoi illisible');
        }
        if ($sentAt > new \DateTimeImmutable()) {
            return $ignored('Date d\'envoi dans le futur');
        }

        $sender = is_array($data['sender'] ?? null) ? $data['sender'] : [];
        $senderAddress = is_string($sender['address'] ?? null) ? trim($sender['address']) : '';
        if ($senderAddress === '') {
            return $ignored('Sans expéditeur');
        }
        if (filter_var($senderAddress, FILTER_VALIDATE_EMAIL) === false) {
            return $ignored('Adresse d\'expéditeur invalide');
        }

        [$body, $bodyRefusal] = $this->body($context->source, is_array($data['body'] ?? null) ? $data['body'] : []);
        if ($bodyRefusal !== null) {
            return $ignored($bodyRefusal);
        }
        if ($body === null) {
            return $ignored('Sans contenu');
        }

        $section = $this->emails->senderSection($senderAddress);
        if ($section === null) {
            return $ignored('Section « Staff d\'U » absente du site');
        }

        $importSource = self::importSource($context->installationId, $item->sourceId);
        if ($this->emails->findImported($importSource) !== null) {
            return ['verdict' => ItemVerdict::present(), 'email' => null, 'files' => []];
        }

        $tags = [$section->fromSenderAddress ? self::TAG_SECTION_FROM_SENDER : self::TAG_SECTION_STAFF];
        if (($data['mail_merge'] ?? false) === true) {
            $tags[] = self::TAG_MAIL_MERGE;
        }

        [$recipients, $recipientTags] = $this->recipients($data['recipients'] ?? null);
        [$memberIds, $memberTags] = $this->memberIds($data['desk_ids'] ?? null);
        array_push($tags, ...$recipientTags, ...$memberTags);
        if ($recipients === []) {
            $tags[] = self::TAG_NO_RECIPIENT;
        }

        [$attachments, $files, $attachmentTags] = $this->attachments(
            $context->source,
            $data['attachments'] ?? null,
            $withFiles
        );
        array_push($tags, ...$attachmentTags);

        $email = null;
        if ($withFiles) {
            $email = new ImportedEmail(
                $importSource,
                $subject,
                $body,
                $sentAt,
                $senderAddress,
                is_string($sender['name'] ?? null) ? $sender['name'] : null,
                is_string($data['audience_name'] ?? null) ? $data['audience_name'] : null,
                $recipients,
                $memberIds,
                $attachments,
                $context->userAccountId
            );
        }

        return ['verdict' => ItemVerdict::import()->tagged(...$tags), 'email' => $email, 'files' => $files];
    }

    /**
     * The body to keep, as HTML: the HTML version when it says anything once
     * sanitized — the version actually sent (spec §6.1) — else the text
     * version, escaped. Returned with no reason, the body is null when
     * neither says anything; with a reason, the email is ignored. Two
     * values, never a marker in the body: an HTML body may start with any
     * character, and its text must never become a reason in the report.
     *
     * @param array<string, mixed> $body
     * @return array{0: ?string, 1: ?string} the body, and the reason to ignore the email
     */
    private function body(ImportSource $source, array $body): array
    {
        foreach (['html', 'text'] as $kind) {
            if (!isset($body[$kind])) {
                continue;
            }
            $path = $body[$kind];
            if (!is_string($path) || !str_starts_with($path, self::CONTENT) || !$source->archive()->has($path)) {
                return [null, 'Contenu introuvable dans l\'archive'];
            }
            // Streamed and kept only up to the ceiling: a body past it is
            // one email ignored, not a whole archive refused, and never
            // held whole in memory.
            $bytes = '';
            $source->archive()->stream($path, static function (string $chunk) use (&$bytes): void {
                if (strlen($bytes) <= self::MAX_CONTENT_BYTES) {
                    $bytes .= $chunk;
                }
            });
            if (strlen($bytes) > self::MAX_CONTENT_BYTES) {
                return [null, 'Contenu de plus de 2 Mio'];
            }
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                return [null, 'Contenu qui n\'est pas en UTF-8'];
            }
            $html = $kind === 'html'
                ? $bytes
                : '<p>' . nl2br(htmlspecialchars(trim($bytes), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>';
            if (self::hasContent($this->sanitizer->sanitize($html))) {
                return [$html, null];
            }
        }

        return [null, null];
    }

    /**
     * The addresses the former site knew, each with its status. A malformed
     * entry is dropped and counted, never the email.
     *
     * @return array{0: list<ImportedRecipient>, 1: list<string>}
     */
    private function recipients(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            return [[], []];
        }

        $recipients = [];
        $tags = [];
        foreach ($list as $entry) {
            $address = is_array($entry) && is_string($entry['address'] ?? null) ? trim($entry['address']) : '';
            if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $tags[] = self::TAG_RECIPIENT_INVALID;
                continue;
            }
            $status = $entry['status'] ?? null;
            $status = in_array($status, [ImportedRecipient::ACCEPTED, ImportedRecipient::FAILED], true)
                ? $status
                : ImportedRecipient::UNKNOWN;
            [$memberIds, $memberTags] = $this->memberIds($entry['desk_ids'] ?? null);
            array_push($tags, ...$memberTags);
            $recipients[] = new ImportedRecipient(
                $address,
                $status,
                isset($entry['sent_at']) ? self::moment($entry['sent_at']) : null,
                $memberIds
            );
        }

        return [$recipients, $tags];
    }

    /**
     * Members by Desk id, merge aliases included (`findByDeskId()`). An id
     * this site does not know drops the link and creates nobody.
     *
     * @return array{0: list<int>, 1: list<string>}
     */
    private function memberIds(mixed $deskIds): array
    {
        if (!is_array($deskIds) || !array_is_list($deskIds)) {
            return [[], []];
        }

        $ids = [];
        $tags = [];
        foreach ($deskIds as $deskId) {
            $deskId = SourceItem::idFrom($deskId);
            $member = $deskId !== null ? $this->members->findByDeskId($deskId) : null;
            if ($member === null) {
                $tags[] = self::TAG_MEMBER_UNKNOWN;
                continue;
            }
            $ids[$member['id']] = $member['id'];
        }

        return [array_values($ids), $tags];
    }

    /**
     * The attachments that are what they claim: present, of the declared
     * fingerprint, not larger than mass_mail accepts and of a type it
     * accepts by their real content. Each one is streamed out of the
     * archive into a temporary file and hashed on the way, never held whole
     * in memory; at the inspection the file is removed at once.
     *
     * @return array{0: list<ImportedAttachment>, 1: list<string>, 2: list<string>}
     */
    private function attachments(ImportSource $source, mixed $list, bool $keep): array
    {
        if (!is_array($list) || !array_is_list($list) || $this->emails === null) {
            return [[], [], []];
        }

        $attachments = [];
        $files = [];
        $tags = [];
        $seen = [];
        foreach ($list as $entry) {
            $path = is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : '';
            $declared = is_array($entry) && is_string($entry['sha256'] ?? null) ? $entry['sha256'] : '';
            if (!str_starts_with($path, self::ATTACHMENTS) || preg_match('/^[0-9a-f]{64}$/', $declared) !== 1
                || !$source->archive()->has($path)
            ) {
                $tags[] = self::TAG_ATTACHMENT_INVALID;
                continue;
            }
            // A file of attachments/ is named by its content: cited twice,
            // it is the same attachment, joined once. That also bounds what
            // one email keeps extracted at once by the archive's own
            // distinct files, however many times an entry repeats one. Only
            // a SUCCESSFUL extraction counts: a first citation with a wrong
            // fingerprint must not cost a later, correct one.
            if (isset($seen[$path])) {
                continue;
            }

            $temporary = $this->extract($source, $path, $declared);
            if ($temporary === null) {
                $tags[] = self::TAG_ATTACHMENT_INVALID;
                continue;
            }
            $seen[$path] = true;
            if (!$keep) {
                @unlink($temporary);
                continue;
            }

            $name = is_string($entry['filename'] ?? null) && trim($entry['filename']) !== ''
                ? basename(str_replace('\\', '/', trim($entry['filename'])))
                : 'piece-jointe';
            $attachments[] = new ImportedAttachment($temporary, $name);
            $files[] = $temporary;
        }

        return [$attachments, $files, $tags];
    }

    /**
     * The member in a temporary file, or null — and nothing left behind —
     * when its bytes are not the declared ones, are too large, or are of a
     * type mass_mail refuses.
     */
    private function extract(ImportSource $source, string $path, string $declared): ?string
    {
        \assert($this->emails !== null);
        $temporary = tempnam(sys_get_temp_dir(), 'sm_mail_');
        if ($temporary === false) {
            return null;
        }
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);

            return null;
        }

        $hash = hash_init('sha256');
        $size = 0;
        $complete = true;
        $limit = $this->emails->maxAttachmentBytes();
        try {
            $source->archive()->stream($path, static function (string $chunk) use (
                $handle,
                $hash,
                &$size,
                &$complete,
                $limit,
            ): void {
                $size += strlen($chunk);
                hash_update($hash, $chunk);
                if ($size <= $limit && $complete) {
                    $complete = self::writeAll($handle, $chunk);
                }
            });
        } finally {
            fclose($handle);
        }

        // The hash is of the archive's bytes, not of the file's: a short
        // write (a full disk) leaves a file the fingerprint never saw.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (!$complete || $size > $limit || !hash_equals($declared, hash_final($hash))
            || !in_array($mime, $this->emails->acceptedAttachmentTypes(), true)
        ) {
            @unlink($temporary);

            return null;
        }

        return $temporary;
    }

    /**
     * Every byte of $chunk, or false: fwrite() may write fewer bytes than
     * asked, and answers 0 or false once it can write no more.
     *
     * @param resource $handle
     */
    private static function writeAll($handle, string $chunk): bool
    {
        while ($chunk !== '') {
            $written = fwrite($handle, $chunk);
            if ($written === false || $written === 0) {
                return false;
            }
            $chunk = substr($chunk, $written);
        }

        return true;
    }

    /**
     * mass_mail's provenance key: the installation prefixed with its length
     * in bytes, then a line feed and the source id. Both may hold any byte,
     * a line feed included, so the length is what keeps `A\nB` + `C` from
     * reading as `A` + `B\nC`. At most 3 + 1 + 190 + 1 + 190 = 385 bytes.
     */
    private static function importSource(string $installationId, string $sourceId): string
    {
        return strlen($installationId) . ':' . $installationId . "\n" . $sourceId;
    }

    /**
     * The communication files in a stable order — byte order of their
     * names, whatever order the ZIP stores them in.
     *
     * @return list<string>
     */
    private static function communicationFiles(ImportSource $source): array
    {
        $files = array_values(array_filter(
            $source->archive()->names(),
            static fn (string $name): bool => str_starts_with($name, self::COMMUNICATIONS)
                && str_ends_with(strtolower($name), '.json')
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * An ISO 8601 moment WITH its offset (conventions § 7): without one, the
     * hour could be any of two dozen.
     */
    private static function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1
        ) {
            return null;
        }
        // The shape above already demands a calendar date and an offset;
        // fromStorage() refuses what still does not parse rather than
        // answering « now ».
        return DateInput::fromStorage($value);
    }

    /**
     * Whether a sanitized body still says anything: some text, or an image.
     * The same rule as mass_mail's own (Service\EmailHistoryImportService),
     * written here because that service is not part of its `Api\`.
     */
    private static function hasContent(string $sanitizedHtml): bool
    {
        $text = html_entity_decode(strip_tags($sanitizedHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text, " \t\n\r\0\x0B\u{00A0}") !== '' || stripos($sanitizedHtml, '<img') !== false;
    }

    private static function count(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count > 1 ? $plural : $singular);
    }
}
