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
    /** How close to the stay the contract reminder is said a second time. */
    public const CONTRACT_SECOND_CHANCE_DAYS = 3;

    // ── To the unit's own people (notification centre) ───────────────────

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
     * The shipped delay, in **days**, before this reminder is due.
     *
     * Days and never hours, on purpose: the pass runs once a day
     * (`SendRentalRemindersHandler`), so an hour is a precision the machine
     * cannot honour — the hold reminder's old `24 hours` was one day
     * dressed up as something finer. What the number counts depends on the
     * reminder and is stated where it is used: days since the request
     * arrived, days before the stay, days after a due date went by.
     *
     * `0` means "the day the condition becomes true", which is what the
     * three money reminders and the two inventories have always done.
     */
    public function defaultDays(): int
    {
        return match ($this) {
            self::UNANSWERED_REQUEST => 3,
            self::HOLD_EXPIRING => 1,
            self::DEPOSIT_MISSING, self::BALANCE_MISSING, self::SECURITY_DEPOSIT_MISSING => 0,
            self::CONTRACT_MISSING => 14,
            self::ARRIVAL_INVENTORY, self::DEPARTURE_INVENTORY => 0,
            self::SETTLEMENT_DUE => 7,
            self::SECURITY_DEPOSIT_TO_RETURN => 14,
            self::COMPLIANCE_EXPIRING => 60,
            self::PRACTICAL_INFO => 7,
        };
    }

    /**
     * The module setting that carries the unit-wide default, which an asset
     * may then override.
     *
     * **Spelled out rather than composed from `$this->value`.** Two reasons,
     * and the second is the one that bites. A composed key ties the name of
     * a *stored* setting to the enum's backing value, so renaming a case
     * silently renames the row a unit already saved: the manifest declares
     * the old key, `settings` still holds it, and every asset falls back to
     * `defaultDays()` with nothing anywhere saying why. And a composed key
     * appears nowhere in the source, so `DeclaredSettingsAreReadTest` — a
     * grep over the module for each declared key — cannot tell a setting
     * that is read from one that is decorative. It is right to insist: the
     * failure it looks for is invisible from the configuration page.
     */
    public function settingKey(): string
    {
        return match ($this) {
            self::UNANSWERED_REQUEST => 'reminder_unanswered_request_days',
            self::HOLD_EXPIRING => 'reminder_hold_expiring_days',
            self::DEPOSIT_MISSING => 'reminder_deposit_missing_days',
            self::BALANCE_MISSING => 'reminder_balance_missing_days',
            self::CONTRACT_MISSING => 'reminder_contract_missing_days',
            self::SECURITY_DEPOSIT_MISSING => 'reminder_security_deposit_missing_days',
            self::ARRIVAL_INVENTORY => 'reminder_arrival_inventory_days',
            self::DEPARTURE_INVENTORY => 'reminder_departure_inventory_days',
            self::SETTLEMENT_DUE => 'reminder_settlement_due_days',
            self::SECURITY_DEPOSIT_TO_RETURN => 'reminder_security_deposit_to_return_days',
            self::COMPLIANCE_EXPIRING => 'reminder_compliance_expiring_days',
            self::PRACTICAL_INFO => 'reminder_practical_info_days',
        };
    }

    /**
     * How many days before this reminder may be sent a second time, or
     * **null** when it is said once and never again.
     *
     * Only four repeat, and the chantier is specific about which: money
     * that has not arrived is worth asking about again, because the answer
     * can change between two Mondays. An inventory nobody recorded is not —
     * it is a fact that will still be true next week, and repeating it
     * teaches the unit to ignore the whole channel, which is worse than not
     * reminding at all (§6.29).
     *
     * The contract's second chance is not a weekly cadence but a single
     * later nudge, and it is **derived from the configured lead time**
     * rather than fixed: sent when the stay comes into range, said once
     * more when only `CONTRACT_SECOND_CHANCE_DAYS` are left. Deriving it is
     * what keeps a unit that shortened the lead time to five days from
     * getting the two sends on top of each other.
     *
     * **The half-window floor is what makes "once more" true.** What comes
     * back here is a *minimum interval*, and `claim()` re-sends every time
     * it has elapsed — so the number of sends across a window of `d` days
     * is `1 + floor($d / $interval)`, not two because the sentence above
     * says two. `$d − 3` alone holds only while `$d > 6`; under that it
     * degenerates, and at four days it sends five times. A promise of one
     * extra nudge that turns into a daily nag for a setting this screen
     * offers is worse than no second chance, because a unit learns to
     * ignore the channel that carries the other eleven.
     *
     * So the interval is never allowed below `intdiv($d, 2) + 1`, which is
     * exactly the threshold at which a third send no longer fits. Above six
     * days — the default fourteen included — the derived value already
     * clears it and nothing changes; below, the second chance simply lands
     * a little earlier than three days out, which is the honest answer when
     * the whole window is shorter than that.
     */
    public function repeatAfterDays(int $configuredDays): ?int
    {
        return match ($this) {
            self::DEPOSIT_MISSING, self::BALANCE_MISSING, self::SECURITY_DEPOSIT_MISSING => 7,
            self::CONTRACT_MISSING => max(
                1,
                intdiv($configuredDays, 2) + 1,
                $configuredDays - self::CONTRACT_SECOND_CHANCE_DAYS
            ),
            default => null,
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
