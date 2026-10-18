<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\AppClock;
use Core\Config\SettingService;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Registration requests left waiting (issue #718 § C point 5): ONE point for
 * all of them, counting only requests pending for more than a number of
 * days, and only from a day of the year onwards.
 *
 * Both are settings, because both are a unit's own rhythm. The date is a
 * day and a month with no year (« 01/06 », the 1st of June, by default):
 * it opens the period within the current scout year — September to August
 * — from which a family still waiting is worth a line on this page, and the
 * period closes with the scout year. Before it, requests are expected to
 * pile up while the staffs plan the year, and a point would be noise.
 * The age (15 days by default) leaves aside what simply arrived recently.
 */
class RegistrationAttentionProvider implements AttentionPointProvider
{
    public const SETTING_FROM = 'registration_attention_from';
    public const SETTING_AGE_DAYS = 'registration_attention_age_days';

    private const DEFAULT_FROM = '01/06';
    private const DEFAULT_AGE_DAYS = 15;

    /** The month a scout year starts in. */
    private const SCOUT_YEAR_FIRST_MONTH = 9;

    public function __construct(
        private RegistrationRequestRepository $requests,
        private SettingService $settingService,
        /** Tests only: the moment to read the period and the age from. */
        private ?\DateTimeImmutable $now = null
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Inscriptions';
    }

    public function collect(int $scoutYearId): array
    {
        $now = $this->now ?? AppClock::now();
        if ($now < $this->periodStart($now)) {
            return [];
        }

        $days = $this->ageDays();
        $count = $this->requests->countPendingReceivedBefore(
            $now->modify('-' . $days . ' days')->format('Y-m-d H:i:s')
        );
        if ($count === 0) {
            return [];
        }

        return [new AttentionPoint(
            title: $count === 1
                ? 'Une demande d\'inscription attend depuis plus de ' . $days . ' jours'
                : $count . ' demandes d\'inscription attendent depuis plus de ' . $days . ' jours',
            why: 'Les familles concernées ne savent toujours pas si leur enfant a une place. Acceptez ou refusez '
                . 'ces demandes, ou retirez celles qui n\'ont plus lieu d\'être.',
            actionLabel: 'Voir les demandes',
            actionUrl: '/config/inscriptions/demandes',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    /**
     * The configured day and month, placed in the scout year $now belongs to.
     */
    private function periodStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        [$day, $month] = $this->fromDayAndMonth();
        $scoutYearStart = (int) $now->format('n') >= self::SCOUT_YEAR_FIRST_MONTH
            ? (int) $now->format('Y')
            : (int) $now->format('Y') - 1;
        $year = $month >= self::SCOUT_YEAR_FIRST_MONTH ? $scoutYearStart : $scoutYearStart + 1;

        // A 29/02 in a common year falls on the 1st of March, which is the
        // day after the 28th — the nearest reading of what was meant.
        return $now->setDate($year, $month, $day)->setTime(0, 0);
    }

    /** @return array{0: int, 1: int} */
    private function fromDayAndMonth(): array
    {
        $value = (string) ($this->settingService->get(self::SETTING_FROM, 'registration') ?: self::DEFAULT_FROM);
        if (preg_match('/^(\d{2})\/(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], 2024)) {
            $value = self::DEFAULT_FROM;
            preg_match('/^(\d{2})\/(\d{2})$/', $value, $m);
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function ageDays(): int
    {
        $days = (int) $this->settingService->get(self::SETTING_AGE_DAYS, 'registration');

        return $days > 0 ? $days : self::DEFAULT_AGE_DAYS;
    }
}
