<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

use Core\Config\SettingService;
use Modules\InboundMail\Api\InboundMailInterface;

/**
 * Which boxes are seed boxes, and whether the unit wants copies at all
 * (roadmap IT-07).
 *
 * **There is no new kind of mailbox and no new configuration concept**
 * (D10). A seed box is an ordinary box of the unit's, declared under
 * « Courrier entrant » like any other, on which the super-admin grants
 * this consumer a scope. « Which boxes are seed boxes » is then answered
 * by the scope mechanism that already answers « who reads what », and the
 * addresses come back from
 * {@see InboundMailInterface::probeAddressesFor()} — the very method the
 * manual probe already uses to ask the same question. Nothing here
 * enumerates boxes on its own.
 *
 * **Only the unit's own boxes ever receive a copy** (D11), and that falls
 * out of the same mechanism rather than being enforced twice: these are
 * the boxes this installation has configured and synchronises. A seed
 * address at a third-party analytics service stays where D11 put it — on
 * the manual probe, where an operator chooses what is sent.
 *
 * **Off unless the unit turns it on.** A copy of every mailing carries
 * real members' data into however many mailboxes are declared, so the
 * answer has to be somebody's decision rather than a default.
 */
final class SeedMailboxes
{
    /** Registered `editable: false`: the toggle is a page, not a text field. */
    public const SETTING_ENABLED = 'mail_seed_boxes_enabled';

    /** The consumer a seed box is scoped to. */
    public const CONSUMER_ID = 'core_mail_seed';

    /**
     * **The correlation rides here, never in the subject.**
     *
     * The manual probe decorates its subject with `SM-XXXXXX`, which is
     * right for it: somebody hunts that string in a crowded mailbox. A
     * seed copy must not be decorated at all — the subject and the body
     * ARE the measurement, and an unusual token in a subject line is
     * exactly the sort of thing a spam filter weighs. Measuring the
     * token's effect instead of the campaign's would make every figure on
     * the results page quietly wrong.
     */
    public const HEADER = 'X-ScoutMagic-Seed';

    /**
     * How many boxes are worth having, and the screen says it.
     *
     * Three to five. These boxes never read their mail, never reply and
     * never click: past a handful they stop being a measurement and start
     * being dead weight in the engagement the large providers score a
     * sender on — so a unit measuring harder would be making its own
     * delivery worse.
     */
    public const SUGGESTED_MAXIMUM = 5;

    public function __construct(
        private SeedCopyRepository $copies,
        private SettingService $settings,
        /** Null when `inbound_mail` is off — then there are no boxes at all (D2). */
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * Hand this the module once it exists.
     *
     * **The mutable-registry shape §7.6 describes**, and for the reason
     * that section gives: both composition roots build the one
     * `MailService` the whole request uses long before the modules load —
     * `public/index.php` builds it around line 2200 and `inbound_mail`
     * some four thousand lines later. A constructor argument would
     * therefore always be null here, and the feature would be wired,
     * green and inert.
     *
     * Safe because nothing reads it until a message is actually sent,
     * which is long after boot has finished. The same file already does
     * exactly this for the mail-template registry, and says so there.
     */
    public function useInboundMail(?InboundMailInterface $inboundMail): void
    {
        $this->inboundMail = $inboundMail;
    }

    public function isEnabled(): bool
    {
        return (string) ($this->settings->get(self::SETTING_ENABLED) ?? '0') === '1';
    }

    /**
     * Every seed box this installation has, copies or not — what the
     * screen counts and what the « trois à cinq suffisent » notice is
     * measured against.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        try {
            return $this->inboundMail?->probeAddressesFor(self::CONSUMER_ID) ?? [];
        } catch (\Throwable) {
            // A module that cannot answer is « no boxes », never a broken
            // mailing: this is a diagnostic, and a diagnostic may not cost
            // anybody their mail.
            return [];
        }
    }

    /**
     * The boxes this run may still be copied to, each already written
     * down.
     *
     * **Claimed before the copy is sent**, so a retried batch or a run the
     * scheduler picks up twice cannot put a second message in the same
     * box — which would double the measurement's denominator in silence.
     * An address that comes back here is one this caller now owes a copy.
     *
     * @return list<string>
     */
    public function claimFor(string $runReference, \DateTimeImmutable $now): array
    {
        if ($runReference === '' || !$this->isEnabled()) {
            return [];
        }

        $claimed = [];
        foreach ($this->addresses() as $address) {
            try {
                if ($this->copies->claim($runReference, $address, $now)) {
                    $claimed[] = $address;
                }
            } catch (\Throwable) {
                // One box that cannot be written down costs its own
                // measurement and nothing else. Silent for the reason the
                // receipt is: the journal is reached through the database
                // that just refused.
                continue;
            }
        }

        return $claimed;
    }
}
