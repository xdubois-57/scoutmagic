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

    /** The day a scout year starts, month and day. */
    private const SCOUT_YEAR_START = '09-01';

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
     * **The day the public year changes.** The target year moves, and the
     * campaign moves with it: the families' answers, the menu, the counts
     * and the e-mails all read the same target year, so a campaign cannot
     * outlive its own year. One still running at that moment ends without
     * a closing e-mail, and the next year's campaign takes over.
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
        $key = self::dateIn(self::closeYearFor($targetStart, $openAt, $closeAt), $closeAt)?->format('Y-m-d');

        return $key;
    }

    /**
     * Whether the campaign `$key` has begun: its opening date is behind us,
     * or it was opened before it — by the clock or by the switch with its
     * opening e-mail. A campaign that has not begun has nobody to tell it
     * is over (issue #796): closing it writes to nobody.
     *
     * `$openAt` is the opening date the campaign is judged by — the one
     * being saved when a save is planned, not the stored one the save is
     * about to replace.
     */
    public function hasStarted(string $key, \DateTimeImmutable $now, ?string $openAt = null): bool
    {
        $openAt = self::validMonthDay($openAt ?? $this->monthDay(self::SETTING_OPEN_AT));
        $openOn = $openAt !== null ? self::openingDateOf($key, $openAt) : null;
        if ($openOn !== null && $openOn->format('Y-m-d') <= $now->format('Y-m-d')) {
            return true;
        }

        return $this->stillRunning($key);
    }

    /**
     * Whether the campaign `$key` was opened: by the clock, or by the
     * switch with an e-mail that has gone out.
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
     * The scout year label a campaign asks about: the one that starts after
     * the campaign opened — in the calendar year it opens in for a spring
     * opening (`2027-05-15` opened on 03-01 → `2027-2028`), in the next one
     * for an autumn opening, which falls after 1 September (`2026-12-15`
     * opened on 10-01 → `2027-2028`). Every e-mail of the campaign carries
     * this label, never one recomputed from whatever the public year is
     * when it leaves (issue #796).
     *
     * `$openAt` is the opening date the campaign is read by — the one being
     * saved when a save is described, not the stored one it is about to
     * replace.
     */
    public function targetLabelOf(string $key, ?string $openAt = null): string
    {
        $openAt = self::validMonthDay($openAt ?? $this->monthDay(self::SETTING_OPEN_AT));
        $openOn = $openAt !== null ? self::openingDateOf($key, $openAt) : null;
        $start = $openOn !== null
            ? (int) $openOn->format('Y') + ($openAt >= self::SCOUT_YEAR_START ? 1 : 0)
            : (int) substr($key, 0, 4);

        return sprintf('%d-%d', $start, $start + 1);
    }

    /**
     * The calendar year the campaign for the scout year starting in
     * `$targetStart` closes in. The campaign is the last window that opens
     * before that year begins on 1 September: opened in the same calendar
     * year for a spring opening, in the autumn before for an autumn one, and
     * closed the year after it opened when it straddles New Year. Keyed on
     * the close date alone, a window lying wholly in autumn would sit a year
     * ahead of itself and never open.
     */
    private static function closeYearFor(int $targetStart, string $openAt, string $closeAt): int
    {
        $openYear = $openAt >= self::SCOUT_YEAR_START ? $targetStart - 1 : $targetStart;

        return $closeAt >= $openAt ? $openYear : $openYear + 1;
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
     * The campaign of the target year, step by step — the one source for the
     * « Relancer maintenant » box and its dialog (issue #796, D10, D11).
     *
     * Each of the four e-mails is in one of five states:
     *
     * - **sent** — with its moment, and `manual` when the opening went out
     *   before the opening date, by the switch;
     * - **planned** — with its date, today or later;
     * - **missed** — its date is behind us and it did not go: a missed date
     *   is missed, nothing is sent late;
     * - **skipped** — a reminder whose date falls before the opening, with
     *   that date, never sent;
     * - **off** — the campaign's e-mails are switched off.
     *
     * The reminder dates are those of THIS campaign — the dialog said « no
     * other reminder is planned » while the settings planned two, because
     * they were counted from the close of a campaign already over.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     opens: ?string,
     *     closes: string,
     *     opened_early_at: ?\DateTimeImmutable,
     *     opened_early: bool,
     *     started: bool,
     *     steps: list<array{type: string, state: string, date: ?string, at: ?\DateTimeImmutable, manual: bool}>,
     *     previous: ?array{label: string, closed_on: string}
     * }|null null when the dates designate no campaign
     */
    public function timeline(\DateTimeImmutable $now): ?array
    {
        $key = $this->currentCampaignKey($now);
        $openAt = $this->monthDay(self::SETTING_OPEN_AT);
        $close = $key !== null ? DateInput::parse('!Y-m-d', $key) : null;
        if ($key === null || $openAt === null || $close === null) {
            return null;
        }

        $today = $now->format('Y-m-d');
        $opens = self::openingDateOf($key, $openAt)?->format('Y-m-d');
        $emailsOn = $this->emailsEnabled();

        $steps = [];
        foreach ([self::EMAIL_OPENING, self::EMAIL_REMINDER_1, self::EMAIL_REMINDER_2, self::EMAIL_CLOSING] as $type) {
            $date = match ($type) {
                self::EMAIL_OPENING => $opens,
                self::EMAIL_CLOSING => $key,
                default => $this->rawReminderDate($type, $close),
            };
            if ($date === null && $type !== self::EMAIL_OPENING) {
                // No delay set: no such reminder in this campaign.
                continue;
            }
            $marker = self::emailMarker($type);
            $sent = $this->alreadyDone($marker, $key);
            $at = $sent ? $this->doneAt($marker, $key) : null;

            $state = match (true) {
                !$emailsOn && !$sent => 'off',
                $sent => 'sent',
                $type !== self::EMAIL_OPENING && $type !== self::EMAIL_CLOSING
                    && $opens !== null && $date < $opens => 'skipped',
                $date !== null && $date < $today => 'missed',
                default => 'planned',
            };

            $steps[] = [
                'type' => $type,
                'state' => $state,
                'date' => $date,
                'at' => $at,
                'manual' => $type === self::EMAIL_OPENING && $at !== null && $opens !== null
                    && $at->format('Y-m-d') < $opens,
            ];
        }

        $openingAt = $this->doneAt(self::emailMarker(self::EMAIL_OPENING), $key);
        // A campaign the switch has opened is under way, whatever its e-mails
        // did: the switch writes no marker, and with the e-mails off nothing
        // else does either — the page's « Ouverte » badge reads isOpen() too.
        $started = $this->hasStarted($key, $now) || $this->isOpen();
        $previousKey = $this->lastClosedCampaignBefore($key);
        // One answer for every surface of the page: opened before its
        // scheduled date, by the recorded opening e-mail or, when none was
        // recorded (the switch writes no marker), by the clock today.
        $openedEarly = $started && $opens !== null
            && ($openingAt !== null ? $openingAt->format('Y-m-d') : $now->format('Y-m-d')) < $opens;

        return [
            'key' => $key,
            'label' => $this->targetLabelOf($key),
            'opens' => $opens,
            'closes' => $key,
            'opened_early_at' => $openingAt !== null && $opens !== null && $openingAt->format('Y-m-d') < $opens
                ? $openingAt
                : null,
            'opened_early' => $openedEarly,
            'started' => $started,
            'steps' => $steps,
            // Between two campaigns, one grey line says how the last one
            // ended — what the box used to show in full, as if current.
            'previous' => !$started && $previousKey !== null
                ? ['label' => $this->targetLabelOf($previousKey['key']), 'closed_on' => $previousKey['on']]
                : null,
        ];
    }

    /**
     * The last campaign that really closed before the one keyed `$key`, as
     * recorded — by the clock's closing or by its closing e-mail — never
     * worked out from today's settings: a unit that has never run a campaign
     * has no previous one to speak of, and one that has moved its close date
     * since must not have the old campaign restated with the new one.
     *
     * `on` is the day the closing really happened, as its marker recorded it
     * — a campaign closed by hand ahead of its date, with the e-mails on,
     * leaves its closing e-mail and so reads the day the switch was turned
     * off, not the scheduled one. The scheduled date is the fallback when a
     * marker carries no moment. A campaign closed by hand with the e-mails
     * off leaves no mark, and so no line: a wrong answer looks worse than
     * none.
     *
     * @return array{key: string, on: string}|null
     */
    private function lastClosedCampaignBefore(string $key): ?array
    {
        $last = null;
        foreach ([self::MARKER_CLOSED, self::emailMarker(self::EMAIL_CLOSING)] as $marker) {
            $value = (string) $this->settingService->get($marker, 'registration', '');
            if ($value === '' || $value >= $key || ($last !== null && $value <= $last['key'])) {
                continue;
            }
            $last = [
                'key' => $value,
                'on' => $this->doneAt($marker, $value)?->format('Y-m-d') ?? $value,
            ];
        }

        return $last;
    }

    /**
     * A reminder's date in campaign `$close`, BEFORE the « skipped » rule —
     * the date the box shows beside « sauté ». Null when no delay is set.
     */
    private function rawReminderDate(string $type, \DateTimeImmutable $close): ?string
    {
        $raw = (string) $this->settingService->get(
            $type === self::EMAIL_REMINDER_1 ? self::SETTING_REMINDER_1_DAYS : self::SETTING_REMINDER_2_DAYS,
            'registration'
        );

        return is_numeric($raw) ? $close->modify('-' . max(0, (int) $raw) . ' days')->format('Y-m-d') : null;
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
