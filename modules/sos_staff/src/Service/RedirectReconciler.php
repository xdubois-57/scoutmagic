<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Modules\SosStaff\Api\ProviderException;

/**
 * Puts the SOS line where the planning says it should be NOW, after
 * reading where it actually is (issue #1102).
 *
 * The scheduled transitions only ever compared one planned day with the
 * planned day before it. Whenever the line had been moved in between — a
 * duty removed after its morning handover, a transition that failed, a
 * change made at the operator — nothing compared the plan with the line,
 * and the calls kept ringing for somebody who was no longer on duty while
 * the planning page reported success. This is that comparison, in one
 * place, for every caller that needs it:
 *
 *  - right after a month of the planning is saved, and after the default
 *    number changes — the immediate application, whatever the save changed;
 *  - every hour, and in quick retries after a failure
 *    (Task\ReconcileRedirectHandler);
 *  - the dashboard's « Redirection actuelle » block, which shows the same
 *    expected destination (expectedDestination()).
 *
 * The change itself is RedirectService::apply() — read-back, journal,
 * handover notifications and alert included — so a correction made here
 * is indistinguishable from a scheduled one.
 */
class RedirectReconciler
{
    public const ALIGNED = 'aligned';
    public const CORRECTED = 'corrected';
    public const FAILED = 'failed';
    public const UNREADABLE = 'unreadable';
    public const NOT_CONFIGURED = 'not_configured';
    public const NO_DESTINATION = 'no_destination';

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private OnCallService $onCallService,
        private RedirectService $redirectService,
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private JournalService $journalService,
        private ?\Closure $clock = null
    ) {
    }

    /**
     * Where the calls should be going at this moment: the planned member
     * (null = the default number) and the number that means.
     *
     * @return array{member_id: ?int, number: ?string}
     */
    public function expectedDestination(int $scoutYearId): array
    {
        $memberId = $this->onCallService->resolveExpectedTargetAt(
            $this->now(),
            array_column($this->settingsService->getStaffOptions($scoutYearId), 'member_id')
        )['member_id'];

        return ['member_id' => $memberId, 'number' => $this->redirectService->resolveNumber($memberId, $scoutYearId)];
    }

    /**
     * @param string $trigger what asked, for the journal (« planning »,
     *        « default_number », « hourly », « retry »)
     * @return array{status: string, expected_member_id: ?int, expected_number: ?string}
     */
    public function reconcile(int $scoutYearId, string $trigger): array
    {
        $provider = $this->providerConfigService->getActiveProvider();
        if ($provider === null) {
            return ['status' => self::NOT_CONFIGURED, 'expected_member_id' => null, 'expected_number' => null];
        }

        $expected = $this->expectedDestination($scoutYearId);
        $outcome = static fn(string $status): array => [
            'status' => $status,
            'expected_member_id' => $expected['member_id'],
            'expected_number' => $expected['number'],
        ];

        if ($expected['number'] === null) {
            // Nobody on duty and no usable default number: a configuration
            // gap, not something a provider call can fix. apply() would
            // alert on every hourly pass; this says it once per pass, in
            // the journal, and leaves the line alone.
            $this->journalService->log(
                'sos_staff',
                'redirect_reconcile_no_destination',
                'warning',
                'Contrôle de la redirection SOS : aucun destinataire valide (ni garde, ni numéro par défaut).',
                ['trigger' => $trigger]
            );
            return $outcome(self::NO_DESTINATION);
        }

        try {
            $live = $provider->readForwardingState();
        } catch (ProviderException $e) {
            $this->journalService->log(
                'sos_staff',
                'redirect_reconcile_unreadable',
                'warning',
                "Contrôle de la redirection SOS : l'état n'a pas pu être lu chez l'opérateur.",
                ['trigger' => $trigger, 'exception_class' => $e::class]
            );
            return $outcome(self::UNREADABLE);
        }

        if ($live->active && $live->number === $expected['number']) {
            // Read from the operator just now: whatever failure was
            // recorded earlier, the line is where it should be.
            $this->settingsService->recordRedirectOutcome(false, $this->now());
            return $outcome(self::ALIGNED);
        }

        $previousMemberId = null;
        if ($live->active && $live->number !== null) {
            foreach ($this->settingsService->getStaffOptions($scoutYearId) as $option) {
                if ($option['mobile'] === $live->number) {
                    $previousMemberId = (int) $option['member_id'];
                    break;
                }
            }
        }

        $this->journalService->log(
            'sos_staff',
            'redirect_drift_detected',
            'warning',
            $live->active
                ? 'La redirection SOS ne correspond pas au planning : correction.'
                : "La redirection SOS n'est pas active : réactivation.",
            [
                'trigger' => $trigger,
                'expected_member_id' => $expected['member_id'],
                'live_member_id' => $previousMemberId,
            ]
        );

        try {
            $this->redirectService->apply($expected['member_id'], $previousMemberId, $scoutYearId);
        } catch (SosException) {
            // Already journaled, recorded for the attention page and
            // alerted inside apply().
            return $outcome(self::FAILED);
        }

        return $outcome(self::CORRECTED);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }
}
