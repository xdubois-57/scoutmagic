<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

use Core\Config\SettingService;
use Core\Journal\JournalService;

/**
 * How a module block registers its {@see MemberMergeListener} (issue
 * #1462): into the registry, and over every merge already done whenever
 * there is one it has not seen — made before the module could follow a
 * merge, while it was disabled, or during its last catch-up.
 *
 * The setting `member_merge_caught_up_<module>` holds the merges'
 * signature ({@see MemberMergeRepository::mergeSignature()}) as of the
 * module's last catch-up; any new merge changes it, and the catch-up runs
 * again. Replaying a merge the module already followed finds nothing to
 * move, so a re-run is harmless. The signature is read once per request,
 * for every module.
 */
class MemberMergeListenerRegistrar
{
    private ?string $signature = null;

    public function __construct(
        private MemberMergeListenerRegistry $registry,
        private MemberMergeRepository $merges,
        private SettingService $settings,
        private JournalService $journal
    ) {
    }

    public function register(MemberMergeListener $listener): void
    {
        $this->registry->register($listener);

        $this->signature ??= $this->merges->mergeSignature();
        $key = 'member_merge_caught_up_' . $listener->moduleId();
        if ($this->settings->get($key) === $this->signature) {
            return;
        }

        $this->settings->register(
            $key,
            '',
            'text',
            'Fusions passées rattrapées',
            'État des fusions de fiches déjà appliquées aux données de ce module.',
            null,
            null,
            null,
            false,
            999
        );
        $handled = $this->merges->catchUp($listener);
        if ($handled > 0) {
            $this->journal->log(
                $listener->moduleId(),
                'member_merge_caught_up',
                'info',
                'Fusions de fiches déjà faites appliquées aux données du module : ' . $handled . ' ligne(s)',
                ['rows' => $handled]
            );
        }
        $this->settings->setInternal($key, $this->signature);
    }
}
