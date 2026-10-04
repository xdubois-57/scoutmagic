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
    /**
     * The one switch over the four e-mails and the manual reminder
     * (issue #732). On by default: a unit that set dates expects them.
     */
    public const SETTING_EMAILS_ENABLED = 'registration_reenrollment_emails_enabled';

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
     * Whether the campaign may write to families at all. Off, nothing goes
     * out: no opening, no reminder, no manual reminder, no closing.
     */
    public function emailsEnabled(): bool
    {
        return (string) $this->settingService->get(self::SETTING_EMAILS_ENABLED, 'registration', '1') !== '0';
    }

    /**
     * What saving a configuration would do to a CLOSED campaign right now
     * (issue #732) — asked before anything is saved, so a chief can be told
     * that an opening e-mail is about to leave, and can say no.
     *
     * Two ways a save opens a campaign today: the manual switch turned on,
     * or an opening date that makes the scheduled opening due TODAY (and
     * not already applied for that campaign). A date already in the past
     * opens nothing — a missed date is missed, as everywhere else here.
     *
     * Null when nothing would open (or the campaign is already open).
     * Otherwise the campaign the opening belongs to — always the campaign of
     * the target year (issue #796, D3), null only when the dates designate
     * none — and whether it is the scheduled opening rather than the
     * switch. Whether an e-mail then leaves is
     * Service\ReenrollmentSavePlanner's answer.
     *
     * @return array{key: ?string, scheduled: bool}|null
     */
    public function openingOnSave(
        ?string $openAt,
        ?string $closeAt,
        bool $switchOn,
        ?\DateTimeImmutable $now = null
    ): ?array {
        if ($this->isOpen()) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $key = $this->campaignKeyFor($now, $openAt, $closeAt);
        $openOn = $key !== null ? self::openingDateOf($key, (string) self::validMonthDay($openAt)) : null;

        if (
            $key !== null
            && $openOn !== null
            && $openOn->format('Y-m-d') === $now->format('Y-m-d')
            && !$this->alreadyDone(self::MARKER_OPENED, $key)
        ) {
            return ['key' => $key, 'scheduled' => true];
        }

        if (!$switchOn) {
            return null;
        }

        // **No distance rule any more** (issue #796, D3, D4). Outside the
        // dates, the switch used to guess between reopening the campaign
        // that had just ended and opening the next one early, by whichever
        // date was nearer — so the same click reopened May's campaign
        // without an e-mail until 7 October and wrote to every family about
        // next year's from the 8th, and nothing said so. There is now one
        // campaign the switch can open, the target year's: opened by hand,
        // it opens now, and keeps the close date, reminders and closing
        // e-mail of the settings, however far away they are.
        return ['key' => $key, 'scheduled' => false];
    }

    /**
     * The campaign of the year the families are asked about, as its own
     * close date (`Y-m-d`) — the key every marker is written against
     * (issue #796, D3).
     *
     * **One campaign at a time, and it is the target year's.** The target
     * year is the scout year after the public one (`years()`); its campaign
     * closes on the close date of the calendar year that target year starts
     * in — 2027-2028 closes on 2027-05-15. It does not depend on today: the
     * campaign is « the one for 2027-2028 » from the day the public year
     * becomes 2026-2027 until the day it becomes 2027-2028, before its dates,
     * during them and after them. That is what lets the page and every
     * e-mail name the year, and what makes a 2027-2028 label impossible to
     * pair with a 2026 date — the incident of issue #796.
     *
     * The key is still the close date, so every marker written before this
     * rule keeps its meaning (D5).
     *
     * **The day the public year changes.** Moving to the next public year
     * moves the target, and would replace the campaign in silence. A
     * campaign that is still running — its close date not reached, and
     * opened, by hand or by the clock — keeps being the current one until
     * it closes; only then does the next one take over.
     */
    public function currentCampaignKey(?\DateTimeImmutable $now = null): ?string
    {
        return $this->campaignKeyFor(
            $now ?? new \DateTimeImmutable(),
            $this->monthDay(self::SETTING_OPEN_AT),
            $this->monthDay(self::SETTING_CLOSE_AT)
        );
    }

    /**
     * currentCampaignKey() for any pair of dates — the saved ones, or the
     * ones a chief is about to save. Null when either date is missing or
     * malformed.
     */
    public function campaignKeyFor(\DateTimeImmutable $now, ?string $openAt, ?string $closeAt): ?string
    {
        $openAt = self::validMonthDay($openAt);
        $closeAt = self::validMonthDay($closeAt);
        if ($openAt === null || $closeAt === null) {
            return null;
        }

        $targetStart = (int) explode('-', $this->years()['target_label'])[0];
        $key = self::dateIn($targetStart, $closeAt)?->format('Y-m-d');

        $previous = self::dateIn($targetStart - 1, $closeAt)?->format('Y-m-d');
        if ($previous !== null && $previous >= $now->format('Y-m-d') && $this->stillRunning($previous)) {
            return $previous;
        }

        return $key;
    }

    /**
     * Whether the campaign `$key` has begun: its opening date is behind us,
     * or it was opened before it — by the clock or by the switch with its
     * opening e-mail. A campaign that has not begun has nobody to tell it
     * is over (issue #796): closing it writes to nobody.
     */
    public function hasStarted(string $key, \DateTimeImmutable $now): bool
    {
        $openOn = self::openingDateOf($key, (string) $this->monthDay(self::SETTING_OPEN_AT));
        if ($openOn !== null && $openOn->format('Y-m-d') <= $now->format('Y-m-d')) {
            return true;
        }

        return $this->stillRunning($key);
    }

    /**
     * Whether the campaign `$key` has been opened and has written to
     * families or opened by the clock — the campaigns a change of public
     * year must not replace before they close.
     */
    private function stillRunning(string $key): bool
    {
        if ($this->alreadyDone(self::MARKER_OPENED, $key)) {
            return true;
        }
        foreach ([self::EMAIL_OPENING, self::EMAIL_REMINDER_1, self::EMAIL_REMINDER_2] as $type) {
            if ($this->alreadyDone(self::emailMarker($type), $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The date the campaign `$key` opens: the opening day before its close
     * — the same calendar year, or the year before when the window
     * straddles new year (open in November, close in February).
     */
    public static function openingDateOf(string $key, string $openAt): ?\DateTimeImmutable
    {
        $close = DateInput::parse('!Y-m-d', $key);
        if ($close === null || self::validMonthDay($openAt) === null) {
            return null;
        }

        $open = self::dateIn((int) $close->format('Y'), $openAt);
        if ($open !== null && $open > $close) {
            $open = self::dateIn((int) $close->format('Y') - 1, $openAt);
        }

        return $open;
    }

    /**
     * The scout year label a campaign asks about: the one starting in the
     * calendar year the campaign closes (`2027-05-15` → `2027-2028`). Every
     * e-mail of the campaign carries this label, never one recomputed from
     * whatever the public year is when it leaves (issue #796).
     */
    public static function targetLabelOf(string $key): string
    {
        $year = (int) substr($key, 0, 4);

        return sprintf('%d-%d', $year, $year + 1);
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
        $key = $this->currentCampaignKey($now);
        if ($openAt === null || $key === null) {
            return null;
        }

        $openOn = self::openingDateOf($key, $openAt);

        return $openOn !== null && $openOn->format('Y-m-d') === $now->format('Y-m-d') ? $key : null;
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

        return self::reminderDueOn(
            $close,
            $this->monthDay(self::SETTING_OPEN_AT),
            (string) $this->settingService->get($setting, 'registration')
        );
    }

    /**
     * reminderDate() for any campaign and any settings — the stored ones,
     * or the ones a chief is about to save (Service\ReenrollmentSavePlanner).
     * Null when the delay is not a number, or when the date falls before
     * the campaign opened: skipped outright, never sent late.
     */
    public static function reminderDueOn(
        \DateTimeImmutable $close,
        ?string $openAt,
        string $daysBeforeClose
    ): ?\DateTimeImmutable {
        if (!is_numeric($daysBeforeClose)) {
            return null;
        }

        $due = $close->modify('-' . max(0, (int) $daysBeforeClose) . ' days');

        $openOn = $openAt !== null ? self::openingDateOf($close->format('Y-m-d'), $openAt) : null;
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

    public function markDone(string $marker, string $campaignKey, ?\DateTimeImmutable $at = null): void
    {
        // **One decision, not two.** Two `setInternal()` calls in a row let
        // the first commit and the second throw, leaving « this ran »
        // recorded without « when » — and the next pass would then read the
        // campaign as finished while the page had no date to show for it.
        $this->settingService->setManyInternal([
            $marker => $campaignKey,
            self::momentMarker($marker) => ($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], 'registration');
    }

    /**
     * **When** the thing the marker records actually happened.
     *
     * The marker itself holds a *campaign key* — the campaign's closing
     * date — which is what makes "has this already been sent for this
     * campaign?" answerable. Its setting is named `..._sent_on`, which
     * reads like a send date and is not one: a reminder queued in March
     * for a campaign closing in May stores `2027-05-15`.
     *
     * So the page could not show a chief when the last reminder went out,
     * and showing the marker instead would have displayed the closing
     * date under the word "sent" — a wrong answer looks worse than none.
     * This second setting holds the moment, and only `markDone()` writes
     * it, so the two cannot disagree.
     */
    private static function momentMarker(string $marker): string
    {
        return $marker . '_moment';
    }

    /**
     * The moment a marker was set **for this campaign**, or null.
     *
     * **The campaign key is not optional.** `markDone()` overwrites the
     * moment and never clears it, so a marker left over from last year's
     * campaign still carries last year's timestamp — and answering with it
     * would put « Envoyé le 14/03/2027 » under an email that has not gone
     * out for the campaign now open. That is the wrong answer this whole
     * setting exists to avoid, one campaign further along.
     *
     * So the moment is gated on exactly what `alreadyDone()` is gated on:
     * the marker naming *this* campaign.
     *
     * Null therefore means one of three things, and the caller cannot tell
     * them apart from here: never run, run for another campaign, or run
     * before the moment was recorded at all — which is why
     * `ReenrollmentConfigController` asks `alreadyDone()` separately rather
     * than reading « no moment » as « not sent ».
     */
    public function doneAt(string $marker, string $campaignKey): ?\DateTimeImmutable
    {
        if (!$this->alreadyDone($marker, $campaignKey)) {
            return null;
        }

        $stored = $this->settingService->get(self::momentMarker($marker), 'registration', '');

        return DateInput::fromStorage(is_string($stored) && $stored !== '' ? $stored : null);
    }

    /**
     * Where the two AUTOMATIC reminders stand, for the « Relancer
     * maintenant » question (issue #732): the last one that went out, and
     * the next one still to come.
     *
     * « Last » is a sent reminder of this campaign — with its moment when
     * it was recorded, `last_at` null otherwise. « Next » is the earliest
     * reminder due today or later that has not gone out; null when none is
     * left, or when the e-mails are switched off and none will go.
     *
     * @return array{last_sent: bool, last_at: ?\DateTimeImmutable, next: ?\DateTimeImmutable}
     */
    public function automaticReminders(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $key = $this->currentCampaignKey($now);

        $lastSent = false;
        $lastAt = null;
        $next = null;
        if ($key === null) {
            return ['last_sent' => false, 'last_at' => null, 'next' => null];
        }

        foreach ([self::EMAIL_REMINDER_1, self::EMAIL_REMINDER_2] as $type) {
            $marker = self::emailMarker($type);
            if ($this->alreadyDone($marker, $key)) {
                $lastSent = true;
                $at = $this->doneAt($marker, $key);
                if ($at !== null && ($lastAt === null || $at > $lastAt)) {
                    $lastAt = $at;
                }
                continue;
            }

            $due = $this->reminderDate($type, $now);
            if ($due !== null && $due >= $now->setTime(0, 0) && ($next === null || $due < $next)) {
                $next = $due;
            }
        }

        return [
            'last_sent' => $lastSent,
            'last_at' => $lastAt,
            'next' => $this->emailsEnabled() ? $next : null,
        ];
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
        $years = $this->years();
        $targetLabel = $years['target_label'];
        $targetYearId = $years['target_year_id'];

        // includeLeaving: a departure answer ticks the departure box
        // (roadmap IT-16), so without it the total would shrink by one
        // with every « il ne revient pas » received and the answer itself
        // would be discarded below as "no longer an animé".
        $animeMemberIds = [];
        foreach ($this->passageService->getAnimeMemberYears($years['public_year_id'], includeLeaving: true) as $row) {
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

    /**
     * The year whose animés are asked, and the year they are asked about —
     * one place for the tracking, the sender and the save plan, so the
     * three can never count different families.
     *
     * @return array{public_year_id: int, target_year_id: int, target_label: string}
     */
    public function years(): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel((string) $publicYear['label']);

        return [
            'public_year_id' => (int) $publicYear['id'],
            'target_year_id' => $this->scoutYearService->ensureYear($targetLabel),
            'target_label' => $targetLabel,
        ];
    }

    private static function dateIn(int $year, string $monthDay): ?\DateTimeImmutable
    {
        return DateInput::parse('!Y-m-d', sprintf('%04d-%s', $year, $monthDay));
    }

    private function monthDay(string $setting): ?string
    {
        return self::validMonthDay((string) ($this->settingService->get($setting, 'registration') ?: ''));
    }

    private static function validMonthDay(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
