<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * One thing a manager can do about a milestone (issue #462, D6–D7): a
 * status transition, pressed right there; a way to the box where the thing
 * is done; or a command the step itself carries out — generating or sending
 * the contract, where the manager already is (#708, IT-16).
 *
 * Exactly one of them is set — or the page of the file a step is
 * answered on (#708, IT-19). A target rather than a URL, because the
 * URL belongs to the page that renders it (`BookingBox::href()` builds it
 * from the booking's own address, `_contract_command.html.twig` the
 * command's form), and this type stays pure.
 */
final class MilestoneAction
{
    /** Renders a new version of the booking's contract. */
    public const GENERATE_CONTRACT = 'generate_contract';
    /** Emails the latest version to the renter, with a confirmation. */
    public const SEND_CONTRACT = 'send_contract';
    /** Opens the renter's copy where it is countersigned or refused. */
    public const COUNTERSIGN = 'countersign';

    private function __construct(
        public readonly string $label,
        public readonly ?BookingStatus $transition,
        public readonly ?BookingBox $box,
        public readonly ?string $command = null,
        /** A page of the booking's file to go to (#708, IT-19). */
        public readonly ?BookingPage $page = null
    ) {
    }

    /**
     * @param self::GENERATE_CONTRACT|self::SEND_CONTRACT|self::COUNTERSIGN $command
     */
    public static function command(string $label, string $command): self
    {
        return new self($label, null, null, $command);
    }

    public static function transition(BookingStatus $to, BookingStatus $from): self
    {
        return new self(BookingTransition::actionLabel($from, $to), $to, null);
    }

    public static function openBox(string $label, BookingBox $box): self
    {
        return new self($label, null, $box);
    }

    /** The way to a page of the file that holds no box — « Modifications ». */
    public static function openPage(string $label, BookingPage $page): self
    {
        return new self($label, null, null, null, $page);
    }

    /**
     * Whether this action gives the booking up rather than moving it on —
     * a refusal, a cancellation, a lapse. Never the proposed action (D7):
     * offering it first is asking the manager to choose their policy on
     * every file.
     */
    public function isRetreat(): bool
    {
        return $this->transition !== null && $this->transition->isAbandoned();
    }
}
