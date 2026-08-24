<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Audit;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;

/**
 * What happened to one booking (§6.15), recorded through `Core\Audit`
 * (§8.66) like every other per-entity history on the site.
 *
 * This module invented that history first, in its own `rental_booking_
 * events` table; core generalised it for Camps and the two then existed
 * side by side, with two storage rules, two timelines and two answers to
 * "is a value personal data". Core\Audit's answer — encrypt every value,
 * unconditionally, and accept that the table is not searchable on them —
 * is the safer of the two, because a history is precisely where a name
 * ends up in a field nobody thought to classify. So this table goes and
 * the module records where everything else does.
 *
 * **What stays on this side of the boundary** is the vocabulary: the field
 * keys below, their French labels, and the mapping from "which member did
 * this" to "which account did this". Core\Audit knows none of that, and
 * `record()` here keeps the exact shape the ~17 call sites already used,
 * so a service that records a status change did not have to learn a new
 * one.
 */
final class BookingAudit
{
    /** Registered in AuditAccessResolver from the composition root. */
    public const ENTITY_TYPE = 'rental_booking';

    public const STATUS_CHANGED = 'status_changed';
    public const HOLD_PLACED = 'hold_placed';
    public const HOLD_CLEARED = 'hold_cleared';
    public const PRICE_CHANGED = 'price_changed';
    public const DATES_CHANGED = 'dates_changed';
    public const CHANGE_REQUESTED = 'change_requested';
    public const CHANGE_DECIDED = 'change_decided';
    public const COMMENT_ADDED = 'comment_added';

    /**
     * What a reader sees instead of the raw key. A key with no entry falls
     * back to the key itself in the shared partial, which is a missing
     * translation rather than a missing change.
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        self::STATUS_CHANGED => 'Statut',
        self::HOLD_PLACED => 'Option posée',
        self::HOLD_CLEARED => 'Option levée',
        self::PRICE_CHANGED => 'Prix',
        self::DATES_CHANGED => 'Dates',
        self::CHANGE_REQUESTED => 'Demande de modification',
        self::CHANGE_DECIDED => 'Décision sur la modification',
        self::COMMENT_ADDED => 'Commentaire',
    ];

    public function __construct(
        private AuditService $audit,
        private ?ActorAccountResolver $actorAccounts = null
    ) {
    }

    /**
     * Records one change against a booking.
     *
     * `$actorMemberId` is null for anything the application did on its own
     * — a hold lapsing on its cron sweep — and that distinction is the
     * reason `source` exists separately from the actor: a change with no
     * account behind it is automatic, and a reader needs to know it was
     * the site rather than a manager who moved the booking.
     *
     * A member the site cannot map to an account (no account yet, a
     * different address in Desk) still records as `Human`: the honest
     * reading is "a person did this and we can no longer say who", which
     * is exactly what a deleted account produces too.
     */
    public function record(
        int $bookingId,
        string $fieldKey,
        ?string $fromValue = null,
        ?string $toValue = null,
        ?string $summary = null,
        ?int $actorMemberId = null
    ): void {
        $this->audit->record(
            self::ENTITY_TYPE,
            $bookingId,
            $fieldKey,
            $fromValue,
            $toValue,
            $actorMemberId !== null ? AuditSource::Human : AuditSource::System,
            $summary,
            null,
            $this->actorAccounts?->accountIdFor($actorMemberId)
        );
    }

    /**
     * Erases a purged booking's history. `entity_changes` carries no
     * foreign key — the referenced table varies by entity type and a
     * module's tables come and go with the module — so nothing cascades
     * here and the purge has to say so itself.
     */
    public function forget(int $bookingId): int
    {
        return $this->audit->forgetEntity(self::ENTITY_TYPE, $bookingId);
    }
}
