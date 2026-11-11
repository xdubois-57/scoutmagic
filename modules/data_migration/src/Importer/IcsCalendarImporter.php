<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Http\UrlMetadataFetcher;
use Core\Service\DateInput;
use Core\Service\IntegerInput;
use Modules\Calendar\Api\CalendarImportWriterInterface;
use Modules\Calendar\Api\ImportedEvent;
use Modules\DataMigration\Ics\IcsEventReader;
use Modules\DataMigration\Ics\IcsUnreadableException;

/**
 * « Calendrier (fichier ICS) »: a one-off copy of the events of an online
 * calendar into one of this site's calendars (specifications.md §49).
 *
 * The only import whose source is not a ZIP but a form: an address, the
 * destination calendar (one the administrator may write to) and the date
 * from which events are copied. acceptSource() downloads the file through
 * UrlMetadataFetcher — the installation's one way out to an address
 * somebody typed (SECURITY.md §17) — and IcsEventReader turns it into one
 * JSON line per event in the run's workspace. Everything after that is the
 * module's shared path, reading those lines.
 *
 * The deduplication key is (hash of the address, `ics`, `event`, UID): the
 * same address imported again finds its events « déjà présents ». The
 * address itself is not kept — it may carry a private calendar's secret —
 * only its hash and the host it pointed to.
 */
final class IcsCalendarImporter implements FormSourceImporter
{
    public const KEY = 'calendrier';
    public const FORMAT = 'ics';
    public const KIND = 'event';
    public const TARGET_TYPE = 'calendar_event';
    public const EVENTS_FILE = 'events.jsonl';
    public const MAX_ADDRESS_LENGTH = 2000;

    public const REASON_RECURRING = 'Événement récurrent, exclu';
    public const REASON_CANCELLED = 'Événement annulé';
    public const REASON_NOT_WRITABLE = 'Calendrier de destination non modifiable';
    public const UNREADABLE_PREFIX = 'Illisible : ';
    public const BEFORE_PREFIX = 'Antérieur au ';
    private const TAG_DESTINATION = 'destination:';

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private ?CalendarImportWriterInterface $calendars,
        private UrlMetadataFetcher $fetcher,
        private IcsEventReader $reader = new IcsEventReader(),
        private ?\Closure $clock = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function requiredModule(): string
    {
        return 'calendar';
    }

    public function title(): string
    {
        return 'Calendrier (fichier ICS)';
    }

    public function description(): string
    {
        return 'Une copie unique des événements d\'un calendrier en ligne, dans un calendrier de ScoutMagic.';
    }

    public function unitLabel(): string
    {
        return 'événements';
    }

    public function archiveFormat(): ?ArchiveFormat
    {
        return null;
    }

    public function sourceFormTemplate(): string
    {
        return '@data_migration/sources/ics.html.twig';
    }

    public function sourceFormContext(int $userAccountId): array
    {
        return [
            'ics_calendars' => $this->writer()->writableCalendars($userAccountId),
            'ics_default_min_date' => $this->defaultMinDate(),
        ];
    }

    /** Today minus two years, the date the form proposes (specification §5.1). */
    public function defaultMinDate(): string
    {
        return $this->now()->modify('-2 years')->format('Y-m-d');
    }

    public function acceptSource(array $fields, string $workspace, int $userAccountId): AcceptedSource
    {
        $address = self::normalisedAddress((string) ($fields['adresse'] ?? ''));
        $host = $address !== null ? parse_url($address, PHP_URL_HOST) : null;
        if ($address === null || !is_string($host) || $host === '') {
            throw new SourceRejectedException(
                'Indiquez l\'adresse complète du calendrier, qui commence par https://, http:// ou webcal://.'
            );
        }

        $calendarId = IntegerInput::id($fields['calendrier'] ?? null);
        $calendar = null;
        foreach ($this->writer()->writableCalendars($userAccountId) as $candidate) {
            if ($candidate->id === $calendarId) {
                $calendar = $candidate;
            }
        }
        if ($calendar === null) {
            throw new SourceRejectedException('Choisissez un calendrier de destination que vous pouvez modifier.');
        }

        $minDate = trim((string) ($fields['date_minimale'] ?? ''));
        if (!DateInput::isIso($minDate)) {
            throw new SourceRejectedException('Indiquez une date valide à partir de laquelle importer les événements.');
        }

        $body = $this->fetcher->fetchCalendar($address);
        if ($body === null) {
            throw new SourceRejectedException(
                'Le calendrier n\'a pas pu être téléchargé. Vérifiez que l\'adresse est publique, '
                . 'qu\'elle mène bien à un fichier ICS (et non à une page web) et que ce fichier fait au plus 5 Mo.'
            );
        }

        $raw = $workspace . '/source.ics';
        $events = $workspace . '/' . self::EVENTS_FILE;
        if (file_put_contents($raw, $body) === false) {
            throw new \RuntimeException('Cannot write the downloaded calendar.');
        }
        unset($body);

        try {
            $this->reader->convert($raw, $events);
        } catch (IcsUnreadableException) {
            throw new SourceRejectedException(
                'Ce fichier n\'est pas un calendrier ICS lisible, ou il ne contient aucun événement.'
            );
        } finally {
            @unlink($raw);
        }

        return new AcceptedSource(
            hash('sha256', $address),
            self::FORMAT,
            ['calendar_id' => $calendar->id, 'calendar_label' => $calendar->label, 'min_date' => $minDate],
            $events,
            'Le calendrier de ' . strtolower($host)
        );
    }

    public function items(ImportSource $source, int $from): iterable
    {
        $file = $source->filePath !== null ? @fopen($source->filePath, 'rb') : false;
        if ($file === false) {
            throw new \RuntimeException('The converted calendar is missing.');
        }

        try {
            $position = 0;
            while (($line = fgets($file)) !== false) {
                if ($position >= $from) {
                    yield $position => self::item($position, $line);
                }
                $position++;
            }
        } finally {
            fclose($file);
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        if ($item->isUnreadable()) {
            return ItemVerdict::ignored((string) $item->unreadableReason);
        }
        $state = $item->data['state'] ?? null;
        if ($state === IcsEventReader::STATE_RECURRING) {
            return ItemVerdict::ignored(self::REASON_RECURRING);
        }
        if ($state === IcsEventReader::STATE_CANCELLED) {
            return ItemVerdict::ignored(self::REASON_CANCELLED);
        }

        $parameters = $context->source->parameters;
        $minDate = (string) ($parameters['min_date'] ?? '');
        if ($minDate !== '' && (string) ($item->data['start_date'] ?? '') < $minDate) {
            return ItemVerdict::ignored(self::BEFORE_PREFIX . self::frenchDate($minDate) . ' (date minimale)');
        }

        $calendarId = (int) ($parameters['calendar_id'] ?? 0);
        if ($context->userAccountId === null || !$this->writer()->canWriteTo($context->userAccountId, $calendarId)) {
            return ItemVerdict::ignored(self::REASON_NOT_WRITABLE);
        }

        return ItemVerdict::import()->tagged(self::TAG_DESTINATION . (string) ($parameters['calendar_label'] ?? ''));
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        if ($context->userAccountId === null) {
            throw new \LogicException('An ICS import runs for an account.');
        }
        $data = $item->data;
        $id = $this->writer()->createImportedEvent(
            $context->userAccountId,
            (int) ($context->source->parameters['calendar_id'] ?? 0),
            new ImportedEvent(
                (string) ($data['title'] ?? ''),
                (string) ($data['start_date'] ?? ''),
                self::stringOrNull($data['end_date'] ?? null),
                self::stringOrNull($data['start_time'] ?? null),
                self::stringOrNull($data['end_time'] ?? null),
                self::stringOrNull($data['location'] ?? null),
                self::stringOrNull($data['description'] ?? null)
            )
        );

        return new WrittenTarget(self::TARGET_TYPE, (string) $id);
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];
        foreach ($summary->tags as $tag => $count) {
            if (str_starts_with($tag, self::TAG_DESTINATION)) {
                $notes[] = PreviewNote::info(sprintf(
                    $count > 1
                        ? '%d événements ajoutés au calendrier « %s ».'
                        : '%d événement ajouté au calendrier « %s ».',
                    $count,
                    substr($tag, strlen(self::TAG_DESTINATION))
                ));
            }
        }
        $notes[] = PreviewNote::info(
            'Les heures sont converties à l\'heure de Bruxelles. '
            . 'Les événements sans heure deviennent « toute la journée ».'
        );

        $before = 0;
        $unreadable = 0;
        foreach ($summary->ignoredReasons as $reason => $count) {
            if (str_starts_with($reason, self::BEFORE_PREFIX)) {
                $before += $count;
            } elseif (str_starts_with($reason, self::UNREADABLE_PREFIX)) {
                $unreadable += $count;
            }
        }
        if ($before > 0) {
            $notes[] = PreviewNote::info($before > 1
                ? $before . ' événements antérieurs à la date minimale : non importés.'
                : '1 événement antérieur à la date minimale : non importé.');
        }
        $recurring = $summary->ignoredReasons[self::REASON_RECURRING] ?? 0;
        if ($recurring > 0) {
            $notes[] = PreviewNote::warning(($recurring > 1
                ? $recurring . ' événements récurrents ou occurrences modifiées : exclus.'
                : '1 événement récurrent ou occurrence modifiée : exclu.') . ' Recréez-les dans ScoutMagic si besoin.');
        }
        if ($unreadable > 0) {
            $notes[] = PreviewNote::warning($unreadable > 1
                ? $unreadable . ' événements illisibles ou incomplets : ignorés.'
                : '1 événement illisible ou incomplet : ignoré.');
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Les événements sont copiés une fois dans le calendrier choisi : ce n\'est pas un abonnement.',
            'Aucune notification, aucun rappel pour les événements passés.',
            'Relancé plus tard avec la même adresse, l\'import ne recrée pas ces événements.',
        ];
    }

    /**
     * The address as typed, cleaned: `webcal://` is what calendar apps
     * show for a subscription and means https; anything that is not then an
     * http(s) URL is null.
     */
    public static function normalisedAddress(string $address): ?string
    {
        $address = trim($address);
        if ($address === '' || strlen($address) > self::MAX_ADDRESS_LENGTH) {
            return null;
        }
        if (preg_match('#^webcals?://#i', $address) === 1) {
            $address = 'https://' . (string) preg_replace('#^webcals?://#i', '', $address);
        }
        if (preg_match('#^https?://#i', $address) !== 1 || filter_var($address, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $address;
    }

    private static function item(int $position, string $line): SourceItem
    {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            return SourceItem::unreadable(
                self::KIND,
                'événement n° ' . ($position + 1),
                self::UNREADABLE_PREFIX . 'ligne illisible'
            );
        }
        $uid = (string) ($row['uid'] ?? '');
        $reference = self::reference($position, $uid, is_string($row['date'] ?? null) ? $row['date'] : null);

        if (($row['state'] ?? null) === IcsEventReader::STATE_UNREADABLE) {
            $reason = (string) ($row['reason'] ?? 'événement incomplet');

            return SourceItem::unreadable(self::KIND, $reference, self::UNREADABLE_PREFIX . $reason);
        }
        if ($uid === '') {
            // A recurring or cancelled event without a UID: nothing will be
            // written, the key only needs to be stable and unique.
            $uid = 'position:' . $position;
        }

        return new SourceItem(self::KIND, self::sourceId($uid), $row, $reference);
    }

    /**
     * The UID as the deduplication key's source id: itself up to the
     * column's 190 bytes, a hash of it beyond — still one id per UID.
     */
    public static function sourceId(string $uid): string
    {
        return strlen($uid) <= SourceItem::MAX_ID_BYTES ? $uid : 'sha256:' . hash('sha256', $uid);
    }

    /**
     * What the preview and the report show: the day and the UID, never the
     * title, which may name somebody (D8).
     */
    private static function reference(int $position, string $uid, ?string $date): string
    {
        $parts = [];
        if ($date !== null && DateInput::isIso($date)) {
            $parts[] = self::frenchDate($date);
        }
        $parts[] = $uid !== '' ? $uid : 'événement n° ' . ($position + 1);

        return implode(' · ', $parts);
    }

    private static function frenchDate(string $isoDate): string
    {
        $date = DateInput::iso($isoDate);

        return $date !== null ? $date->format('d/m/Y') : $isoDate;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function writer(): CalendarImportWriterInterface
    {
        return $this->calendars ?? throw new \LogicException('The calendar module is not enabled.');
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null
            ? ($this->clock)()
            : new \DateTimeImmutable('now', new \DateTimeZone(IcsEventReader::TIME_ZONE));
    }
}
