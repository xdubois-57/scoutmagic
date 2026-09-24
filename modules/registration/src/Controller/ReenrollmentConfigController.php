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

    public function __construct(
        protected Environment $twig,
        private ReenrollmentCampaignService $campaign,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $closeDate = $this->campaign->closeDate();

        return $this->render('@registration/reenrollment_config.html.twig', [
            'is_open' => $this->campaign->isOpen(),
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
        $campaignKey = $this->campaign->currentCampaignKey();

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
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        foreach ([
            ReenrollmentCampaignService::SETTING_OPEN_AT => 'monthDay',
            ReenrollmentCampaignService::SETTING_CLOSE_AT => 'monthDay',
            ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS => 'days',
            ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS => 'days',
        ] as $key => $shape) {
            $value = trim((string) $request->getBody($key, ''));
            if ($shape === 'monthDay' && preg_match('/^\d{2}-\d{2}$/', $value) !== 1) {
                continue;
            }
            if ($shape === 'days' && !ctype_digit($value)) {
                continue;
            }

            $this->settingService->setInternal($key, $value, 'registration');
        }

        // The manual switch, both ways. It never touches the applied-on
        // markers, so opening early cannot make the scheduled transition
        // fire twice, and closing early cannot make it not fire at all.
        $wasOpen = $this->campaign->isOpen();
        $shouldBeOpen = (string) $request->getBody('is_open', '0') === '1';
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

            // A manual close sends the closing e-mail too — the roadmap is
            // explicit: it goes out when the campaign closes, however it
            // closed. The marker keeps it to once per campaign.
            $campaignKey = $this->campaign->currentCampaignKey();
            if (!$shouldBeOpen && $campaignKey !== null) {
                $this->queueEmails(ReenrollmentCampaignService::EMAIL_CLOSING, $campaignKey);
            }
        }

        FlashMessage::set('success', 'Campagne enregistrée.');

        return $this->redirect(self::PAGE_URL);
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

        $campaignKey = $this->campaign->currentCampaignKey();
        if ($campaignKey === null) {
            FlashMessage::set('error', "Aucune campagne en cours — vérifiez les dates d'ouverture et de fermeture.");

            return $this->redirect(self::PAGE_URL);
        }

        // A manual reminder is its own occurrence, so its reference
        // carries the moment it was asked for: two clicks a week apart are
        // two reminders, two clicks in one second are one.
        //
        // **Through seed(), not schedule().** That was the intent from the
        // start and the code did not have it: `schedule()` inserts
        // unconditionally, so two clicks in the same minute left two rows
        // under the same reference — and two reminders in every silent
        // family's inbox. `seed()` stands down when a row of that
        // reference is already queued or running, which is precisely the
        // sentence above.
        $this->schedulerService->seed(
            'registration',
            'send_reenrollment_emails',
            'manual:' . $campaignKey . ':' . (new \DateTimeImmutable())->format('Y-m-d-H-i'),
            new \DateTimeImmutable(),
            [
                'type' => ReenrollmentCampaignService::EMAIL_REMINDER_1,
                'campaign' => $campaignKey,
                'after_key' => 0,
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
