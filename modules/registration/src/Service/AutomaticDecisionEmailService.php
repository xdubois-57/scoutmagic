<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingException;
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
 * transaction: with the switch on and the decision's template written, the
 * e-mail is planned for now + the delay. A decision taken while the switch
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
 * **It cannot be switched on while the acceptance or the refusal template
 * is « À rédiger »** — the one definition of IT-07
 * (RequestEmailTemplateService::isToWrite(), read here through
 * RequestEmailService::isAcceptedBodyReady() / isRefusedBodyReady(),
 * which are its negation). Switching off is always allowed.
 *
 * Both settings are `editable: false`: the rule above concerns the pair
 * and the templates, which a list of independent fields cannot check, so
 * this box is the only place they are written.
 */
class AutomaticDecisionEmailService
{
    public const SETTING_ENABLED = 'registration_decision_email_auto_send';
    public const SETTING_DELAY_HOURS = 'registration_decision_email_delay_hours';

    public const DEFAULT_DELAY_HOURS = 24;
    public const MIN_DELAY_HOURS = 1;
    /** Thirty days: beyond that, a decision would wait longer than a family waits for an answer. */
    public const MAX_DELAY_HOURS = 720;

    public const MESSAGE_TEMPLATES_MISSING = "L'envoi automatique ne peut pas être activé : les gabarits "
        . "d'acceptation et de refus doivent d'abord être rédigés (voir Gabarits). Rien n'a été enregistré.";

    public const MESSAGE_DELAY_INVALID = 'Le délai avant envoi doit être un nombre entier d\'heures, de '
        . self::MIN_DELAY_HOURS . ' à ' . self::MAX_DELAY_HOURS . ". Rien n'a été enregistré.";

    public function __construct(
        private SettingService $settingService,
        private RequestEmailService $emailService,
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
     * Whether both decision templates are written — the condition to
     * switch the automatic sending on.
     */
    public function templatesReady(): bool
    {
        return $this->emailService->isAcceptedBodyReady() && $this->emailService->isRefusedBodyReady();
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
     * when it would not leave on its own — the switch is off, or the
     * template of $decision (DecisionEmail::DECISION_*) is not written.
     * What the decision dialog announces before anything is decided.
     */
    public function plannedDueAtIfDecidedNow(string $decision, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        if (!$this->isEnabled() || !$this->emailService->isBodyReady($decision)) {
            return null;
        }

        return $this->dueAt($now ?? new \DateTimeImmutable());
    }

    /**
     * Plans the e-mail of the decision just taken on $request — $decision
     * is the DecisionEmail::DECISION_* the request now holds ($request
     * itself may be the object read before the transition). Null, and
     * nothing planned, when the automatic sending is off, when that
     * decision's template is not written (an e-mail that can only fail is
     * not planned; the confirmation says the template is missing), or when
     * the request does not hold that decision or already has an e-mail in
     * play (DecisionEmailService::scheduleDecision()).
     */
    public function plan(
        RegistrationRequest $request,
        string $decision,
        ?\DateTimeImmutable $decidedAt = null
    ): ?DecisionEmail {
        if (!$this->isEnabled() || !$this->emailService->isBodyReady($decision)) {
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
     * `can_enable` is false while a decision template is « À rédiger » —
     * the switch is then disabled, unless it is already on (switching off
     * is always allowed).
     *
     * @return array{enabled: bool, delay_hours: int, templates_ready: bool, can_enable: bool,
     *               min_delay: int, max_delay: int}
     */
    public function boxState(): array
    {
        $enabled = $this->isEnabled();
        $templatesReady = $this->templatesReady();

        return [
            'enabled' => $enabled,
            'delay_hours' => $this->delayHours(),
            'templates_ready' => $templatesReady,
            'can_enable' => $enabled || $templatesReady,
            'min_delay' => self::MIN_DELAY_HOURS,
            'max_delay' => self::MAX_DELAY_HOURS,
        ];
    }

    /**
     * Saves the sub-box as one decision — the switch and the delay
     * together, or nothing — and answers the confirmation to show, which
     * says whether e-mails are going to leave (D8).
     *
     * @param string|null $delay What was typed; null when the field was
     *        not submitted, which keeps the stored delay.
     * @throws RegistrationException with a French sentence, nothing saved
     */
    public function save(bool $enabled, ?string $delay): string
    {
        $hours = $delay === null ? $this->delayHours() : self::parseDelay($delay);
        if ($hours === null) {
            throw new RegistrationException(self::MESSAGE_DELAY_INVALID);
        }
        // Switching ON is what the templates condition; keeping an active
        // switch on — to change the delay — is not refused even if a
        // template went missing since (each decision is then simply not
        // planned, plan()).
        if ($enabled && !$this->isEnabled() && !$this->templatesReady()) {
            throw new RegistrationException(self::MESSAGE_TEMPLATES_MISSING);
        }

        $delayChanged = $hours !== $this->delayHours();

        try {
            $this->settingService->setManyInternal([
                self::SETTING_ENABLED => $enabled ? '1' : '0',
                self::SETTING_DELAY_HOURS => (string) $hours,
            ], 'registration');
        } catch (SettingException $e) {
            throw new RegistrationException(
                "Les réglages n'ont pas pu être enregistrés — rechargez la page et réessayez.",
                0,
                $e
            );
        }

        return $this->confirmation($enabled, $hours, $delayChanged, $this->decisionEmails->countScheduled());
    }

    /**
     * The mock-up's confirmation of the sub-box (« Enregistrer les
     * décisions automatiques ? »), said once saved: whether e-mails will
     * leave, for which decisions, and what becomes of the ones already
     * planned.
     */
    private function confirmation(bool $enabled, int $hours, bool $delayChanged, int $planned): string
    {
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
                !$this->templatesReady() => 'Attention : '
                    . ($many
                        ? "les {$planned} e-mails déjà planifiés restent prévus"
                        : "l'e-mail déjà planifié reste prévu")
                    . ', mais ' . ($many ? 'leur' : 'son') . " envoi échouera tant que les gabarits d'acceptation "
                    . 'et de refus ne sont pas rédigés (voir Gabarits).',
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
