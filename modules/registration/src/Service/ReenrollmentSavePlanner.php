<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Service\DateInput;

/**
 * Computes Service\ReenrollmentSavePlan: what saving the reenrollment
 * configuration would write to families (issue #796, D1).
 *
 * It replaces `openingSendsEmail()` as the one source, builds on
 * `openingOnSave()`, and keeps the rules of both: an opening date of today opens now, a missed date
 * is missed, the switch opens a campaign that still has a deadline ahead,
 * and each e-mail goes out at most once per campaign.
 *
 * **What it adds is the closing.** Turning the switch off wrote the closing
 * e-mail with no question asked and no look at the calendar: in October,
 * « the current campaign » was still the one that had closed in May, and its
 * silent families were told, five months late, that it had just ended. A
 * campaign whose close date is behind us is over — closing it again writes
 * to nobody, exactly as the switch already refused to open one.
 *
 * **And what a save makes due later the same day.** An e-mail the save does
 * not send itself but makes « due today » leaves at the next hourly pass of
 * Task\ReenrollmentCampaignHandler — a reminder whose delay now lands on
 * today, a close date moved to today. The plan announces those too
 * (`deferred`): the chief's question is whether anything leaves, not
 * whether it leaves within the same second.
 */
class ReenrollmentSavePlanner
{
    /**
     * @param \Closure(bool $silentOnly): int $familyCounter how many families an
     *        e-mail of the campaign reaches — all of them for the opening, the
     *        silent ones for the rest
     * @param \Closure(string $type, string $campaignKey): bool|null $inFlight whether
     *        that e-mail of that campaign is queued and has not finished: a
     *        marker is only written once the batch has gone out, and
     *        handOver() would not queue it again, so the plan must neither
     *        announce it nor treat its campaign as unopened
     */
    public function __construct(
        private ReenrollmentCampaignService $campaign,
        private SettingService $settingService,
        private \Closure $familyCounter,
        private ?\Closure $inFlight = null
    ) {
    }

    /**
     * The counter the sender itself uses: the same recipients, the same
     * grouping by address, the same « silent » rule.
     */
    public static function countingWith(
        ReenrollmentCampaignService $campaign,
        SettingService $settingService,
        ReenrollmentRecipientService $recipients,
        ?\Core\Scheduler\SchedulerService $scheduler = null
    ): self {
        return new self(
            $campaign,
            $settingService,
            static function (bool $silentOnly) use ($campaign, $recipients): int {
                $years = $campaign->years();

                return count($recipients->pendingFamilies(
                    $years['public_year_id'],
                    $years['target_year_id'],
                    $silentOnly
                ));
            },
            // The guard Task\ReenrollmentCampaignHandler::handOver() itself
            // applies, read the same way.
            $scheduler === null ? null : static fn (string $type, string $key): bool => $scheduler->hasLiveStartingWith(
                'registration',
                'send_reenrollment_emails',
                $type . ':' . $key
            )
        );
    }

    /**
     * @param array{
     *     open_at: ?string,
     *     close_at: ?string,
     *     reminder_1_days: ?string,
     *     reminder_2_days: ?string,
     *     is_open: bool,
     *     emails_enabled: bool
     * } $submitted the form as save() reads it: a null date or delay keeps
     *               the stored one
     */
    public function plan(array $submitted, \DateTimeImmutable $now): ReenrollmentSavePlan
    {
        $before = $this->stored();
        $after = [
            'open_at' => $submitted['open_at'] ?? $before['open_at'],
            'close_at' => $submitted['close_at'] ?? $before['close_at'],
            'reminder_1_days' => $submitted['reminder_1_days'] ?? $before['reminder_1_days'],
            'reminder_2_days' => $submitted['reminder_2_days'] ?? $before['reminder_2_days'],
            'is_open' => $submitted['is_open'],
            'emails_enabled' => $submitted['emails_enabled'],
        ];

        $changes = $this->changes($before, $after);
        if ($changes === []) {
            return new ReenrollmentSavePlan([], null, null, [], ReenrollmentSavePlan::REASON_NO_CHANGE);
        }

        $today = $now->setTime(0, 0);
        $emails = [];
        $reasons = [];

        // ── an opening, by the switch or by an opening date of today ──
        $opening = $this->campaign->openingOnSave($after['open_at'], $after['close_at'], $after['is_open'], $now);
        if ($opening !== null) {
            $reasons[] = $this->queue(
                $emails,
                ReenrollmentCampaignService::EMAIL_OPENING,
                $opening['key'],
                $after['emails_enabled'],
                $today,
                false
            );
        }

        // ── a closing by the switch ───────────────────────────────────
        $closing = null;
        if ($before['is_open'] && !$after['is_open']) {
            $key = $this->campaign->campaignKeyFor($now, $after['open_at'], $after['close_at']);
            $closing = ['campaign' => $key];
            if (
                $key !== null
                && !$this->campaign->hasStarted($key, $now, $after['open_at'])
                && !$this->openingInFlight($key)
            ) {
                // A switch left on before its campaign began — closing it
                // tells nobody « it is over »: nobody was told it began.
                $reasons[] = ReenrollmentSavePlan::REASON_NOT_STARTED;
            } else {
                $reasons[] = $this->queue(
                    $emails,
                    ReenrollmentCampaignService::EMAIL_CLOSING,
                    $key,
                    $after['emails_enabled'],
                    $today,
                    false
                );
            }
        }

        // ── what the save makes due at the next hourly pass ───────────
        $dueAfter = $this->dueToday($after, $now);
        // With the e-mails off, nothing was going to leave: a save that turns
        // them on is what makes today's reminder or closing go out.
        $dueBefore = $before['emails_enabled'] ? $this->dueToday($before, $now) : [];
        foreach ($dueAfter as $type => $key) {
            if (isset($dueBefore[$type]) && $dueBefore[$type] === $key) {
                // Already due before this save: it leaves either way, and
                // it is not this save that sends it.
                continue;
            }
            $reasons[] = $this->queue($emails, $type, $key, $after['emails_enabled'], $today, true);
        }

        $reasons = array_values(array_filter($reasons));
        $concerned = $opening['key'] ?? $closing['campaign'] ?? null;

        return new ReenrollmentSavePlan(
            $changes,
            $opening !== null ? ['campaign' => $opening['key'], 'scheduled' => $opening['scheduled']] : null,
            $closing,
            $emails,
            $emails === [] ? ($reasons[0] ?? ReenrollmentSavePlan::REASON_SETTINGS_ONLY) : null,
            $concerned !== null ? $this->campaignSummary($concerned, $after) : null
        );
    }

    /**
     * The campaign `$key` as it will run once `$state` is saved: the year it
     * asks about, the day it opens and closes, and the reminders that will
     * actually go (a reminder falling before the opening is skipped).
     *
     * @param array{open_at: ?string, close_at: ?string, reminder_1_days: ?string,
     *     reminder_2_days: ?string, is_open: bool, emails_enabled: bool} $state
     * @return array{key: string, label: string, opens: ?string, closes: string, reminders: list<string>}
     */
    private function campaignSummary(string $key, array $state): array
    {
        $close = DateInput::parse('!Y-m-d', $key);
        $reminders = [];
        if ($close !== null) {
            foreach ([$state['reminder_1_days'], $state['reminder_2_days']] as $days) {
                $date = ReenrollmentCampaignService::reminderDueOn($close, $state['open_at'], (string) $days);
                if ($date !== null) {
                    $reminders[] = $date->format('Y-m-d');
                }
            }
        }
        sort($reminders);

        return [
            'key' => $key,
            'label' => $this->campaign->targetLabelOf($key),
            'opens' => ReenrollmentCampaignService::openingDateOf($key, (string) $state['open_at'])?->format('Y-m-d'),
            'closes' => $key,
            'reminders' => $reminders,
        ];
    }

    /**
     * Adds the e-mail `$type` of campaign `$key` to `$emails` when it would
     * leave, and says why not when it would not.
     *
     * @param list<array{type: string, campaign: string, families: int, deferred: bool}> $emails
     */
    private function queue(
        array &$emails,
        string $type,
        ?string $key,
        bool $emailsEnabled,
        \DateTimeImmutable $today,
        bool $deferred
    ): ?string {
        if ($key === null) {
            return ReenrollmentSavePlan::REASON_NO_CAMPAIGN;
        }
        // The most specific reason first: « already sent » says more than
        // « ended » about a campaign reopened after its close (D5).
        if (!$emailsEnabled) {
            return ReenrollmentSavePlan::REASON_EMAILS_DISABLED;
        }
        if (
            $this->campaign->alreadyDone(ReenrollmentCampaignService::emailMarker($type), $key)
            || ($this->inFlight !== null && ($this->inFlight)($type, $key))
        ) {
            // Gone, or on its way: either way handOver() will not queue it
            // a second time, so neither does the plan announce it.
            return ReenrollmentSavePlan::REASON_ALREADY_SENT;
        }
        if ($key < $today->format('Y-m-d')) {
            return ReenrollmentSavePlan::REASON_CAMPAIGN_ENDED;
        }
        foreach ($emails as $email) {
            if ($email['type'] === $type && $email['campaign'] === $key) {
                return null;
            }
        }

        $emails[] = [
            'type' => $type,
            'campaign' => $key,
            'families' => (int) ($this->familyCounter)($type !== ReenrollmentCampaignService::EMAIL_OPENING),
            'deferred' => $deferred,
        ];

        return null;
    }

    /**
     * The e-mails Task\ReenrollmentCampaignHandler would hand over today
     * under `$state`, by type, with their campaign — the reminders whose
     * date is today on an open campaign, and the closing when the close
     * date is today. Its own rules, read off the same helpers.
     *
     * @param array{open_at: ?string, close_at: ?string, reminder_1_days: ?string,
     *     reminder_2_days: ?string, is_open: bool, emails_enabled: bool} $state
     * @return array<string, string>
     */
    private function dueToday(array $state, \DateTimeImmutable $now): array
    {
        $key = $this->campaign->campaignKeyFor($now, $state['open_at'], $state['close_at']);
        if ($key === null) {
            return [];
        }
        $today = $now->format('Y-m-d');
        $close = DateInput::parse('!Y-m-d', $key);
        if ($close === null) {
            return [];
        }

        $due = [];
        if ($state['is_open']) {
            foreach ([
                ReenrollmentCampaignService::EMAIL_REMINDER_1 => $state['reminder_1_days'],
                ReenrollmentCampaignService::EMAIL_REMINDER_2 => $state['reminder_2_days'],
            ] as $type => $days) {
                $date = ReenrollmentCampaignService::reminderDueOn($close, $state['open_at'], (string) $days);
                if ($date !== null && $date->format('Y-m-d') === $today) {
                    $due[$type] = $key;
                }
            }
        }

        if (
            $key === $today
            && !$this->campaign->alreadyDone(ReenrollmentCampaignService::MARKER_CLOSED, $key)
        ) {
            $due[ReenrollmentCampaignService::EMAIL_CLOSING] = $key;
        }

        return $due;
    }

    /**
     * @return array{open_at: ?string, close_at: ?string, reminder_1_days: ?string,
     *     reminder_2_days: ?string, is_open: bool, emails_enabled: bool}
     */
    private function stored(): array
    {
        $read = function (string $key): ?string {
            $value = trim((string) $this->settingService->get($key, 'registration', ''));

            return $value === '' ? null : $value;
        };

        return [
            'open_at' => $read(ReenrollmentCampaignService::SETTING_OPEN_AT),
            'close_at' => $read(ReenrollmentCampaignService::SETTING_CLOSE_AT),
            'reminder_1_days' => $read(ReenrollmentCampaignService::SETTING_REMINDER_1_DAYS),
            'reminder_2_days' => $read(ReenrollmentCampaignService::SETTING_REMINDER_2_DAYS),
            'is_open' => $this->campaign->isOpen(),
            'emails_enabled' => $this->campaign->emailsEnabled(),
        ];
    }

    /**
     * @param array<string, string|bool|null> $before
     * @param array<string, string|bool|null> $after
     * @return list<array{setting: string, from: string, to: string}>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $setting => $value) {
            $from = self::shown($before[$setting]);
            $to = self::shown($value);
            if ($from !== $to) {
                $changes[] = ['setting' => $setting, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    private static function shown(string|bool|null $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * An opening e-mail queued by the switch and not finished has not
     * written its marker yet: the campaign has started all the same.
     */
    private function openingInFlight(string $campaignKey): bool
    {
        return $this->inFlight !== null
            && ($this->inFlight)(ReenrollmentCampaignService::EMAIL_OPENING, $campaignKey);
    }
}
