<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * What saving the reenrollment configuration would do — computed once, by
 * the server, before anything is written (issue #796, decision D1).
 *
 * **One plan, read three times.** The confirmation shows it, the save
 * applies it, and the save compares it again at the moment of writing. Two
 * computations that could diverge are how a dialogue says « aucun e-mail »
 * for a send that then leaves.
 *
 * It answers one question every time, in both directions: does an e-mail
 * leave? Yes — which ones, for which campaign, to how many families, and
 * whether now or at the next hourly pass. No — and why not.
 */
final class ReenrollmentSavePlan
{
    /** Nothing differs from what is stored. */
    public const REASON_NO_CHANGE = 'no_change';
    /** Settings change, and none of them writes to anybody. */
    public const REASON_SETTINGS_ONLY = 'settings_only';
    /** The campaign's e-mails are switched off. */
    public const REASON_EMAILS_DISABLED = 'emails_disabled';
    /** That e-mail has already gone out for this campaign. */
    public const REASON_ALREADY_SENT = 'already_sent';
    /** The campaign's close date is behind us: there is nobody left to tell. */
    public const REASON_CAMPAIGN_ENDED = 'campaign_ended';
    /** The dates designate no campaign at all. */
    public const REASON_NO_CAMPAIGN = 'no_campaign';

    /**
     * @param list<array{setting: string, from: string, to: string}> $changes
     * @param array{campaign: ?string, scheduled: bool}|null $opening
     * @param array{campaign: ?string}|null $closing
     * @param list<array{type: string, campaign: string, families: int, deferred: bool}> $emails
     */
    public function __construct(
        public readonly array $changes,
        public readonly ?array $opening,
        public readonly ?array $closing,
        public readonly array $emails,
        public readonly ?string $noEmailReason
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    public function sendsEmail(): bool
    {
        return $this->emails !== [];
    }

    /**
     * The e-mail of `$type` this save sets off, or null.
     *
     * @return array{type: string, campaign: string, families: int, deferred: bool}|null
     */
    public function email(string $type): ?array
    {
        foreach ($this->emails as $email) {
            if ($email['type'] === $type) {
                return $email;
            }
        }

        return null;
    }

    /**
     * What a confirmation is about, as one string: if any of it changes
     * between the question and the answer, the answer no longer applies.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->changes,
            $this->opening,
            $this->closing,
            $this->emails,
            $this->noEmailReason,
        ]));
    }
}
