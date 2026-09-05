<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Registration\Service\ReenrollmentCampaignService;

/**
 * The reenrollment campaign's clock: opens it, closes it, and queues each
 * of its four e-mails exactly once.
 *
 * Polls hourly and re-schedules itself, the same shape as
 * Task\OpenRegistrationHandler — `poor_mans_cron` only advances on page
 * visits, so a handler may run many times in a day or not at all, and
 * everything it does has to be safe to attempt again.
 *
 * **Every action is guarded, and the guard is what makes the hourly
 * rhythm safe.** Opening and closing are keyed on the campaign's own
 * close date; the two reminders, which change no state of their own, are
 * keyed on the hand-over instead (see handOver() — that guard was
 * described here long before it existed, and its absence sent one
 * reminder per cron pass). Two runs on the same day open the campaign
 * once and hand over each e-mail once; a run that arrives late still does
 * what it should, and a run after a manual close does nothing.
 *
 * **No catch-up on the dates themselves.** A missed opening is missed
 * (roadmap IT-15): a campaign that opened four days late would send an
 * « ouverture » e-mail whose own deadline is already closer than it says,
 * and the manual switch exists for exactly that case.
 *
 * The sends themselves are another task (Task\SendReenrollmentEmailsHandler):
 * this one decides WHAT is due and hands over, so a long batch of e-mails
 * can never delay the next transition.
 */
class ReenrollmentCampaignHandler implements TaskHandlerInterface
{
    public const REFERENCE = 'poll';
    private const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));

        try {
            $this->run($context, $scheduler, new \DateTimeImmutable());
        } finally {
            // Re-armed whatever happened: a campaign whose poll died once
            // must not stop being a campaign.
            $scheduler->rearmAfter('registration', 'reenrollment_campaign', self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }

    private function run(TaskContext $context, SchedulerService $scheduler, \DateTimeImmutable $now): void
    {
        $campaign = self::campaignService($context);

        // ── opening ──────────────────────────────────────────────────
        $openingKey = $campaign->openingDueToday($now);
        if ($openingKey !== null && !$campaign->alreadyDone(ReenrollmentCampaignService::MARKER_OPENED, $openingKey)) {
            $campaign->open();
            $campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, $openingKey);
            $context->journal->log(
                'registration',
                'reenrollment_campaign_opened',
                'info',
                'Campagne de réinscription ouverte automatiquement',
                ['campaign' => $openingKey]
            );
            self::handOver($scheduler, $campaign, ReenrollmentCampaignService::EMAIL_OPENING, $openingKey);
        }

        $campaignKey = $campaign->currentCampaignKey($now);
        if ($campaignKey === null) {
            return;
        }

        // ── the two reminders ────────────────────────────────────────
        foreach ([
            ReenrollmentCampaignService::EMAIL_REMINDER_1,
            ReenrollmentCampaignService::EMAIL_REMINDER_2
        ] as $reminder) {
            $due = $campaign->reminderDate($reminder, $now);
            // Null means the setting is absent OR the date falls before
            // the campaign opened — the roadmap's own case: skipped
            // outright, never sent late.
            if ($due === null || $due->format('Y-m-d') !== $now->format('Y-m-d')) {
                continue;
            }
            if (!$campaign->isOpen()) {
                continue;
            }
            self::handOver($scheduler, $campaign, $reminder, $campaignKey);
        }

        // ── closing ──────────────────────────────────────────────────
        if ($campaign->closingDueToday($now) !== null
            && !$campaign->alreadyDone(ReenrollmentCampaignService::MARKER_CLOSED, $campaignKey)
        ) {
            $campaign->close();
            $campaign->markDone(ReenrollmentCampaignService::MARKER_CLOSED, $campaignKey);
            $context->journal->log(
                'registration',
                'reenrollment_campaign_closed',
                'info',
                'Campagne de réinscription clôturée automatiquement',
                ['campaign' => $campaignKey]
            );
            self::handOver($scheduler, $campaign, ReenrollmentCampaignService::EMAIL_CLOSING, $campaignKey);
        }
    }

    /**
     * Hands one e-mail type to the sending task — once per campaign.
     *
     * **This used to hand it over every time it was asked**, and this
     * handler is asked HOURLY. An opening and a closing survived that,
     * because each is guarded by the marker written the moment the
     * campaign changes state; the two reminders had no such moment, so a
     * reminder's day produced one hand-over per cron pass — up to
     * twenty-four copies of the same e-mail to every silent family in the
     * unit. The docblock here described the guard; the code did not have
     * it.
     *
     * Two questions make one hand-over, and both are needed:
     *
     * - has this e-mail already GONE OUT for this campaign? That is the
     *   sender's marker, written when the last batch finishes.
     * - is it going out right NOW? A unit of more than twenty-five
     *   families is a chain of batches, whose continuations carry a
     *   cursor in their reference — so the question is about the whole
     *   chain, not about its first row, which is `done` long before the
     *   last one runs.
     *
     * A manual reminder deliberately bypasses both: it carries its own
     * `manual:` reference, and a chef asking for one has answered the
     * question themselves (Controller\ReenrollmentConfigController).
     */
    public static function handOver(
        SchedulerService $scheduler,
        ReenrollmentCampaignService $campaign,
        string $type,
        string $campaignKey
    ): bool {
        if ($campaign->alreadyDone(ReenrollmentCampaignService::emailMarker($type), $campaignKey)) {
            return false;
        }

        $reference = $type . ':' . $campaignKey;
        if ($scheduler->hasLiveStartingWith('registration', 'send_reenrollment_emails', $reference)) {
            return false;
        }

        $scheduler->schedule(
            'registration',
            'send_reenrollment_emails',
            new \DateTimeImmutable(),
            ['type' => $type, 'campaign' => $campaignKey, 'after_key' => 0],
            $reference
        );

        return true;
    }

    public static function campaignService(TaskContext $context): ReenrollmentCampaignService
    {
        $pdo = $context->connection->getPdo();
        $sectionService = new \Core\Member\SectionService(
            $context->connection,
            $context->encryption,
            new \Core\Badge\MemberBadgeRepository($pdo)
        );
        $requestRepository = new \Modules\Registration\Repository\RegistrationRequestRepository($pdo,
            $context->encryption);

        return new ReenrollmentCampaignService(
            $context->settings,
            new \Core\ScoutYear\ScoutYearResolver(
                new \Core\Config\ScoutYearService($pdo),
                $context->settings,
                new \Core\Import\MemberYearRepository($pdo)
            ),
            new \Core\Config\ScoutYearService($pdo),
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $context->encryption),
            new \Modules\Registration\Service\PassageService(
                $pdo,
                $context->encryption,
                $sectionService,
                new \Modules\Registration\Repository\SectionTransferRepository($pdo),
                $requestRepository,
                new \Modules\Registration\Repository\AgeBracketRepository($pdo)
            )
        );
    }

    /**
     * Queue the very first poll. Idempotent, so calling it on every
     * request costs one indexed lookup and re-arms the chain by itself if
     * a run ever failed before scheduling its successor.
     *
     * Through seed() and not rearm(): rearm()'s guard only sees `pending`
     * rows, so every request landing while a cron pass held this chain's
     * row `processing` queued another copy — with run_at = now, hence
     * immediately overdue. This task is what ran 16 387 times in
     * forty-eight hours on a real installation. See SchedulerService::
     * seed().
     */
    public static function ensureScheduled(SchedulerService $scheduler): void
    {
        $scheduler->seed('registration', 'reenrollment_campaign', self::REFERENCE, new \DateTimeImmutable());
    }
}
