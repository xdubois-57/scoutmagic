<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Service\DateInput;
use Core\View\DateFilterExtension;

/**
 * « Accepter automatiquement les demandes « Oui » » (chantier #843, D7.2
 * and IT-10): the switch and the date of effect of the « Décisions
 * automatiques » sub-box, and the rules the automatic acceptance
 * (Service\AutomaticAcceptanceService) accepts by — kept here, apart from
 * the pass, so that the decision dialog announces exactly what the pass
 * will do.
 *
 * **It runs only when everything it depends on runs**: the switch is on,
 * a date of effect is set, waitlists are managed (no recommendation
 * otherwise), the automatic sending of the decision e-mails is on and the
 * acceptance template is written — without the last two, an accepted
 * family would be left without an answer. isActive() checks all of it at
 * each pass, not only when the box is saved: waitlists can be switched off
 * elsewhere (Configuration > Paramètres), and a template emptied since.
 *
 * **Which requests it takes, slot by slot** (nextAcceptable()): the queue
 * of Service\RecommendationService, in its order, and
 *
 * - a request filed **before the date of effect** is left aside — never
 *   accepted automatically, and never a reason to stop: it is judged as if
 *   accepted by every request behind it, so taking those leaves its place;
 * - the first other request that is not « Oui » ends the slot — « Peut-
 *   être » and « Non » are never accepted automatically;
 * - so does a ranked « Oui » that would take the slot past its capacity,
 *   which a « Oui » threshold above 100 % allows: only a « Toujours Oui »
 *   request may go beyond the number of places;
 * - possible duplicates and requests outside every age bracket are not in
 *   the queue at all, so they are never taken.
 *
 * Both settings are `editable: false`: the rule above concerns the switch
 * and three other settings, which a list of independent fields cannot
 * check, so the sub-box is the only place they are written.
 */
class AutomaticAcceptancePolicy
{
    public const SETTING_ENABLED = 'registration_auto_accept';
    public const SETTING_EFFECTIVE_FROM = 'registration_auto_accept_from';

    /** What isActive() lacks, in the order the sub-box asks for it. */
    public const MISSING_WAITLIST = 'waitlist';
    public const MISSING_AUTO_SEND = 'auto_send';
    public const MISSING_TEMPLATE = 'template';

    public const MESSAGE_WAITLIST_REQUIRED = "L'acceptation automatique ne peut pas être activée : activez d'abord "
        . "la gestion des listes d'attente, la recommandation en dépend. Rien n'a été enregistré.";

    public const MESSAGE_AUTO_SEND_REQUIRED = "L'acceptation automatique ne peut pas être activée sans l'envoi "
        . "automatique des e-mails de décision, sans quoi la famille resterait sans réponse. Rien n'a été enregistré.";

    public const MESSAGE_TEMPLATE_REQUIRED = "L'acceptation automatique ne peut pas être activée tant que le gabarit "
        . "d'acceptation n'est pas rédigé (voir Gabarits). Rien n'a été enregistré.";

    public const MESSAGE_DATE_REQUIRED = "Choisissez la date « À partir du » de l'acceptation automatique. "
        . "Rien n'a été enregistré.";

    public const MESSAGE_DATE_INVALID = "La date « À partir du » n'est pas une date valide. Rien n'a été enregistré.";

    public function __construct(
        private SettingService $settingService,
        private SlotService $slotService,
        private AutomaticDecisionEmailService $automaticEmails
    ) {
    }

    public function isSwitchOn(): bool
    {
        return (string) $this->settingService->get(self::SETTING_ENABLED, 'registration', '0') === '1';
    }

    /**
     * The date of effect, at midnight: the requests filed from then on are
     * the only ones the automatic acceptance takes. Null when none is set,
     * or when what is stored is not a date — only the box writes it, but a
     * database is not a promise.
     */
    public function effectiveFrom(): ?\DateTimeImmutable
    {
        return self::parseDate((string) $this->settingService->get(self::SETTING_EFFECTIVE_FROM, 'registration', ''));
    }

    /**
     * What the automatic acceptance needs and does not have — MISSING_*,
     * the first one in the order the sub-box asks for them — or null.
     */
    public function missingPrerequisite(): ?string
    {
        return self::missing(
            $this->slotService->waitlistEnabled(),
            $this->automaticEmails->isEnabled(),
            $this->automaticEmails->acceptanceTemplateReady()
        );
    }

    /**
     * Whether the automatic acceptance runs now: the switch, its date, and
     * every prerequisite (see the class comment).
     */
    public function isActive(): bool
    {
        return $this->isSwitchOn() && $this->effectiveFrom() !== null && $this->missingPrerequisite() === null;
    }

    /**
     * Switches it off — what turning « Gérer les listes d'attente » off
     * does too, as in the mock-up: without a recommendation there is
     * nothing to accept by, and switching waitlists back on must not bring
     * the automatic acceptance back unnoticed. True when it was on.
     *
     * @throws SettingException when the setting cannot be written
     */
    public function switchOff(): bool
    {
        if (!$this->isSwitchOn()) {
            return false;
        }
        $this->settingService->setInternal(self::SETTING_ENABLED, '0', 'registration');

        return true;
    }

    /**
     * What the sub-box shows.
     *
     * `can_enable`: the switch may be turned on — waitlists and the
     * automatic sending are on and the acceptance template is written; it
     * is disabled otherwise, and `missing` says why. `effective_from` is the
     * stored date, or today's when none is (Y-m-d, for the native picker).
     * `active`: what the pass will do.
     *
     * `waitlist_enabled` and `template_ready` are what the page's script
     * needs to keep the switch in step without a reload.
     *
     * @return array{enabled: bool, effective_from: string, can_enable: bool, missing: ?string,
     *               waitlist_enabled: bool, template_ready: bool, active: bool}
     */
    public function boxState(\DateTimeImmutable $today = new \DateTimeImmutable()): array
    {
        $missing = $this->missingPrerequisite();
        $enabled = $this->isSwitchOn();
        $from = $this->effectiveFrom();

        return [
            'enabled' => $enabled,
            'effective_from' => ($from ?? $today)->format('Y-m-d'),
            'can_enable' => $missing === null,
            'missing' => $missing,
            'waitlist_enabled' => $this->slotService->waitlistEnabled(),
            'template_ready' => $this->automaticEmails->acceptanceTemplateReady(),
            'active' => $enabled && $from !== null && $missing === null,
        ];
    }

    /**
     * Saves the whole « Décisions automatiques » sub-box as one decision —
     * the automatic sending and its delay (AutomaticDecisionEmailService's
     * rules), the automatic acceptance and its date — or nothing, and
     * answers the confirmation to show.
     *
     * The automatic acceptance is refused on — switched on, or kept on —
     * while waitlists are not managed, while the automatic sending is not
     * on in this very save, or while the acceptance template is not
     * written; and without a date. A date that was not submitted ($from
     * null or empty) keeps the stored one; one that is not a date is
     * refused, switch on or off — a value nobody sees act is not stored
     * wrong.
     *
     * @throws RegistrationException with a French sentence, nothing saved
     */
    public function save(bool $autoSend, ?string $delay, bool $enabled, ?string $from): string
    {
        $hours = $this->automaticEmails->validatedDelay($autoSend, $delay);

        $submittedDate = null;
        if ($from !== null && trim($from) !== '') {
            $submittedDate = self::parseDate(trim($from));
            if ($submittedDate === null) {
                throw new RegistrationException(self::MESSAGE_DATE_INVALID);
            }
        }
        $date = $submittedDate ?? $this->effectiveFrom();

        if ($enabled) {
            $missing = self::missing(
                $this->slotService->waitlistEnabled(),
                $autoSend,
                $this->automaticEmails->acceptanceTemplateReady()
            );
            $refusal = match ($missing) {
                self::MISSING_WAITLIST => self::MESSAGE_WAITLIST_REQUIRED,
                self::MISSING_AUTO_SEND => self::MESSAGE_AUTO_SEND_REQUIRED,
                self::MISSING_TEMPLATE => self::MESSAGE_TEMPLATE_REQUIRED,
                default => null,
            };
            if ($refusal !== null) {
                throw new RegistrationException($refusal);
            }
            if ($date === null) {
                throw new RegistrationException(self::MESSAGE_DATE_REQUIRED);
            }
        }

        $wasOn = $this->isSwitchOn();
        $delayChanged = $hours !== $this->automaticEmails->delayHours();
        $settings = $this->automaticEmails->settingsFor($autoSend, $hours) + [
            self::SETTING_ENABLED => $enabled ? '1' : '0',
            self::SETTING_EFFECTIVE_FROM => $date?->format('Y-m-d') ?? '',
        ];

        try {
            $this->settingService->setManyInternal($settings, 'registration');
        } catch (SettingException $e) {
            throw new RegistrationException(AutomaticDecisionEmailService::MESSAGE_SAVE_FAILED, 0, $e);
        }

        $lines = [$this->automaticEmails->confirmation($autoSend, $hours, $delayChanged)];
        if ($enabled) {
            $lines[] = 'À partir du ' . DateFilterExtension::dateFr($date) . ', les demandes recommandées « Oui » '
                . 'déposées depuis cette date seront acceptées par le site, dans l\'ordre de priorité, sans dépasser '
                . 'le nombre de places (seules les « Toujours Oui » le peuvent). Leurs familles recevront l\'e-mail '
                . "d'acceptation {$hours} h plus tard.";
        } elseif ($wasOn) {
            $lines[] = "L'acceptation automatique est désactivée : chaque demande se décide à la main.";
        }

        return implode(' ', $lines);
    }

    /**
     * The request the automatic acceptance takes next in one slot's queue —
     * $queue in queue order (queuesBySlot()) — or null when it takes none
     * (see the class comment for the rules).
     *
     * @param array<int, Recommendation> $queue
     */
    public static function nextAcceptable(array $queue, \DateTimeImmutable $effectiveFrom): ?Recommendation
    {
        foreach ($queue as $entry) {
            if (!$entry->isQueued()) {
                continue;
            }
            // Filed before the date of effect: left aside, never a stop.
            if ($entry->receivedAt === null || $entry->receivedAt < $effectiveFrom) {
                continue;
            }
            if ($entry->level !== SlotMath::LEVEL_YES) {
                return null;
            }
            // A ranked « Oui » beyond the number of places (a « Oui »
            // threshold above 100 %): only « Toujours Oui » may go there.
            if ($entry->kind !== Recommendation::KIND_ALWAYS && $entry->exceedsCapacity()) {
                return null;
            }

            return $entry;
        }

        return null;
    }

    /**
     * Every request of $recommendations (one year's, as
     * RecommendationService::forYear() answers them) that the automatic
     * acceptance would take if nobody decided first — what the decision
     * dialog announces. Taking a request turns it into a place already
     * occupied, so the requests behind it keep the occupancy they were
     * judged on: successive passes take exactly these.
     *
     * @param array<int, Recommendation> $recommendations
     * @return array<int, int> request ids
     */
    public static function acceptableRequestIds(array $recommendations, \DateTimeImmutable $effectiveFrom): array
    {
        $ids = [];
        foreach (self::queuesBySlot($recommendations) as $queue) {
            while (($next = self::nextAcceptable($queue, $effectiveFrom)) !== null) {
                $ids[] = $next->requestId;
                $taken = $next->requestId;
                $isNotTaken = static fn(Recommendation $entry): bool => $entry->requestId !== $taken;
                $queue = array_values(array_filter($queue, $isNotTaken));
            }
        }

        return $ids;
    }

    /**
     * The queued recommendations of one year, slot by slot — keyed
     * « age branch id:year in branch » — each slot in queue order.
     *
     * @param array<int, Recommendation> $recommendations
     * @return array<string, array<int, Recommendation>>
     */
    public static function queuesBySlot(array $recommendations): array
    {
        $queues = [];
        foreach ($recommendations as $entry) {
            if ($entry->isQueued()) {
                $queues[$entry->ageBranchId . ':' . $entry->yearInBranch][] = $entry;
            }
        }
        foreach ($queues as &$queue) {
            usort($queue, static fn(Recommendation $a, Recommendation $b): int => $a->position <=> $b->position);
        }
        unset($queue);

        return $queues;
    }

    /**
     * MISSING_* for the first prerequisite that is false, or null.
     */
    private static function missing(bool $waitlist, bool $autoSend, bool $template): ?string
    {
        return match (false) {
            $waitlist => self::MISSING_WAITLIST,
            $autoSend => self::MISSING_AUTO_SEND,
            $template => self::MISSING_TEMPLATE,
            default => null,
        };
    }

    /**
     * A `Y-m-d` date at midnight, or null.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        return DateInput::parse('!Y-m-d', $value);
    }
}
