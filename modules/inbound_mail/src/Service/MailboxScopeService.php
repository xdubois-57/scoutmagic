<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Api\MailboxScope;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Repository\InboundMailboxRepository;

/**
 * The one place that answers « ce module peut-il faire ceci sur cette
 * boîte ? ».
 *
 * Everything else — the synchronisation, the access registry, the listing
 * screens — asks here rather than reading the table, because the answer is
 * not the table alone: a dedicated box's scopes are **implied** by its
 * purpose, and a consumer with no row at all has a real answer (do
 * nothing) rather than a missing one.
 *
 * **Absence means inert, deliberately.** A module installed after a box was
 * configured does nothing to it until somebody says otherwise. The
 * alternative — a new module inheriting whatever the last one had, or
 * defaulting to "analyse everything" — would silently widen who sees a
 * unit's mail on an upgrade nobody read the notes for.
 */
class MailboxScopeService
{
    /**
     * The Camps setting this screen supersedes (A8/D9).
     *
     * Read once and written into the new model rather than consulted
     * forever: unlike the retention, where a live read keeps a unit's own
     * choice intact, this one is a *structural* answer that the new screen
     * now owns. Leaving Camps' list authoritative would mean two places
     * declaring a box dedicated, disagreeing the first time somebody used
     * the screen.
     */
    public const CAMPS_LEGACY_SETTING = 'camps_dedicated_mailbox_ids';
    public const REPRISE_DONE_SETTING = 'inbound_mail_scopes_migrated';

    public function __construct(
        private InboundMailboxRepository $mailboxRepository,
        private MessageConsumerRegistry $consumerRegistry
    ) {
    }

    /**
     * Every registered consumer's standing with this box, whether or not
     * it has a row — which is what a screen and a guard both need, and
     * neither should have to assemble.
     *
     * @return array<string, MailboxScope>
     */
    public function scopesFor(Mailbox $mailbox): array
    {
        $consumerIds = array_map(
            static fn($consumer) => $consumer->consumerId(),
            $this->consumerRegistry->all()
        );

        if ($mailbox->isDedicated()) {
            // The purpose is the source of truth here. A stale row left
            // behind by a box that used to be shared must not resurrect a
            // module the operator has since shut out.
            return $mailbox->impliedScopes($consumerIds);
        }

        $stored = $this->mailboxRepository->findScopes($mailbox->id);

        $scopes = [];
        foreach ($consumerIds as $consumerId) {
            $scopes[$consumerId] = $stored[$consumerId] ?? MailboxScope::inert($consumerId);
        }

        return $scopes;
    }

    public function scopeFor(Mailbox $mailbox, string $consumerId): MailboxScope
    {
        if ($mailbox->isDedicated()) {
            return $consumerId === $mailbox->dedicatedTo
                ? new MailboxScope($consumerId, true, ReadMode::ALL)
                : MailboxScope::inert($consumerId);
        }

        return $this->mailboxRepository->findScopes($mailbox->id)[$consumerId]
            ?? MailboxScope::inert($consumerId);
    }

    /**
     * The consumers allowed to look at this box's mail at all.
     *
     * What `Service\MailboxSyncService` asks before offering a message
     * around. A consumer that is not on this list is never handed the
     * message — not "handed it and told to ignore it", which would leave
     * the decision in the consumer's hands and make the screen advisory.
     *
     * @return \Modules\InboundMail\Api\MessageConsumerInterface[]
     */
    public function analyzingConsumers(Mailbox $mailbox): array
    {
        $scopes = $this->scopesFor($mailbox);

        return array_values(array_filter(
            $this->consumerRegistry->all(),
            static fn($consumer) => ($scopes[$consumer->consumerId()] ?? null)?->analyzes === true
        ));
    }

    /**
     * Save what the operator answered for a shared box.
     *
     * @param array<string, array{analyze: bool, read: string}> $answers keyed by consumer id
     */
    public function saveSharedScopes(int $mailboxId, array $answers): void
    {
        $this->mailboxRepository->setPurpose($mailboxId, MailboxPurpose::SHARED, null);

        foreach ($this->consumerRegistry->all() as $consumer) {
            $id = $consumer->consumerId();
            $answer = $answers[$id] ?? ['analyze' => false, 'read' => ReadMode::NONE->value];

            $this->mailboxRepository->saveScope($mailboxId, new MailboxScope(
                $id,
                $answer['analyze'],
                ReadMode::fromString($answer['read'])
            ));
        }
    }

    /**
     * Declare a box dedicated to one consumer.
     *
     * The per-module rows are cleared rather than rewritten to match: the
     * purpose already says everything, and rows that agree with it today
     * are rows that can disagree with it tomorrow.
     */
    public function saveDedicated(int $mailboxId, string $consumerId): void
    {
        $this->mailboxRepository->setPurpose($mailboxId, MailboxPurpose::DEDICATED, $consumerId);

        foreach ($this->consumerRegistry->all() as $consumer) {
            $this->mailboxRepository->saveScope(
                $mailboxId,
                MailboxScope::inert($consumer->consumerId())
            );
        }
    }

    /**
     * One-time reprise of `camps_dedicated_mailbox_ids` (A8).
     *
     * A unit that told Camps « cette boîte est celle des camps » must find
     * that answer already given on the new screen — not a blank form that
     * silently stopped routing its camp mail. Guarded by a setting on the
     * `member_section_periods_backfilled` model, and idempotent regardless:
     * it only ever writes the same purpose onto the same box.
     *
     * Deliberately does NOT clear the Camps setting. Reading it after the
     * reprise is harmless, and a module's own configuration is not this
     * module's to delete.
     *
     * @return int the number of boxes given a purpose
     */
    public function repriseCampsDedicatedBoxes(SettingService $settings, ?SettingRepository $repository = null): int
    {
        if (trim((string) ($settings->get(self::REPRISE_DONE_SETTING, 'inbound_mail', '') ?? '')) !== '') {
            return 0;
        }

        $raw = (string) ($settings->get(self::CAMPS_LEGACY_SETTING, 'camps', '') ?? '');
        $migrated = 0;

        foreach (self::parseIds($raw) as $mailboxId) {
            if ($this->mailboxRepository->findById($mailboxId) === null) {
                // The box was deleted since. Nothing to declare, and
                // certainly nothing to recreate.
                continue;
            }

            $this->saveDedicated($mailboxId, 'camps');
            $migrated++;
        }

        // Stamped even when nothing moved: "there was nothing to migrate"
        // is a finished migration, and re-reading a legacy setting on every
        // page view for the rest of the installation's life is not.
        $repository?->updateValue('inbound_mail', self::REPRISE_DONE_SETTING, '1');
        $settings->clearCache();

        return $migrated;
    }

    /**
     * @return int[]
     */
    public static function parseIds(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $piece) {
            $id = (int) $piece;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
