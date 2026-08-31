<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Service\DateInput;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * The reenrollment campaign: when it is open, who still owes an answer,
 * and how far along it is.
 *
 * **The window is a recurring MM-DD pair, never a date with a year** —
 * the same convention as `registration_scheduled_open_at` and
 * `SlotService::referenceMonthDay()`, so one configuration fires every
 * scout year without a chief re-entering it. A campaign is identified by
 * its own CLOSE date (`Y-m-d`): that is what the applied-on markers store,
 * and what makes "at most once per campaign, per e-mail" exact.
 *
 * **No catch-up.** `Task\OpenRegistrationHandler` has a configurable
 * catch-up window because a missed opening of the public form costs a
 * unit its whole intake. A missed reenrollment date costs nothing that
 * cannot be fixed with the manual switch, and a campaign that opened four
 * days late would send an « ouverture » e-mail whose own deadline is
 * already closer than it says. A missed date is missed (roadmap IT-15).
 *
 * **The manual switch always wins**, in both directions: it is how a unit
 * opens early, and how it lets one late family back in. Flipping it never
 * touches the applied-on markers, so it can never make the scheduled
 * transition fire twice or not at all.
 */
class ReenrollmentCampaignService
{
    public const SETTING_OPEN = 'registration_reenrollment_open';
    public const SETTING_OPEN_AT = 'registration_reenrollment_open_at';
    public const SETTING_CLOSE_AT = 'registration_reenrollment_close_at';
    public const SETTING_REMINDER_1_DAYS = 'registration_reenrollment_reminder_1_days';
    public const SETTING_REMINDER_2_DAYS = 'registration_reenrollment_reminder_2_days';

    public const MARKER_OPENED = 'registration_reenrollment_open_applied_on';
    public const MARKER_CLOSED = 'registration_reenrollment_close_applied_on';

    /** The four e-mails, in the order a campaign sends them. */
    public const EMAIL_OPENING = 'opening';
    public const EMAIL_REMINDER_1 = 'reminder_1';
    public const EMAIL_REMINDER_2 = 'reminder_2';
    public const EMAIL_CLOSING = 'closing';

    public function __construct(
        private SettingService $settingService,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private ReenrollmentRepository $repository,
        private PassageService $passageService
    ) {
    }

    public function isOpen(): bool
    {
        return (string) $this->settingService->get(self::SETTING_OPEN, 'registration', '0') === '1';
    }

    /**
     * The campaign a given moment belongs to, as its own close date
     * (`Y-m-d`) — the key every marker is written against.
     *
     * The close date of the campaign whose window CONTAINS or most
     * recently preceded `$now`: a campaign that opens on 01-03 and closes
     * on 15-05 is "the 2027 one" from March until the following March,
     * which is what makes a closing e-mail sent on the 16th belong to the
     * campaign that just ended rather than to next year's.
     */
    public function currentCampaignKey(?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable();
        $closeAt = $this->monthDay(self::SETTING_CLOSE_AT);
        if ($closeAt === null) {
            return null;
        }

        $year = (int) $now->format('Y');
        foreach ([$year, $year - 1] as $candidateYear) {
            $close = DateInput::parse('!Y-m-d', sprintf('%04d-%s', $candidateYear, $closeAt));
            if ($close === null) {
                continue;
            }
            $openOn = $this->openDateFor($candidateYear);
            if ($openOn !== null && $now->setTime(0, 0) >= $openOn) {
                return $close->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * The date the current campaign closes, for the page to show and for
     * the reminders to count back from.
     */
    public function closeDate(?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $key = $this->currentCampaignKey($now);

        return $key !== null ? DateInput::parse('!Y-m-d', $key) : null;
    }

    /**
     * Whether the scheduled OPENING is due today, and for which campaign.
     *
     * Strictly today, never a window: a missed date is missed.
     */
    public function openingDueToday(?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable();
        $openAt = $this->monthDay(self::SETTING_OPEN_AT);
        if ($openAt === null) {
            return null;
        }

        $today = $now->setTime(0, 0);
        $openOn = $this->openDateFor((int) $today->format('Y'));
        if ($openOn === null || $openOn->format('Y-m-d') !== $today->format('Y-m-d')) {
            return null;
        }

        return $this->campaignKeyForOpening($today);
    }

    /**
     * Whether the scheduled CLOSING is due today, and for which campaign.
     */
    public function closingDueToday(?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable();
        $key = $this->currentCampaignKey($now);

        return $key !== null && $key === $now->format('Y-m-d') ? $key : null;
    }

    /**
     * The date one of the two reminders is due, or null when the setting
     * is absent — or when the date it computes falls BEFORE the campaign
     * opened, which is the one case the roadmap singles out: a reminder
     * that would have been due before anybody could answer is skipped
     * outright, never sent late.
     */
    public function reminderDate(string $which, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $close = $this->closeDate($now);
        if ($close === null) {
            return null;
        }

        $setting = $which === self::EMAIL_REMINDER_1 ? self::SETTING_REMINDER_1_DAYS : self::SETTING_REMINDER_2_DAYS;
        $raw = $this->settingService->get($setting, 'registration');
        if (!is_numeric((string) $raw)) {
            return null;
        }

        $due = $close->modify('-' . max(0, (int) $raw) . ' days');

        $openOn = $this->openDateFor((int) $close->format('Y'));
        if ($openOn !== null && $due < $openOn) {
            return null;
        }

        return $due;
    }

    /**
     * Marks a transition or an e-mail as done for `$campaignKey`, and says
     * whether it had already been done.
     *
     * The whole idempotence of the campaign rests here: `poor_mans_cron`
     * only advances on page visits, so a handler may run many times in a
     * day or not at all, and it must never send twice.
     */
    public function alreadyDone(string $marker, string $campaignKey): bool
    {
        return (string) $this->settingService->get($marker, 'registration', '') === $campaignKey;
    }

    public function markDone(string $marker, string $campaignKey): void
    {
        $this->settingService->setInternal($marker, $campaignKey, 'registration');
    }

    public static function emailMarker(string $type): string
    {
        return 'registration_reenrollment_' . $type . '_sent_on';
    }

    public function open(): void
    {
        $this->settingService->setInternal(self::SETTING_OPEN, '1', 'registration');
    }

    public function close(): void
    {
        $this->settingService->setInternal(self::SETTING_OPEN, '0', 'registration');
    }

    /**
     * How far along the campaign is — counts only, never a name
     * (SECURITY.md §11 applies to the journal, and this page follows the
     * same rule because there is no reason for it not to).
     *
     * @return array{total: int, answered: int, leaving: int, silent: int, target_year_label: string}
     */
    public function tracking(): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel((string) $publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);

        $animeMemberIds = [];
        foreach ($this->passageService->getAnimeMemberYears((int) $publicYear['id']) as $row) {
            $animeMemberIds[(int) $row['member_id']] = true;
        }

        $answers = $this->repository->findAnswersForYear($targetYearId);

        $answered = 0;
        $leaving = 0;
        foreach ($answers as $memberId => $answer) {
            if (!isset($animeMemberIds[$memberId])) {
                // An answer for somebody who is no longer an animé — they
                // left, or their function changed since. Counted nowhere
                // rather than inflating a total nobody could reconcile.
                continue;
            }
            $answered++;
            if (!$answer->isReenrolled()) {
                $leaving++;
            }
        }

        $total = count($animeMemberIds);

        return [
            'total' => $total,
            'answered' => $answered,
            'leaving' => $leaving,
            'silent' => max(0, $total - $answered),
            'target_year_label' => $targetLabel,
        ];
    }

    private function openDateFor(int $year): ?\DateTimeImmutable
    {
        $openAt = $this->monthDay(self::SETTING_OPEN_AT);

        return $openAt !== null ? DateInput::parse('!Y-m-d', sprintf('%04d-%s', $year, $openAt)) : null;
    }

    /**
     * The close date of the campaign that opens on `$openDay` — the same
     * calendar year when the close month follows the open month, the next
     * one when the window straddles new year.
     */
    private function campaignKeyForOpening(\DateTimeImmutable $openDay): ?string
    {
        $closeAt = $this->monthDay(self::SETTING_CLOSE_AT);
        if ($closeAt === null) {
            return null;
        }

        $year = (int) $openDay->format('Y');
        $close = DateInput::parse('!Y-m-d', sprintf('%04d-%s', $year, $closeAt));
        if ($close === null) {
            return null;
        }

        if ($close < $openDay) {
            $close = DateInput::parse('!Y-m-d', sprintf('%04d-%s', $year + 1, $closeAt));
        }

        return $close?->format('Y-m-d');
    }

    private function monthDay(string $setting): ?string
    {
        $value = trim((string) ($this->settingService->get($setting, 'registration') ?: ''));

        return preg_match('/^\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
