<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * « Envoyer automatiquement les e-mails de décision » (chantier #843, D7.1
 * and IT-09): the switch and the delay of the « Décisions automatiques »
 * sub-box of « Capacités par branche », and the planning they drive.
 *
 * **Planning happens when a decision is taken, and only then.** plan() is
 * called by Service\RequestDecisionService::apply() — the one door every
 * decision goes through — right after the status changed, in the same
 * transaction: with the switch on, the e-mail is planned for now + the delay. A decision taken while the switch
 * was off is never planned afterwards, switching it on included (D7: « Les
 * décisions prises avant l'activation ne sont pas planifiées ») — nothing
 * scans the existing decisions. A change of decision cancels the planned
 * e-mail (RegistrationRequestRepository::updateStatus()) and the new
 * decision is planned here with a full delay.
 *
 * **Switching off cancels nothing**: an e-mail already planned still leaves
 * at its due date — Task\SendDecisionEmailsHandler consumes due e-mails
 * whatever the switch says — and the fiche says so, with « Envoyer
 * maintenant ».
 *
 * **Both decision templates ship with a text** (issue #932), so nothing
 * waits on a chief to write one: the switch can always be turned on, and a
 * decision is always planned. A stored body that lost its tracking link
 * cannot leave — the attempt is recorded as failed
 * (RequestEmailService::deliverAttempt()), never sent without it.
 *
 * Both settings are `editable: false`: the pair is saved together with the
 * automatic acceptance (AutomaticAcceptancePolicy::save()), which a list of
 * independent fields cannot do, so this box is the only place they are
 * written.
 */
class AutomaticDecisionEmailService
{
    public const SETTING_ENABLED = 'registration_decision_email_auto_send';
    public const SETTING_DELAY_HOURS = 'registration_decision_email_delay_hours';

    public const DEFAULT_DELAY_HOURS = 24;
    public const MIN_DELAY_HOURS = 1;
    /** Thirty days: beyond that, a decision would wait longer than a family waits for an answer. */
    public const MAX_DELAY_HOURS = 720;

    public const MESSAGE_SAVE_FAILED = "Les réglages n'ont pas pu être enregistrés — rechargez la page et réessayez.";

    public const MESSAGE_DELAY_INVALID = 'Le délai avant envoi doit être un nombre entier d\'heures, de '
        . self::MIN_DELAY_HOURS . ' à ' . self::MAX_DELAY_HOURS . ". Rien n'a été enregistré.";

    public function __construct(
        private SettingService $settingService,
        private DecisionEmailService $decisionEmails
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) $this->settingService->get(self::SETTING_ENABLED, 'registration', '0') === '1';
    }

    /**
     * The delay in hours. A stored value that is not a whole number within
     * the bounds — only the box writes it, but a database is not a promise —
     * reads as the default rather than as an e-mail sent at once.
     */
    public function delayHours(): int
    {
        return self::parseDelay(
            (string) $this->settingService->get(self::SETTING_DELAY_HOURS, 'registration', '')
        ) ?? self::DEFAULT_DELAY_HOURS;
    }

    /**
     * When the e-mail of a decision taken at $decidedAt would leave.
     */
    public function dueAt(\DateTimeImmutable $decidedAt): \DateTimeImmutable
    {
        return $decidedAt->modify('+' . $this->delayHours() . ' hours');
    }

    /**
     * When the e-mail of a decision taken right now would leave, or null
     * when it would not leave on its own — the switch is off. What the
     * decision dialog announces before anything is decided.
     */
    public function plannedDueAtIfDecidedNow(?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return $this->dueAt($now ?? new \DateTimeImmutable());
    }

    /**
     * Plans the e-mail of the decision just taken on $request — $decision
     * is the DecisionEmail::DECISION_* the request now holds ($request
     * itself may be the object read before the transition). Null, and
     * nothing planned, when the automatic sending is off, or when the
     * request does not hold that decision or already has an e-mail in
     * play (DecisionEmailService::scheduleDecision()).
     */
    public function plan(
        RegistrationRequest $request,
        string $decision,
        ?\DateTimeImmutable $decidedAt = null
    ): ?DecisionEmail {
        if (!$this->isEnabled()) {
            return null;
        }

        return $this->decisionEmails->scheduleDecision(
            $request->id,
            $decision,
            $this->dueAt($decidedAt ?? new \DateTimeImmutable())
        );
    }

    /**
     * What the « Décisions automatiques » sub-box shows.
     *
     * @return array{enabled: bool, delay_hours: int, min_delay: int, max_delay: int}
     */
    public function boxState(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'delay_hours' => $this->delayHours(),
            'min_delay' => self::MIN_DELAY_HOURS,
            'max_delay' => self::MAX_DELAY_HOURS,
        ];
    }

    /**
     * The checks made before the pair is written — by
     * AutomaticAcceptancePolicy::save(), which saves it with the rest of
     * the sub-box (the automatic acceptance, IT-10) as one decision: the
     * delay to store — the stored one when $delay, what was typed, is null
     * (the field was not submitted) — or a French sentence, and nothing
     * is saved.
     *
     * @throws RegistrationException with a French sentence
     */
    public function validatedDelay(?string $delay): int
    {
        $hours = $delay === null ? $this->delayHours() : self::parseDelay($delay);
        if ($hours === null) {
            throw new RegistrationException(self::MESSAGE_DELAY_INVALID);
        }

        return $hours;
    }

    /**
     * The two settings as they are written.
     *
     * @return array<string, string>
     */
    public function settingsFor(bool $enabled, int $hours): array
    {
        return [
            self::SETTING_ENABLED => $enabled ? '1' : '0',
            self::SETTING_DELAY_HOURS => (string) $hours,
        ];
    }

    /**
     * The mock-up's confirmation of the sub-box (« Enregistrer les
     * décisions automatiques ? »), said once saved: whether e-mails will
     * leave, for which decisions, and what becomes of the ones already
     * planned.
     */
    public function confirmation(bool $enabled, int $hours, bool $delayChanged): string
    {
        $planned = $this->decisionEmails->countScheduled();
        $lines = ['Réglages enregistrés.'];
        $many = $planned > 1;

        if ($enabled) {
            $lines[] = "Les e-mails d'acceptation et de refus partiront {$hours} h après chaque décision. Tant "
                . "qu'ils ne sont pas partis, vous pouvez changer la décision et rien n'est envoyé.";
            $lines[] = 'Seules les décisions prises à partir de maintenant sont concernées : une décision déjà '
                . 'prise sans envoi reste à envoyer à la main depuis sa fiche.';
            if ($delayChanged && $planned > 0) {
                $lines[] = $many
                    ? "Les {$planned} e-mails déjà planifiés gardent leur échéance."
                    : "L'e-mail déjà planifié garde son échéance.";
            }

            return implode(' ', $lines);
        }

        $lines[] = $planned > 0
            ? "Aucune nouvelle décision n'enverra d'e-mail toute seule : chaque envoi se fait depuis la fiche "
                . 'de la demande.'
            : "Aucun e-mail ne partira tout seul : chaque envoi se fait depuis la fiche de la demande.";
        if ($planned > 0) {
            $lines[] = match (true) {
                $many => "Attention : les {$planned} e-mails déjà planifiés partiront quand même à leur échéance ; "
                    . '« Envoyer maintenant », sur leur fiche, les fait partir tout de suite.',
                default => "Attention : l'e-mail déjà planifié partira quand même à son échéance ; « Envoyer "
                    . 'maintenant », sur sa fiche, le fait partir tout de suite.',
            };
        }

        return implode(' ', $lines);
    }


    /**
     * A whole number of hours within the bounds, or null. Digits only:
     * « 24,5 », « -1 », « 1e3 » and « 24 h » are refused rather than read
     * as something else.
     */
    public static function parseDelay(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d{1,4}$/', $value) !== 1) {
            return null;
        }
        $hours = (int) $value;

        return $hours >= self::MIN_DELAY_HOURS && $hours <= self::MAX_DELAY_HOURS ? $hours : null;
    }
}
