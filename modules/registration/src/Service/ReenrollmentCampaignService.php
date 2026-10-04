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
     * Otherwise the campaign the opening belongs to — null when the dates
     * do not designate one, in which case no e-mail can follow — and
     * whether it is the scheduled opening rather than the switch. Whether
     * an e-mail then leaves is Service\ReenrollmentSavePlanner's answer.
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
        $today = $now->setTime(0, 0);
        $openAt = self::validMonthDay($openAt);
        $closeAt = self::validMonthDay($closeAt);

        if ($openAt !== null && $closeAt !== null) {
            $openOn = self::dateIn((int) $today->format('Y'), $openAt);
            if ($openOn !== null && $openOn->format('Y-m-d') === $today->format('Y-m-d')) {
                $key = self::keyForOpening($today, $closeAt);
                if ($key !== null && !$this->alreadyDone(self::MARKER_OPENED, $key)) {
                    return ['key' => $key, 'scheduled' => true];
                }
            }
        }

        if (!$switchOn) {
            return null;
        }

        return ['key' => self::keyForManualOpening($today, $openAt, $closeAt), 'scheduled' => false];
    }

    /**
     * The campaign a MANUAL opening opens, when it opens one that still
     * has a deadline ahead — the only kind an opening e-mail can announce.
     *
     * Inside a campaign's window, that campaign. Outside every window the
     * switch serves two purposes, and the calendar tells them apart: just
     * after a close it lets a late family back into the campaign that has
     * just ended, and ahead of the next opening date it opens that next
     * campaign early. Whichever of the two dates is nearer decides, a tie
     * going to the campaign just closed. Reopening a finished campaign is
     * null: an « ouverture » e-mail whose closing date has already passed
     * would announce a deadline that is behind everybody.
     */
    private static function keyForManualOpening(
        \DateTimeImmutable $today,
        ?string $openAt,
        ?string $closeAt
    ): ?string {
        if ($openAt === null || $closeAt === null) {
            return null;
        }

        $current = self::keyAt($today, $openAt, $closeAt);
        if ($current !== null && $current >= $today->format('Y-m-d')) {
            return $current;
        }

        $year = (int) $today->format('Y');
        $nextOpen = self::dateIn($year, $openAt);
        if ($nextOpen !== null && $nextOpen < $today) {
            $nextOpen = self::dateIn($year + 1, $openAt);
        }
        if ($nextOpen === null) {
            return null;
        }

        $lastClose = $current !== null ? DateInput::parse('!Y-m-d', $current) : null;
        if ($lastClose !== null && $today->diff($lastClose)->days <= $today->diff($nextOpen)->days) {
            return null;
        }

        return self::keyForOpening($nextOpen, $closeAt);
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
        $openAt = $this->monthDay(self::SETTING_OPEN_AT);
        if ($closeAt === null || $openAt === null) {
            return null;
        }

        return self::keyAt($now, $openAt, $closeAt);
    }

    /**
     * currentCampaignKey() for any pair of dates — the saved ones, or the
     * ones a chief is about to save. Null when either date is missing or
     * malformed.
     */
    public static function campaignKeyFor(\DateTimeImmutable $now, ?string $openAt, ?string $closeAt): ?string
    {
        $openAt = self::validMonthDay($openAt);
        $closeAt = self::validMonthDay($closeAt);

        return $openAt !== null && $closeAt !== null ? self::keyAt($now, $openAt, $closeAt) : null;
    }

    /**
     * The campaign a save that closes the switch closes: the window in
     * progress, or the next one when the switch opened it early — and only
     * when it really did, as its markers show. A switch left on past its
     * window is not an early opening: nobody was told the next campaign had
     * begun, so nobody is told it has closed.
     *
     * An opening e-mail still queued has not written its marker yet, so
     * `$openingInFlight` says whether it is on its way for a campaign key.
     *
     * @param \Closure(string $campaignKey): bool|null $openingInFlight
     */
    public function campaignKeyToClose(
        \DateTimeImmutable $now,
        ?string $openAt,
        ?string $closeAt,
        ?\Closure $openingInFlight = null
    ): ?string {
        $openAt = self::validMonthDay($openAt);
        $closeAt = self::validMonthDay($closeAt);
        if ($openAt === null || $closeAt === null) {
            return null;
        }

        $today = $now->setTime(0, 0);
        $current = self::keyAt($today, $openAt, $closeAt);
        if ($current !== null && $current >= $today->format('Y-m-d')) {
            return $current;
        }

        $next = self::keyForManualOpening($today, $openAt, $closeAt);

        $opened = $next !== null && ($this->openedBefore($next) || ($openingInFlight !== null && $openingInFlight($next)));

        return $opened ? $next : $current;
    }

    /** Whether the campaign `$key` was opened, by the clock or by hand: a marker says so. */
    private function openedBefore(string $key): bool
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

    private static function keyAt(\DateTimeImmutable $now, string $openAt, string $closeAt): ?string
    {
        $year = (int) $now->format('Y');
        foreach ([$year, $year - 1] as $candidateYear) {
            // A window that straddles New Year closes the calendar year after
            // it opens (open 11-01, close 02-15).
            $close = self::dateIn($closeAt >= $openAt ? $candidateYear : $candidateYear + 1, $closeAt);
            if ($close === null) {
                continue;
            }
            $openOn = self::dateIn($candidateYear, $openAt);
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

        // The window opened the calendar year before it closes when it
        // straddles New Year (open 11-01, close 02-15).
        $openOn = $openAt !== null
            ? self::dateIn((int) $close->format('Y') - ($close->format('m-d') < $openAt ? 1 : 0), $openAt)
            : null;
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

    private function openDateFor(int $year): ?\DateTimeImmutable
    {
        $openAt = $this->monthDay(self::SETTING_OPEN_AT);

        return $openAt !== null ? self::dateIn($year, $openAt) : null;
    }

    private static function dateIn(int $year, string $monthDay): ?\DateTimeImmutable
    {
        return DateInput::parse('!Y-m-d', sprintf('%04d-%s', $year, $monthDay));
    }

    /**
     * The close date of the campaign that opens on `$openDay` — the same
     * calendar year when the close month follows the open month, the next
     * one when the window straddles new year.
     */
    private function campaignKeyForOpening(\DateTimeImmutable $openDay): ?string
    {
        $closeAt = $this->monthDay(self::SETTING_CLOSE_AT);

        return $closeAt !== null ? self::keyForOpening($openDay, $closeAt) : null;
    }

    private static function keyForOpening(\DateTimeImmutable $openDay, string $closeAt): ?string
    {
        $year = (int) $openDay->format('Y');
        $close = self::dateIn($year, $closeAt);
        if ($close === null) {
            return null;
        }

        if ($close < $openDay) {
            $close = self::dateIn($year + 1, $closeAt);
        }

        return $close?->format('Y-m-d');
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
