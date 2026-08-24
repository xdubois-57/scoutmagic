<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Mail\RentalMessageConsumer;
use Modules\Rental\Repository\RentalAggregateRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalReminderRepository;

/**
 * Deleting a booking once its retention has run out (§6.35).
 *
 * **The delay is counted from the close of the accounting year the stay
 * fell in, never from the stay itself.** That is not a detail: it means
 * every letting of one financial year purges together, which is what makes
 * the rule explicable to a unit and auditable afterwards. Counting from
 * each stay would scatter the deletions across the calendar and make "have
 * we kept 2027's paperwork long enough?" unanswerable.
 *
 * **Everything about the person goes.** The booking, its lines, its
 * documents and the files behind them, its tokens, its change history, its
 * meter readings, its
 * inventory, its incidents, its settlements, and the emails
 * `inbound_mail` attached to it, and the receivables it raised in Finance.
 * The database cascades do most of it; the files on disk, the other
 * modules' rows and their messages do not cascade and are removed
 * explicitly here.
 *
 * **One anonymous row stays**: asset, month, days, amount. Without it the
 * year's revenue would drop to zero on the morning the purge ran (§6.34) —
 * and with anything more in it, the "deletion" would not be one.
 *
 * The default retention is **an aid, never legal advice**: seven years is
 * what Belgian bookkeeping obligations generally imply for supporting
 * documents, but what a given unit must keep is a question for the unit.
 */
class RentalRetentionService
{
    public const SETTING_RETENTION_YEARS = 'purge_retention_years';
    public const DEFAULT_RETENTION_YEARS = 7;
    private const MODULE = 'rental';

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalDocumentRepository $documentRepository,
        private RentalAggregateRepository $aggregateRepository,
        private RentalReminderRepository $reminderRepository,
        private SettingService $settingService,
        private JournalService $journal,
        private \PDO $pdo,
        private ?FileRepository $fileRepository = null,
        private ?InboundMailInterface $inboundMail = null,
        private string $storagePath = '',
        private ?RentalPaymentService $payments = null,
        private ?BookingAudit $bookingAudit = null
    ) {
    }

    public function retentionYears(): int
    {
        $configured = (int) ($this->settingService->get(self::SETTING_RETENTION_YEARS, self::MODULE)
            ?: self::DEFAULT_RETENTION_YEARS);

        // A zero or negative retention would delete everything on the next
        // tick, which is never what somebody meant to type.
        return $configured > 0 ? $configured : self::DEFAULT_RETENTION_YEARS;
    }

    /**
     * The last day a stay may have ended and still be purgeable today.
     *
     * Resolved through the accounting years themselves rather than by
     * subtracting years from today: the boundary that matters is the close
     * of the year the stay fell in, and only the year table knows where
     * that is.
     */
    public function cutoffDate(\DateTimeImmutable $today): ?string
    {
        $years = $this->retentionYears();
        $limit = $today->modify('-' . $years . ' years')->format('Y-m-d');

        // The most recent accounting year that closed at least $years ago.
        // Everything ending on or before its own close is out of retention.
        $stmt = $this->pdo->prepare(
            'SELECT end_date FROM scout_years WHERE end_date <= ? ORDER BY end_date DESC LIMIT 1'
        );
        $stmt->execute([$limit]);
        $endDate = $stmt->fetchColumn();

        return $endDate === false ? null : (string) $endDate;
    }

    /**
     * Purge everything out of retention.
     *
     * @return int how many bookings were deleted
     */
    public function purge(\DateTimeImmutable $today): int
    {
        $cutoff = $this->cutoffDate($today);
        if ($cutoff === null) {
            // No accounting year has closed long enough ago. On a young
            // installation that is the honest answer, and deleting nothing
            // is the right behaviour.
            return 0;
        }

        $bookings = $this->bookingRepository->findEndedOnOrBefore($cutoff);
        if ($bookings === []) {
            return 0;
        }

        foreach ($bookings as $booking) {
            $this->purgeBooking($booking);
        }

        // The count and the cutoff, and nothing else — a journal entry
        // about a deletion must not become the last surviving trace of what
        // was deleted (§6.35, SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_bookings_purged',
            'info',
            'Suppression définitive de réservations hors rétention',
            ['count' => count($bookings), 'cutoff' => $cutoff]
        );

        return count($bookings);
    }

    /**
     * One booking: aggregate first, then delete.
     *
     * **That order matters.** A crash between the two leaves an aggregate
     * with a live booking still behind it — a double count on one page,
     * visible and fixable. The other order would lose the letting from the
     * year's figures entirely, silently, and with nothing left to
     * reconstruct it from.
     */
    private function purgeBooking(RentalBooking $booking): void
    {
        $this->aggregateRepository->record(
            $booking->assetId,
            (new \DateTimeImmutable($booking->arrivalDate))->format('Y-m'),
            $this->occupiedDays($booking),
            $booking->effectiveTotalCents() ?? 0,
            $this->scoutYearIdFor($booking->arrivalDate)
        );

        // The other module's messages, and the files behind their
        // attachments. Nothing preserved: a purge is a purge, and an
        // attachment reclassified into a booking document goes with the
        // booking anyway (§7.10).
        $this->inboundMail?->purgeReference(RentalMessageConsumer::CONSUMER_ID, $booking->reference);

        // Files do not cascade — the rows in `files` and the bytes on disk
        // are outside this module's tables entirely.
        //
        // The document rows are deleted here too, explicitly. The schema
        // does declare `ON DELETE CASCADE`, but relying on it for
        // correctness would make this method behave differently depending
        // on the engine underneath, and a document row surviving its file
        // is a broken link rather than a tidy leftover.
        foreach ($this->documentRepository->findForBooking($booking->id) as $document) {
            $this->deleteStoredFile($document->fileId);
            $this->documentRepository->delete($document->id);
        }

        $this->reminderRepository->forgetSubject('booking', $booking->id);

        // Finance lives in its own tables, so nothing about this booking
        // cascades there: without this call the unit keeps being told it is
        // owed money for a stay whose every other trace has just been
        // erased, and the receivable's label carries the renter's name —
        // which would make the purge incomplete in the one way that
        // matters. Null when Finance is disabled, which is a normal
        // installation and not an error.
        $this->payments?->forgetBooking($booking->id);

        // The booking's own change history (§6.15) lives in core's
        // `entity_changes`, which carries no foreign key on purpose
        // (§8.66) — so nothing about it cascades, and a purge that forgot
        // it would leave a timeline of a stay that no longer exists, under
        // an id the next booking will be given.
        $this->bookingAudit?->forget($booking->id);

        // Everything keyed on the booking cascades from here.
        $this->bookingRepository->deleteById($booking->id);
    }

    private function deleteStoredFile(int $fileId): void
    {
        if ($this->fileRepository === null) {
            return;
        }

        $record = $this->fileRepository->findById($fileId);
        if ($record === null) {
            return;
        }

        // The bytes first, then the row. A crash between the two leaves a
        // row pointing at nothing — which reads as a missing file and is
        // recoverable — rather than an orphaned file on disk that no
        // deletion will ever find again.
        if ($this->storagePath !== '') {
            $path = $this->storagePath . '/' . $record->relativePath;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->fileRepository->delete($fileId);
    }

    /**
     * Days the stay occupied, counted the same way everywhere else in the
     * module: arrival to departure inclusive is what a manager sees on a
     * calendar, and a one-night stay is one day here rather than zero.
     */
    private function occupiedDays(RentalBooking $booking): int
    {
        $arrival = new \DateTimeImmutable($booking->arrivalDate);
        $departure = new \DateTimeImmutable($booking->departureDate);

        return max(1, (int) $arrival->diff($departure)->format('%a'));
    }

    private function scoutYearIdFor(string $date): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM scout_years WHERE start_date <= ? AND end_date >= ? LIMIT 1'
        );
        $stmt->execute([$date, $date]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
