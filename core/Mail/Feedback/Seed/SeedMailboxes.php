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
     * Asked for the module the first time somebody needs it.
     *
     * @var (\Closure(): ?InboundMailInterface)|null
     */
    private ?\Closure $inboundMailResolver = null;

    /**
     * The module, however it was wired — handed over, or resolved now.
     *
     * A resolver that throws is « no module », for the same reason
     * `addresses()` swallows one: this whole feature is a diagnostic, and
     * a diagnostic may not cost anybody their mail. The answer is kept
     * either way, so a mailing of four hundred resolves once.
     */
    private function module(): ?InboundMailInterface
    {
        if ($this->inboundMail !== null || $this->inboundMailResolver === null) {
            return $this->inboundMail;
        }

        $resolver = $this->inboundMailResolver;
        // Cleared first: a resolver that throws must not be retried on
        // every message of the mailing that follows.
        $this->inboundMailResolver = null;

        try {
            $this->inboundMail = $resolver();
        } catch (\Throwable) {
            $this->inboundMail = null;
        }

        return $this->inboundMail;
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

    /**
     * The same wiring, said as « ask for it when you need it ».
     *
     * **This exists because `cron.php` cannot use the eager form, and
     * `cron.php` is the entry point that actually sends mailings.**
     * `public/index.php` has a built `InboundMailInterface` in a variable
     * by the time its module block runs; the scheduler builds its
     * capabilities lazily, on purpose — constructing the inbound graph on
     * every cron pass that has no mail to read is exactly what that
     * laziness avoids. Handing over a resolver instead of an instance
     * keeps both.
     *
     * Resolved once and remembered, so a mailing of four hundred asks the
     * registry once rather than four hundred times. A resolver that
     * throws is « no module », never a failed mailing: the measurement is
     * worth less than the message it measures.
     *
     * @param \Closure(): ?InboundMailInterface $resolver
     */
    public function resolveInboundMailWith(\Closure $resolver): void
    {
        $this->inboundMailResolver = $resolver;
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
            return $this->module()?->probeAddressesFor(self::CONSUMER_ID) ?? [];
        } catch (\Throwable) {
            // A module that cannot answer is « no boxes », never a broken
            // mailing: this is a diagnostic, and a diagnostic may not cost
            // anybody their mail.
            return [];
        }
    }

    /**
     * How many seed boxes cannot see their own junk folder.
     *
     * **The measurement's blind spot, and it is the default.** A mailbox
     * declared under « Courrier entrant » is read in its INBOX and
     * nowhere else unless the operator names more folders
     * (`Mailbox::watchedFolders()`). For every other consumer that is
     * right — mail nobody sent to the inbox is mail nobody has to file.
     * For this one it is the opposite: a copy the provider shelved as
     * spam is then never fetched, never recorded, and two days later the
     * sweep writes « jamais arrivé » on it. The screen would show its
     * gravest badge for a message that was in fact delivered, and the
     * routing would act on the difference — so a feature whose entire
     * purpose is telling « réception » from « indésirables » would
     * systematically report neither.
     *
     * It is a count, never a name: what an operator acts on is « two of
     * your boxes », and a mailbox address has no business on a screen
     * (SECURITY.md §11).
     *
     * A module that cannot answer is « nothing to report », like
     * {@see self::addresses()}: a warning raised by a hiccup would teach
     * its reader to ignore the warning.
     */
    public function boxesBlindToSpam(): int
    {
        try {
            $watched = $this->module()?->watchedFoldersFor(self::CONSUMER_ID) ?? [];
        } catch (\Throwable) {
            return 0;
        }

        $blind = 0;
        foreach ($watched as $folders) {
            foreach ($folders as $folder) {
                // The same vocabulary the verdict uses, deliberately: a
                // folder this site would read as « Indésirables » is
                // exactly the folder it needs to be watching, and two
                // lists of provider folder names would drift apart.
                if (SeedVerdict::fromFolder($folder) === SeedVerdict::Spam) {
                    continue 2;
                }
            }

            $blind++;
        }

        return $blind;
    }

    /**
     * Whether the measurement is sound enough for a decision **nobody
     * will re-read**.
     *
     * Two conditions, and the second is the one that was missing: every
     * declared box must be able to see its junk folder, **and there must
     * be a declared box at all**. {@see self::boxesBlindToSpam()} counts
     * blind boxes, so with no boxes it counts zero — « nothing wrong »
     * and « nothing measured » giving the same answer, which is the
     * oldest trap on this page and the reason the results table states
     * them apart. A unit could therefore arm the automatism before
     * declaring anything, add an inbox-only box later, and have the
     * sweep reroute a provider on exactly the evidence the guard exists
     * to refuse.
     *
     * Asked in both places for the same reason: the screen arms the
     * switch, but the configuration can change afterwards and the daily
     * sweep is what acts.
     */
    public function measuresSpamReliably(): bool
    {
        return $this->addresses() !== [] && $this->boxesBlindToSpam() === 0;
    }

    /**
     * What the header on a copy of this run must carry.
     *
     * A keyed tag rather than the bare reference, because a reference is
     * `mass_mail:<id>` and a seed box is an ordinary, non-secret mailbox
     * — see {@see SeedCopyRepository::stamp()} for what forging one would
     * buy an attacker.
     */
    public function stampFor(string $runReference): string
    {
        return $this->copies->stamp($runReference);
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
