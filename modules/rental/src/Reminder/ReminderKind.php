<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Reminder;

/**
 * Every reminder this module sends (§6.29).
 *
 * **The audience is part of the definition, not a decision made later.**
 * That is the distinction the roadmap is emphatic about: `NotificationService`
 * targets `user_accounts`, and an external renter does not have one — their
 * reminders go out by `MailService` and never through the notification
 * centre. Encoding it here means the two channels cannot be confused at a
 * call site, and a new reminder has to say which it is before it can exist.
 */
enum ReminderKind: string
{
    // ── To the unit's own people (notification centre) ───────────────────

    /** A public request just came in. */
    case NEW_REQUEST = 'new_request';

    /** A request nobody has answered yet. */
    case UNANSWERED_REQUEST = 'unanswered_request';

    /** A temporary hold about to lapse and free the dates again. */
    case HOLD_EXPIRING = 'hold_expiring';

    /** The deposit's due date has passed with nothing received. */
    case DEPOSIT_MISSING = 'deposit_missing';

    /** The balance's due date has passed with something still owed. */
    case BALANCE_MISSING = 'balance_missing';

    /** The stay is close and no contract has been generated. */
    case CONTRACT_MISSING = 'contract_missing';

    /** The security deposit's due date has passed with nothing received. */
    case SECURITY_DEPOSIT_MISSING = 'security_deposit_missing';

    /** The renters have arrived and nobody recorded the arrival inventory. */
    case ARRIVAL_INVENTORY = 'arrival_inventory';

    /** They have left and nobody recorded the departure inventory. */
    case DEPARTURE_INVENTORY = 'departure_inventory';

    /** The stay is over and no settlement has been drawn up. */
    case SETTLEMENT_DUE = 'settlement_due';

    /** A security deposit is sitting on the unit's account after the stay. */
    case SECURITY_DEPOSIT_TO_RETURN = 'security_deposit_to_return';

    /** A register entry is expiring, or has expired (§6.33). */
    case COMPLIANCE_EXPIRING = 'compliance_expiring';

    // ── To the renter (email only) ───────────────────────────────────────

    /** A week out: arrival times, keys, contacts. */
    case PRACTICAL_INFO = 'practical_info';

    /**
     * Whether this reminder goes to somebody with an account.
     *
     * The one that does not — PRACTICAL_INFO — is the reason this method
     * exists rather than a comment: a renter has no `user_account`, so
     * dispatching it through `NotificationService` would silently reach
     * nobody at all rather than fail.
     */
    public function isInternal(): bool
    {
        return $this !== self::PRACTICAL_INFO;
    }

    /**
     * The declared notification type, for the internal ones.
     *
     * One id per reminder rather than a single `rental.reminder`: a unit
     * that wants to hear about unpaid deposits but not about inventories
     * can only say so if the two are separate types in the notification
     * settings.
     */
    public function notificationTypeId(): string
    {
        return 'rental.' . $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::NEW_REQUEST => 'Nouvelle demande de location',
            self::UNANSWERED_REQUEST => 'Demande de location sans réponse',
            self::HOLD_EXPIRING => 'Blocage de dates bientôt expiré',
            self::DEPOSIT_MISSING => 'Acompte non reçu',
            self::BALANCE_MISSING => 'Solde non reçu',
            self::CONTRACT_MISSING => 'Contrat non établi',
            self::SECURITY_DEPOSIT_MISSING => 'Caution non reçue',
            self::ARRIVAL_INVENTORY => "État des lieux d'entrée à faire",
            self::DEPARTURE_INVENTORY => 'État des lieux de sortie à faire',
            self::SETTLEMENT_DUE => 'Décompte final à établir',
            self::SECURITY_DEPOSIT_TO_RETURN => 'Caution à restituer',
            self::COMPLIANCE_EXPIRING => 'Document de conformité expirant',
            self::PRACTICAL_INFO => 'Informations pratiques avant le séjour',
        };
    }

    /**
     * What the register entry or booking is keyed under in
     * `rental_reminders_sent`.
     */
    public function subjectType(): string
    {
        return $this === self::COMPLIANCE_EXPIRING ? 'compliance' : 'booking';
    }

    /**
     * @return self[]
     */
    public static function internalCases(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $kind) => $kind->isInternal()));
    }
}
