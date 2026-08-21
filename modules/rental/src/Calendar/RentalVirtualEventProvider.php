<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Calendar;

use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Api\VirtualEventProviderInterface;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlock;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalAuthorizationService;

/**
 * Publishes rental occupancy onto the calendar as **virtual events**
 * (§6.30, §6.31) — nothing is ever written to `calendar_events`, so the
 * booking stays the single source of truth and the two can never disagree.
 *
 * **The privacy rule is not configurable, and it is applied before anything
 * is serialised.** A reader who may not see the detail never has it built:
 *
 * | Reader | What is constructed |
 * |---|---|
 * | Ordinary calendar reader | `Local Saint-Georges — loué` and nothing else |
 * | Manager of that asset, or unit staff | organisation, head count, contact, link |
 *
 * That distinction lives in two separate builders below rather than in one
 * builder with a flag, precisely so there is no "detailed version" object
 * in existence for a template, a JSON payload, a tooltip or an ICS line to
 * leak. A field that is never built cannot escape.
 *
 * **Rights are resolved once per generation** (§6.31), at the top of
 * `findVirtualEvents()`, never per booking: a month view carries dozens of
 * bars and a feed hundreds, and re-deriving "may this person manage this
 * asset?" for each of them would turn one page into hundreds of queries.
 *
 * **Two queries for a whole window**, whatever it contains: one for the
 * bookings of every publishing asset, one for the blocks. Never one per day
 * and never one per asset.
 */
class RentalVirtualEventProvider implements VirtualEventProviderInterface
{
    /**
     * What an ordinary reader sees instead of a renter's identity. Not a
     * placeholder to be filled in later: it is the whole of the public
     * rendering (§6.30).
     */
    private const PUBLIC_LET_SUFFIX = ' — loué';
    private const PUBLIC_BLOCKED_SUFFIX = ' — indisponible';

    public function __construct(
        private RentalAssetRepository $assetRepository,
        private RentalBookingRepository $bookingRepository,
        private RentalBlockRepository $blockRepository,
        private RentalAuthorizationService $authorizationService,
        private string $baseUrl = ''
    ) {
    }

    public function providerId(): string
    {
        return 'rental';
    }

    /**
     * @return VirtualEvent[]
     */
    public function findVirtualEvents(
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        VirtualEventViewer $viewer
    ): array {
        $assets = $this->publishableAssetsFor($viewer);
        if ($assets === []) {
            return [];
        }

        $assetIds = array_map(static fn(RentalAsset $asset) => $asset->id, $assets);
        $from = $windowStart->format('Y-m-d');
        $to = $windowEnd->format('Y-m-d');

        // Resolved ONCE for the whole generation, never per booking.
        $manageableAssetIds = $this->manageableAssetIdsFor($viewer, $assetIds);

        $byId = [];
        foreach ($assets as $asset) {
            $byId[$asset->id] = $asset;
        }

        $now = new \DateTimeImmutable();
        $events = [];

        foreach ($this->bookingRepository->findForAssetsBetween($assetIds, $from, $to) as $booking) {
            $asset = $byId[$booking->assetId] ?? null;
            if ($asset === null) {
                continue;
            }

            $event = $this->bookingEvent(
                $booking,
                $asset,
                in_array($asset->id, $manageableAssetIds, true),
                $now
            );

            if ($event !== null) {
                $events[] = $event;
            }
        }

        foreach ($this->blockRepository->findForAssetsBetween($assetIds, $from, $to) as $block) {
            $asset = $byId[$block->assetId] ?? null;
            if ($asset !== null) {
                $events[] = $this->blockEvent($block, $asset, in_array($asset->id, $manageableAssetIds, true));
            }
        }

        return $events;
    }

    /**
     * The assets whose occupancy this reader's calendars actually carry.
     *
     * Filtering on the viewer's calendars matters: without it, a bare feed
     * for the Animateurs calendar would quietly carry the occupancy of an
     * asset published onto a different one.
     *
     * @return RentalAsset[]
     */
    private function publishableAssetsFor(VirtualEventViewer $viewer): array
    {
        return array_values(array_filter(
            $this->assetRepository->findPublishingToCalendar(),
            static fn(RentalAsset $asset) => $viewer->seesCalendar($asset->calendarId)
        ));
    }

    /**
     * Which of $assetIds this reader may see in detail — **one resolution
     * for the whole generation** (§6.31).
     *
     * An anonymous reader gets an empty list without a single query: there
     * is no identity to resolve, and asking anyway would be a database
     * round trip to reach a foregone conclusion.
     *
     * @param int[] $assetIds
     * @return int[]
     */
    private function manageableAssetIdsFor(VirtualEventViewer $viewer, array $assetIds): array
    {
        if (!$viewer->isIdentified()) {
            return [];
        }

        $manageable = array_map(
            static fn(RentalAsset $asset) => $asset->id,
            $this->authorizationService->listManageableAssets($viewer->email, $viewer->scoutYearId)
        );

        return array_values(array_intersect($assetIds, $manageable));
    }

    /**
     * One booking, rendered for exactly one kind of reader.
     *
     * Returns null for a booking that is not published at all — one that
     * has not reached the asset's chosen starting point, and was never
     * announced to anybody. A booking that WAS published and has since been
     * cancelled comes back marked cancelled instead (§6.32): a subscriber
     * who already has it must be told it is off, and dropping it from the
     * feed would leave it in their calendar forever.
     */
    private function bookingEvent(
        RentalBooking $booking,
        RentalAsset $asset,
        bool $viewerManagesAsset,
        \DateTimeImmutable $now
    ): ?VirtualEvent {
        $isCancelled = !$booking->status->occupiesTheAsset();

        if (!$isCancelled && !$asset->calendarPublishFrom->covers($booking, $now)) {
            return null;
        }

        if ($isCancelled && !$booking->status->isFinal()) {
            return null;
        }

        return $viewerManagesAsset
            ? $this->detailedBookingEvent($booking, $asset, $isCancelled)
            : $this->publicBookingEvent($booking, $asset, $isCancelled);
    }

    /**
     * What an ordinary reader gets: the asset's name and the fact that it
     * is let. **Nothing about the renter is assembled here at all.**
     */
    private function publicBookingEvent(
        RentalBooking $booking,
        RentalAsset $asset,
        bool $isCancelled
    ): VirtualEvent {
        return new VirtualEvent(
            uid: self::bookingUid($booking->id),
            calendarId: (int) $asset->calendarId,
            title: $asset->name . self::PUBLIC_LET_SUFFIX,
            startDate: $booking->arrivalDate,
            endDate: $booking->departureDate,
            startTime: self::timeOrNull($asset->arrivalTime),
            endTime: self::timeOrNull($asset->departureTime),
            location: $asset->name,
            description: null,
            url: null,
            isCancelled: $isCancelled,
            sequence: $isCancelled ? 1 : 0
        );
    }

    /**
     * What a manager of this asset gets — and only they.
     */
    private function detailedBookingEvent(
        RentalBooking $booking,
        RentalAsset $asset,
        bool $isCancelled
    ): VirtualEvent {
        $who = $booking->renterOrganisation ?? $booking->renterName;

        $details = [$booking->reference, $booking->status->label()];
        if ($booking->estimatedPersons !== null) {
            $details[] = $booking->estimatedPersons . ' participants';
        }
        $details[] = 'Contact : ' . $booking->renterName;
        if ($booking->renterPhone !== null) {
            $details[] = $booking->renterPhone;
        }
        $details[] = $booking->renterEmail;

        return new VirtualEvent(
            uid: self::bookingUid($booking->id),
            calendarId: (int) $asset->calendarId,
            title: $asset->name . ' — ' . $who,
            startDate: $booking->arrivalDate,
            endDate: $booking->departureDate,
            startTime: self::timeOrNull($asset->arrivalTime),
            endTime: self::timeOrNull($asset->departureTime),
            location: $asset->name,
            description: implode("\n", $details),
            url: $this->manageUrl($asset, $booking),
            isCancelled: $isCancelled,
            sequence: $isCancelled ? 1 : 0
        );
    }

    /**
     * A manual block. To an ordinary reader it says the asset is
     * unavailable and nothing else — deliberately indistinguishable in kind
     * from a letting, since why the unit cannot let its hall is nobody
     * else's business (§6.7).
     */
    private function blockEvent(RentalBlock $block, RentalAsset $asset, bool $viewerManagesAsset): VirtualEvent
    {
        return new VirtualEvent(
            uid: self::blockUid($block->id),
            calendarId: (int) $asset->calendarId,
            title: $asset->name . self::PUBLIC_BLOCKED_SUFFIX,
            startDate: $block->startDate,
            endDate: $block->endDate,
            startTime: null,
            endTime: null,
            location: $asset->name,
            // The reason is written by managers for managers; an ordinary
            // reader never sees it.
            description: $viewerManagesAsset ? $block->reason : null,
            url: $viewerManagesAsset ? $this->calendarUrl($asset) : null
        );
    }

    /**
     * The stable UID for a booking (§6.32).
     *
     * Derived from the booking's own id, so the same stay reaching a reader
     * through two subscribed calendars is one event rather than two, and a
     * calendar client updates its copy in place instead of accumulating one
     * per fetch.
     */
    public static function bookingUid(int $bookingId): string
    {
        return 'rental-booking-' . $bookingId . '@scoutmagic';
    }

    public static function blockUid(int $blockId): string
    {
        return 'rental-block-' . $blockId . '@scoutmagic';
    }

    private function manageUrl(RentalAsset $asset, RentalBooking $booking): ?string
    {
        $base = rtrim($this->baseUrl, '/');

        return $base === ''
            ? null
            : $base . '/mes-locations/' . $asset->slug . '/reservations/' . $booking->id;
    }

    private function calendarUrl(RentalAsset $asset): ?string
    {
        $base = rtrim($this->baseUrl, '/');

        return $base === '' ? null : $base . '/mes-locations/' . $asset->slug . '/calendrier';
    }

    /**
     * The asset's arrival/departure time when it has one — "use them when
     * they are known" (§6.30). Without them the occupancy is an all-day
     * event, which is the honest rendering of "we do not know the hour".
     */
    private static function timeOrNull(?string $time): ?string
    {
        return $time !== null && trim($time) !== '' ? $time : null;
    }
}
