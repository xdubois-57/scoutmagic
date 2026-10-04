<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentSavePlan;
use Modules\Registration\Service\ReenrollmentSavePlanner;
use Twig\Environment;

/**
 * Espace chefs d'U > Réinscription (`role_min: admin`): the campaign's
 * window, its two reminder delays, the manual switch, and how far along it
 * is.
 *
 * **Beside « Inscriptions », not under Configuration.** The two pages are
 * the same job a year apart — deciding when the unit asks the families a
 * question and watching the answers come in — and that job belongs to the
 * chef d'unité, not to whoever administers the server. It sat in
 * Configuration at `superadmin`, which put a yearly campaign behind the
 * one role a unit may not have at hand. The path is unchanged: a page
 * that moves menu is not a page that moves address, and the bookmarks and
 * the help topic's `paths` both point here.
 *
 * **The tracking counts and never names.** How many families have
 * answered, how many animés are announced leaving, how many are still
 * silent — a page that listed them would be a list of children whose
 * parents have said they are leaving, sitting on a configuration screen.
 * The Passage and Départs pages are where individual decisions belong.
 *
 * **The manual reminder is unavailable on a closed campaign.** Reminding
 * somebody to answer a form they can no longer answer is worse than not
 * reminding them.
 */
class ReenrollmentConfigController extends AbstractController
{
    private const PAGE_URL = '/config/reinscription';

    private const OPENING_QUESTION = "Cette configuration va ouvrir la campagne de réinscription immédiatement. "
        . "Un e-mail d'ouverture sera envoyé aux familles concernées. Voulez-vous continuer ?";

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock « now », injectable so a
     *        test can stand in October as well as in April
     */
    public function __construct(
        protected Environment $twig,
        private ReenrollmentCampaignService $campaign,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private JournalService $journalService,
        private ReenrollmentSavePlanner $planner,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $closeDate = $this->campaign->closeDate($this->now());

        return $this->render('@registration/reenrollment_config.html.twig', [
            'is_open' => $this->campaign->isOpen(),
            'emails_enabled' => $this->campaign->emailsEnabled(),
            'remind_question' => $this->remindQuestion(),
            'open_at' => (string) $this->settingService->get(
                ReenrollmentCampaignService::SETTING_OPEN_AT,
                'registration',
                ''
            ),
            'close_at' => (string) $this->settingService->get(
                ReenrollmentCampaignService::SETTING_CLOSE_AT,
                'registration',
                ''
            ),
            'reminder_1_days' => (string) $this->settingService->get(
                ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS,
                'registration',
                ''
            ),
            'reminder_2_days' => (string) $this->settingService->get(
                ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS,
                'registration',
                ''
            ),
            'close_date' => $closeDate?->format('d/m/Y'),
            // When each email of this campaign actually went out. A chief
            // who has just clicked « Relancer » gets a scheduled job and a
            // success message; without this the page never told them
            // whether it ran, so the only way to find out was to click
            // again.
            'emails' => $this->emailStates(),
            'tracking' => $this->campaign->tracking(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * Where each of the campaign's four emails stands, keyed by type.
     *
     * **Two facts, not one.** « Sent » and « sent at » come apart in a case
     * that exists on any installation upgrading mid-campaign: the marker
     * says the email went out, and the moment beside it was introduced
     * after it did, so there is nothing to read. Collapsing the two would
     * print « Pas encore envoyé » under an email a chief watched leave —
     * a wrong answer, which is what this whole block was added to stop.
     *
     * Both are scoped to the campaign now open. A marker from last year's
     * campaign is not this campaign's news.
     *
     * @return array<string, array{sent: bool, at: ?\DateTimeImmutable}>
     */
    private function emailStates(): array
    {
        $campaignKey = $this->campaign->currentCampaignKey($this->now());

        $states = [];
        foreach ([
            ReenrollmentCampaignService::EMAIL_OPENING,
            ReenrollmentCampaignService::EMAIL_REMINDER_1,
            ReenrollmentCampaignService::EMAIL_REMINDER_2,
            ReenrollmentCampaignService::EMAIL_CLOSING,
        ] as $type) {
            $marker = ReenrollmentCampaignService::emailMarker($type);

            $states[$type] = $campaignKey === null
                ? ['sent' => false, 'at' => null]
                : [
                    'sent' => $this->campaign->alreadyDone($marker, $campaignKey),
                    'at' => $this->campaign->doneAt($marker, $campaignKey),
                ];
        }

        return $states;
    }

    /**
     * POST /config/reinscription/apercu — what saving the form as it is
     * filled in would set off, asked BEFORE it is saved (issue #732).
     *
     * Answers with the question to put to the chief, or null when there is
     * nothing to ask. The same computation guards save() itself, so a
     * browser without this script cannot skip the question either.
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $values = $this->submittedValues(static fn(string $key): string => (string) ($data[$key] ?? ''));

        return $this->json([
            'success' => true,
            'confirm' => self::needsOpeningConfirmation($this->planner->plan($values, $this->now()))
                ? self::OPENING_QUESTION
                : null,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        $values = $this->submittedValues(static fn(string $key): string => (string) $request->getBody($key, ''));

        // One plan (issue #796, D1): what this save writes to families,
        // computed once and applied below — never a second calculation
        // that could disagree with the first.
        $now = $this->now();
        $plan = $this->planner->plan($values, $now);

        // Asked before anything is written (issue #732): a save that opens
        // the campaign AND writes to every family needs the chief to have
        // said yes. Without it, nothing is saved and nothing opens.
        if (self::needsOpeningConfirmation($plan) && (string) $request->getBody('confirm_opening', '') !== '1') {
            FlashMessage::set(
                'error',
                "Rien n'a été enregistré : cette configuration ouvre la campagne et envoie l'e-mail d'ouverture "
                    . 'aux familles, ce qui doit être confirmé.'
            );

            return $this->redirect(self::PAGE_URL);
        }

        foreach ([
            ReenrollmentCampaignService::SETTING_OPEN_AT => $values['open_at'],
            ReenrollmentCampaignService::SETTING_CLOSE_AT => $values['close_at'],
            ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => $values['reminder_1_days'],
            ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS => $values['reminder_2_days'],
        ] as $key => $value) {
            if ($value !== null) {
                $this->settingService->setInternal($key, $value, 'registration');
            }
        }
        $this->settingService->setInternal(
            ReenrollmentCampaignService::SETTING_EMAILS_ENABLED,
            $values['emails_enabled'] ? '1' : '0',
            'registration'
        );

        // The manual switch, both ways. It never touches the applied-on
        // markers, so opening early cannot make the scheduled transition
        // fire twice, and closing early cannot make it not fire at all.
        $wasOpen = $this->campaign->isOpen();
        $shouldBeOpen = $values['is_open'];
        if ($shouldBeOpen !== $wasOpen) {
            $shouldBeOpen ? $this->campaign->open() : $this->campaign->close();

            $this->journalService->log(
                'registration',
                $shouldBeOpen ? 'reenrollment_campaign_opened' : 'reenrollment_campaign_closed',
                'info',
                $shouldBeOpen
                    ? 'Campagne de réinscription ouverte manuellement'
                    : 'Campagne de réinscription clôturée manuellement',
                [],
                AuthSession::getUserAccountId()
            );
        } elseif ($plan->opening !== null && $plan->opening['scheduled'] && $plan->opening['campaign'] !== null) {
            // The opening date just saved is today: the scheduled opening
            // happens now rather than at the next hourly pass, so what the
            // chief confirmed is what they see — and through the same
            // marker the clock would have written, so it never fires again.
            $this->campaign->open();
            $this->campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, $plan->opening['campaign']);
            $this->journalService->log(
                'registration',
                'reenrollment_campaign_opened',
                'info',
                "Campagne de réinscription ouverte (date d'ouverture du jour)",
                ['campaign' => $plan->opening['campaign']],
                AuthSession::getUserAccountId()
            );
        }

        // Exactly the e-mails the plan announced as leaving now, and no
        // other. A closing whose campaign ended months ago is not among
        // them (issue #796): nobody is told « it has just closed » about a
        // deadline five months behind them. What the plan announced as
        // deferred leaves at the next hourly pass, through the same guard.
        foreach ($plan->emails as $email) {
            if (!$email['deferred']) {
                $this->queueEmails($email['type'], $email['campaign']);
            }
        }

        FlashMessage::set('success', 'Campagne enregistrée.');

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * The form, read the one way both save() and preview() read it. A date
     * or a number that does not have the expected shape is null — the
     * stored value stays, as it always has.
     *
     * @param callable(string): string $read
     * @return array{
     *     open_at: ?string,
     *     close_at: ?string,
     *     reminder_1_days: ?string,
     *     reminder_2_days: ?string,
     *     is_open: bool,
     *     emails_enabled: bool
     * }
     */
    private function submittedValues(callable $read): array
    {
        $monthDay = static function (string $value): ?string {
            $value = trim($value);

            return preg_match('/^\d{2}-\d{2}$/', $value) === 1 ? $value : null;
        };
        $days = static function (string $value): ?string {
            $value = trim($value);

            return ctype_digit($value) ? $value : null;
        };

        return [
            'open_at' => $monthDay($read(ReenrollmentCampaignService::SETTING_OPEN_AT)),
            'close_at' => $monthDay($read(ReenrollmentCampaignService::SETTING_CLOSE_AT)),
            'reminder_1_days' => $days($read(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS)),
            'reminder_2_days' => $days($read(ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS)),
            'is_open' => $read('is_open') === '1',
            // '0' and '1' are the form's two answers; nothing at all is a
            // post that did not carry the switch, which changes nothing.
            'emails_enabled' => match ($read(ReenrollmentCampaignService::SETTING_EMAILS_ENABLED)) {
                '1' => true,
                '0' => false,
                default => $this->campaign->emailsEnabled(),
            },
        ];
    }

    /**
     * Whether `$plan` is the one case the opening question covers: a save
     * that opens the campaign now and writes the opening e-mail now.
     */
    private static function needsOpeningConfirmation(ReenrollmentSavePlan $plan): bool
    {
        $email = $plan->email(ReenrollmentCampaignService::EMAIL_OPENING);

        return $email !== null && !$email['deferred'];
    }

    /**
     * The « Relancer maintenant » question (issue #732): that an e-mail
     * leaves, to whom, and where the automatic reminders stand, so a chief
     * can see a scheduled reminder is two days away before sending one now.
     */
    private function remindQuestion(): string
    {
        $question = "Un e-mail de relance va être envoyé à chaque famille qui n'a pas encore répondu. "
            . "L'envoi est programmé : il part dans quelques minutes, et rien ici ne le rappelle.";

        $reminders = $this->campaign->automaticReminders($this->now());
        if ($reminders['last_at'] !== null) {
            $question .= ' Dernier rappel automatique envoyé le ' . $reminders['last_at']->format('d/m/Y') . '.';
        } elseif ($reminders['last_sent']) {
            $question .= ' Un rappel automatique a déjà été envoyé, à une date inconnue.';
        } else {
            $question .= " Aucun rappel automatique n'a encore été envoyé.";
        }
        $question .= $reminders['next'] !== null
            ? ' Prochain rappel automatique prévu le ' . $reminders['next']->format('d/m/Y') . '.'
            : " Aucun autre rappel automatique n'est prévu.";

        return $question . ' Relancer maintenant ?';
    }

    /**
     * POST /config/reinscription/relance — write again to whoever still
     * owes an answer, now.
     *
     * @param array<string, string> $params
     */
    public function remind(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        if (!$this->campaign->isOpen()) {
            FlashMessage::set(
                'error',
                "La campagne est fermée : relancer une famille vers un formulaire qu'elle ne peut plus remplir ne "
                    . "l'aiderait pas."
            );

            return $this->redirect(self::PAGE_URL);
        }

        if (!$this->campaign->emailsEnabled()) {
            FlashMessage::set(
                'error',
                "Les e-mails de la campagne sont désactivés : aucune relance n'est envoyée."
            );

            return $this->redirect(self::PAGE_URL);
        }

        $campaignKey = $this->campaign->currentCampaignKey($this->now());
        if ($campaignKey === null) {
            FlashMessage::set('error', "Aucune campagne en cours — vérifiez les dates d'ouverture et de fermeture.");

            return $this->redirect(self::PAGE_URL);
        }

        // A manual reminder is its own occurrence, so its reference
        // carries the moment it was asked for: two clicks a week apart are
        // two reminders, two clicks in one minute are one. The occurrence
        // travels in the payload too (issue #732): the sender claims each
        // family under it and writes no automatic marker, so a manual
        // reminder neither replaces nor blocks a scheduled one.
        //
        // **Through seed(), not schedule().** That was the intent from the
        // start and the code did not have it: `schedule()` inserts
        // unconditionally, so two clicks in the same minute left two rows
        // under the same reference — and two reminders in every silent
        // family's inbox. `seed()` stands down when a row of that
        // reference is already queued or running, which is precisely the
        // sentence above.
        $occurrence = $this->now()->format('Y-m-d-H-i');
        $this->schedulerService->seed(
            'registration',
            'send_reenrollment_emails',
            'manual:' . $campaignKey . ':' . $occurrence,
            new \DateTimeImmutable(),
            [
                'type' => ReenrollmentCampaignService::EMAIL_REMINDER_1,
                'campaign' => $campaignKey,
                'after_key' => 0,
                'occurrence' => $occurrence,
            ]
        );

        $this->journalService->log(
            'registration',
            'reenrollment_manual_reminder',
            'info',
            'Relance manuelle des familles sans réponse',
            ['campaign' => $campaignKey],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set(
            'success',
            'Relance programmée : les familles sans réponse la recevront dans quelques minutes.'
        );

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * The same hand-over the scheduled clock performs, through the same
     * guard — a chef who closes the campaign, notices, re-opens it and
     * closes it again owes the families ONE closing e-mail, not two.
     *
     * Written out here as a second `schedule()` call, it was two.
     */
    private function queueEmails(string $type, string $campaignKey): void
    {
        \Modules\Registration\Task\ReenrollmentCampaignHandler::handOver(
            $this->schedulerService,
            $this->campaign,
            $type,
            $campaignKey
        );
    }
}
