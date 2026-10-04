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
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentSavePlan;
use Modules\Registration\Service\ReenrollmentSavePlanPresenter;
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
    /** The dashboard: « État » and « Relancer maintenant » (issue #796, D9). */
    private const PAGE_URL = '/config/reinscription';
    /** The settings: dates, reminders, the two switches. */
    private const SETTINGS_URL = '/config/reinscription/reglages';

    /**
     * The fields a save carries, in the form's own names — what the
     * confirmation page posts back, unchanged, once the chief agrees.
     */
    private const FORM_FIELDS = [
        ReenrollmentCampaignService::SETTING_OPEN_AT,
        ReenrollmentCampaignService::SETTING_CLOSE_AT,
        ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS,
        ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS,
        ReenrollmentCampaignService::SETTING_EMAILS_ENABLED,
        'is_open',
    ];

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
        ?\Closure $clock = null,
        private ReenrollmentSavePlanPresenter $presenter = new ReenrollmentSavePlanPresenter()
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * GET /config/reinscription — the dashboard (issue #796, D9): where the
     * campaign of the target year stands, and « Relancer maintenant », each
     * step saying what went out or what is planned (D10).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $now = $this->now();
        $timeline = $this->campaign->timeline($now);
        $isOpen = $this->campaign->isOpen();
        $closeDate = $this->campaign->closeDate($now);

        return $this->render('@registration/reenrollment_config.html.twig', [
            'is_open' => $isOpen,
            'emails_enabled' => $this->campaign->emailsEnabled(),
            'timeline' => $timeline,
            'remind_question' => $this->remindQuestion($timeline),
            // « Clôture prévue » only while it is still ahead: the card
            // announced a close five months gone.
            'close_date' => $closeDate !== null && $closeDate->format('Y-m-d') >= $now->format('Y-m-d')
                ? $closeDate->format('d/m/Y')
                : null,
            'opened_early' => $isOpen && $timeline !== null && $timeline['opens'] !== null
                && $now->format('Y-m-d') < $timeline['opens'],
            'tracking' => $this->campaign->tracking(),
        ]);
    }

    /**
     * GET /config/reinscription/reglages — the dates, the reminders and the
     * two switches (issue #796, D9).
     *
     * @param array<string, string> $params
     */
    public function settings(Request $request, array $params): Response
    {
        $campaignKey = $this->campaign->currentCampaignKey($this->now());
        $setting = fn (string $key): string => (string) $this->settingService->get($key, 'registration', '');

        return $this->render('@registration/reenrollment_settings.html.twig', [
            'is_open' => $this->campaign->isOpen(),
            'emails_enabled' => $this->campaign->emailsEnabled(),
            'open_at' => $setting(ReenrollmentCampaignService::SETTING_OPEN_AT),
            'close_at' => $setting(ReenrollmentCampaignService::SETTING_CLOSE_AT),
            'reminder_1_days' => $setting(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS),
            'reminder_2_days' => $setting(ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS),
            // The campaign the switch opens and closes, named under it
            // (issue #796, D3): there is only one, the target year's.
            'campaign_label' => $campaignKey !== null
                ? $this->campaign->targetLabelOf($campaignKey)
                : null,
        ]);
    }

    /**
     * POST /config/reinscription/apercu — the plan of the form as it is
     * filled in, asked BEFORE it is saved (issue #796, D1, D2): what changes,
     * and whether an e-mail leaves, in the words of the dialog — with the
     * fingerprint the save will be checked against.
     *
     * The same plan, worded by the same presenter, is what save() shows a
     * browser without the script, so the question cannot be skipped and
     * cannot be different there.
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
        $now = $this->now();
        $plan = $this->planner->plan($values, $now);

        return $this->json([
            'success' => true,
            'changed' => $plan->hasChanges(),
            'fingerprint' => $plan->fingerprint(),
            'dialog' => $this->presenter->dialog($plan, $now),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::SETTINGS_URL)) !== null) {
            return $guard;
        }

        $values = $this->submittedValues(static fn(string $key): string => (string) $request->getBody($key, ''));

        // One plan (issue #796, D1): what this save writes to families,
        // computed once and applied below — never a second calculation
        // that could disagree with the first.
        $now = $this->now();
        $plan = $this->planner->plan($values, $now);

        if (!$plan->hasChanges()) {
            FlashMessage::set('warning', 'Aucun changement à enregistrer.');

            return $this->redirect(self::SETTINGS_URL);
        }

        // **Every save that changes something is confirmed, on the exact
        // plan** (issue #796, D2, D7). No fingerprint: the chief has not
        // been asked yet — a browser without the script, or a script that
        // could not reach /apercu — and is shown the confirmation here, by
        // the server. A fingerprint that no longer matches: something moved
        // between the question and the answer (a family answered, an e-mail
        // left, another chief saved), and the answer is to a question that
        // is no longer the one being asked. Nothing is written either way.
        $confirmed = (string) $request->getBody('plan_fingerprint', '');
        if ($confirmed !== $plan->fingerprint()) {
            return $this->confirmation($request, $plan, $now, $confirmed !== '');
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

        FlashMessage::set('success', $this->presenter->afterSave($plan, $now));

        return $this->redirect(self::SETTINGS_URL);
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
     * The confirmation, rendered by the server: the same dialog the script
     * shows, as a page, with the form's fields carried along unchanged and
     * the plan's fingerprint (issue #796, D7). « Annuler » goes back to the
     * page; nothing has been written.
     */
    private function confirmation(
        Request $request,
        ReenrollmentSavePlan $plan,
        \DateTimeImmutable $now,
        bool $stale
    ): Response
    {
        $fields = [];
        foreach (self::FORM_FIELDS as $name) {
            $fields[$name] = (string) $request->getBody($name, '');
        }

        return $this->render('@registration/reenrollment_confirm.html.twig', [
            'dialog' => $this->presenter->dialog($plan, $now),
            'fields' => $fields,
            'fingerprint' => $plan->fingerprint(),
            'stale' => $stale,
        ]);
    }

    /**
     * The « Relancer maintenant » question (issues #732, #796 D11): how many
     * families an e-mail reaches, and where the automatic reminders stand —
     * read off the same timeline as the box beside it, so the two can never
     * say different things.
     *
     * @param array{steps: list<array{type: string, state: string, date: ?string, at: ?\DateTimeImmutable,
     *     manual: bool}>}|null $timeline
     */
    private function remindQuestion(?array $timeline): string
    {
        $families = $this->planner->families(true);
        $question = match (true) {
            $families === 0 => "Aucune famille ne recevra de relance. ",
            $families === 1 => "1 e-mail va partir : une relance à la famille qui n'a pas encore répondu. "
                . "L'envoi est programmé : il part dans quelques minutes, et rien ici ne le rappelle.",
            default => $families . " e-mails vont partir : une relance à chaque famille qui n'a pas encore répondu. "
                . "L'envoi est programmé : il part dans quelques minutes, et rien ici ne le rappelle.",
        };

        $sent = [];
        $next = null;
        foreach ($timeline['steps'] ?? [] as $step) {
            if ($step['type'] !== ReenrollmentCampaignService::EMAIL_REMINDER_1
                && $step['type'] !== ReenrollmentCampaignService::EMAIL_REMINDER_2
            ) {
                continue;
            }
            if ($step['state'] === 'sent') {
                $sent[] = $step['at'];
            } elseif ($step['state'] === 'planned' && ($next === null || $step['date'] < $next)) {
                // The earliest date, not the first step met: the two delays
                // are independent, so the second reminder can come first.
                $next = $step['date'];
            }
        }

        $last = array_filter($sent);
        if ($last !== []) {
            $question .= ' Dernier rappel automatique envoyé le ' . max($last)->format('d/m/Y') . '.';
        } elseif ($sent !== []) {
            $question .= ' Un rappel automatique a déjà été envoyé, à une date inconnue.';
        } else {
            $question .= " Aucun rappel automatique n'a encore été envoyé.";
        }
        $question .= $next !== null
            ? ' Prochain rappel automatique prévu le '
                . (\Core\Service\DateInput::parse('!Y-m-d', $next)?->format('d/m/Y') ?? $next) . '.'
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
