<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Service\DateInput;

/**
 * Service\ReenrollmentSavePlan in the chief's words: the confirmation
 * dialog, and the message after the save (issue #796, D2, D6, D8).
 *
 * **One wording, two screens.** The script's dialog and the page a browser
 * without the script is shown both read `dialog()`, so the two can never
 * say different things about the same save — and both come from the plan
 * the save then applies (D1).
 *
 * Every dialog answers the same question: does an e-mail leave? Yes — how
 * many, which, to whom, and the button turns into « Enregistrer et
 * envoyer ». No — « Aucun e-mail ne partira », and why.
 */
class ReenrollmentSavePlanPresenter
{
    private const SETTING_LABELS = [
        'open_at' => 'Ouverture',
        'close_at' => 'Fermeture',
        'reminder_1_days' => 'Premier rappel',
        'reminder_2_days' => 'Second rappel',
    ];

    private const EMAIL_WHAT = [
        ReenrollmentCampaignService::EMAIL_OPENING => "l'e-mail d'ouverture",
        ReenrollmentCampaignService::EMAIL_REMINDER_1 => 'le premier rappel',
        ReenrollmentCampaignService::EMAIL_REMINDER_2 => 'le second rappel',
        ReenrollmentCampaignService::EMAIL_CLOSING => "l'e-mail de clôture",
    ];

    /**
     * @return array{
     *     title: string,
     *     campaign: ?array{title: string, detail: string},
     *     changes: list<string>,
     *     mail: ?array{headline: string, lines: list<string>, note: string},
     *     none: ?array{headline: string, reason: string},
     *     confirm_label: string,
     *     sends: bool
     * }
     */
    public function dialog(ReenrollmentSavePlan $plan, \DateTimeImmutable $now): array
    {
        $mail = null;
        $none = null;
        if ($plan->sendsEmail()) {
            $lines = [];
            $total = 0;
            foreach ($plan->emails as $email) {
                $total += $email['families'];
                $lines[] = ucfirst(self::EMAIL_WHAT[$email['type']] ?? $email['type'])
                    . ', ' . self::audience($email['type'], $email['families'])
                    . ($email['deferred'] ? ' — il part dans l\'heure, au passage du planificateur.' : ' — il part dans quelques minutes.');
            }
            $mail = [
                'headline' => $total === 1 ? '1 e-mail va partir' : $total . ' e-mails vont partir',
                'lines' => $lines,
                'note' => 'Un e-mail envoyé ne se rappelle pas.',
            ];
        } else {
            $none = [
                'headline' => 'Aucun e-mail ne partira.',
                'reason' => $this->reason($plan, $now, false),
            ];
        }

        return [
            'title' => "Confirmer l'enregistrement",
            'campaign' => $this->campaign($plan, $now),
            'changes' => $this->changes($plan),
            'mail' => $mail,
            'none' => $none,
            'confirm_label' => $mail !== null ? 'Enregistrer et envoyer' : 'Enregistrer',
            'sends' => $mail !== null,
        ];
    }

    /**
     * What the page says once the save is done (D8): what left, and what
     * did not — never just « Campagne enregistrée ».
     */
    public function afterSave(ReenrollmentSavePlan $plan, \DateTimeImmutable $now): string
    {
        if (!$plan->sendsEmail()) {
            return "Enregistré. Aucun e-mail n'est parti : " . $this->reason($plan, $now, true);
        }

        $parts = [];
        foreach ($plan->emails as $email) {
            $parts[] = ucfirst(self::EMAIL_WHAT[$email['type']] ?? $email['type'])
                . ' est programmé pour ' . self::families($email['families'])
                . ($email['deferred'] ? " : il part dans l'heure" : ' : il part dans quelques minutes');
        }

        return 'Enregistré. ' . implode(' ; ', $parts) . '.';
    }

    /**
     * @return array{title: string, detail: string}|null
     */
    private function campaign(ReenrollmentSavePlan $plan, \DateTimeImmutable $now): ?array
    {
        $campaign = $plan->campaign;
        if ($campaign === null) {
            return null;
        }
        $close = self::day($campaign['closes']);
        $reminders = array_map(static fn (string $date): string => self::day($date), $campaign['reminders']);
        $remindersText = match (count($reminders)) {
            0 => 'aucun rappel',
            1 => 'rappel le ' . $reminders[0],
            default => 'rappels le ' . implode(', le ', array_slice($reminders, 0, -1)) . ' et le ' . end($reminders),
        };

        if ($plan->opening !== null) {
            $detail = 'Fermeture le ' . $close . ', ' . $remindersText . '.';
            $months = self::monthsUntil($now, $campaign['closes']);
            if (!$plan->opening['scheduled'] && $months !== null && $months >= 2) {
                $detail .= " La campagne restera ouverte jusqu'au " . $close . ', soit plus de ' . $months . ' mois.';
            }

            return [
                'title' => 'Ouvre la campagne de réinscription pour ' . $campaign['label'],
                'detail' => $detail,
            ];
        }

        return [
            'title' => 'Ferme la campagne de réinscription pour ' . $campaign['label'],
            'detail' => $campaign['closes'] < $now->format('Y-m-d')
                ? 'Elle devait se fermer le ' . $close . ', date déjà passée.'
                : 'Elle devait se fermer le ' . $close . '.',
        ];
    }

    /**
     * @return list<string>
     */
    private function changes(ReenrollmentSavePlan $plan): array
    {
        $lines = [];
        foreach ($plan->changes as $change) {
            $from = $change['from'] === '' ? '—' : $change['from'];
            $to = $change['to'] === '' ? '—' : $change['to'];
            $lines[] = match ($change['setting']) {
                'reminder_1_days', 'reminder_2_days' => self::SETTING_LABELS[$change['setting']]
                    . ' : ' . $from . ' → ' . $to . ' jours avant la fermeture',
                'emails_enabled' => 'E-mails de la campagne : '
                    . ($change['to'] === '1' ? 'désactivés → activés' : 'activés → désactivés'),
                'is_open' => 'Campagne : ' . ($change['to'] === '1' ? 'fermée → ouverte' : 'ouverte → fermée'),
                default => (self::SETTING_LABELS[$change['setting']] ?? $change['setting'])
                    . ' : ' . $from . ' → ' . $to,
            };
        }

        return $lines;
    }

    /**
     * Why nothing leaves — as a sentence for the dialog, or as the clause
     * after « Aucun e-mail n'est parti : » once saved.
     */
    private function reason(ReenrollmentSavePlan $plan, \DateTimeImmutable $now, bool $clause): string
    {
        $which = $plan->opening !== null ? 'opening' : ($plan->closing !== null ? 'closing' : null);
        $close = $plan->campaign !== null ? self::day($plan->campaign['closes']) : '';
        $emailsChange = null;
        foreach ($plan->changes as $change) {
            if ($change['setting'] === 'emails_enabled') {
                $emailsChange = $change['to'];
            }
        }

        $sentence = match ($plan->noEmailReason) {
            ReenrollmentSavePlan::REASON_NO_CHANGE => 'Aucun changement à enregistrer.',
            ReenrollmentSavePlan::REASON_EMAILS_DISABLED => $which === null
                ? 'Plus aucun e-mail ne partira, ni automatique ni à la demande.'
                : 'Les e-mails de la campagne sont désactivés : '
                    . ($which === 'opening' ? "l'ouverture" : 'la fermeture') . " n'écrit à personne.",
            ReenrollmentSavePlan::REASON_ALREADY_SENT => $which === 'closing'
                ? "L'e-mail de clôture de cette campagne est déjà parti : la refermer n'écrit à personne."
                : "L'e-mail d'ouverture de cette campagne est déjà parti : la rouvrir n'écrit à personne.",
            ReenrollmentSavePlan::REASON_CAMPAIGN_ENDED => 'La campagne est terminée depuis le ' . $close
                . " : personne n'est prévenu à nouveau.",
            ReenrollmentSavePlan::REASON_NOT_STARTED => "La campagne n'a pas encore commencé : personne n'a été "
                . "prévenu de son ouverture, personne ne l'est de sa fermeture.",
            ReenrollmentSavePlan::REASON_NO_CAMPAIGN => "Les dates ne désignent aucune campagne : aucun e-mail ne "
                . 'peut partir.',
            default => $emailsChange === '1'
                ? 'Rien ne part maintenant : les e-mails prévus partiront à leurs dates.'
                : ($emailsChange === '0'
                    ? 'Plus aucun e-mail ne partira, ni automatique ni à la demande.'
                    : 'Cet enregistrement ne change que des réglages : rien ne part maintenant.'),
        };

        return $clause ? lcfirst($sentence) : $sentence;
    }

    private static function audience(string $type, int $families): string
    {
        return $type === ReenrollmentCampaignService::EMAIL_OPENING
            ? 'à toutes les familles (' . self::families($families) . ')'
            : "aux familles qui n'ont pas répondu (" . self::families($families) . ')';
    }

    private static function families(int $count): string
    {
        return $count === 1 ? '1 famille' : $count . ' familles';
    }

    private static function day(string $ymd): string
    {
        return DateInput::parse('!Y-m-d', $ymd)?->format('d/m/Y') ?? $ymd;
    }

    private static function monthsUntil(\DateTimeImmutable $now, string $ymd): ?int
    {
        $until = DateInput::parse('!Y-m-d', $ymd);
        if ($until === null || $until < $now) {
            return null;
        }
        $diff = $now->diff($until);

        return $diff->y * 12 + $diff->m;
    }
}
