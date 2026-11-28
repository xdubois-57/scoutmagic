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
 * merge, or while it was disabled.
 *
 * The setting `member_merge_caught_up_<module>` holds the merges'
 * signature ({@see MemberMergeRepository::mergeSignature()}) as of the
 * last merge the module followed. A merge made while the module is
 * enabled advances it in the merge's own transaction ({@see advance()}),
 * so only a merge the module missed leaves it behind and triggers the
 * catch-up — never an ordinary merge. Replaying a merge the module already
 * followed finds nothing to move, so a re-run is harmless.
 *
 * The catch-up runs from the composition root of ordinary requests, so it
 * is kept from weighing on them: one request at a time runs it (the others
 * stand down, {@see MemberMergeRepository::catchUpAlone()}), and a failure
 * is journalled and swallowed, never left to 500 every page — the admin
 * included, from where the module could be disabled. After a failure the
 * setting holds `échec|<signature>|<time>`: the catch-up is retried only
 * once RETRY_AFTER_SECONDS have passed, and journalled once per signature.
 */
class MemberMergeListenerRegistrar
{
    private const FAILED_PREFIX = 'échec|';

    private const RETRY_AFTER_SECONDS = 900;

    private ?string $signature = null;

    /** @var array<string, true> modules whose setting holds $signature */
    private array $upToDate = [];

    public function __construct(
        private MemberMergeListenerRegistry $registry,
        private MemberMergeRepository $merges,
        private SettingService $settings,
        private JournalService $journal
    ) {
        $registry->afterEachMerge($this->advance(...));
    }

    public function register(MemberMergeListener $listener): void
    {
        $this->registry->register($listener);

        $this->signature ??= $this->merges->mergeSignature();
        $module = $listener->moduleId();
        $key = self::settingKey($module);
        $stored = (string) ($this->settings->get($key) ?? '');
        if ($stored === $this->signature) {
            $this->upToDate[$module] = true;

            return;
        }
        $failedAt = $this->failedAt($stored);
        if ($failedAt !== null && time() - $failedAt < self::RETRY_AFTER_SECONDS) {
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

        try {
            $handled = $this->merges->catchUpAlone($listener);
        } catch (\Throwable $e) {
            $this->recordFailure($module, $key, $stored, $e);

            return;
        }
        if ($handled === null) {
            return;
        }

        if ($handled > 0) {
            $this->journal->log(
                $module,
                'member_merge_caught_up',
                'info',
                'Fusions de fiches déjà faites appliquées aux données du module : ' . $handled . ' ligne(s)',
                ['rows' => $handled]
            );
        }
        // Compare-and-set: a concurrent request that caught up first wrote
        // the same signature, or a newer one this request must not undo.
        $this->settings->replaceIfUnchanged($key, $stored, $this->signature);
        $this->upToDate[$module] = true;
    }

    /**
     * End of a merge, inside its transaction: every module that was up to
     * date has just followed it, so its setting moves to the new
     * signature. A module whose setting moved meanwhile (a concurrent
     * merge) is left behind, and simply catches up on the next request.
     */
    private function advance(): void
    {
        if ($this->signature === null || $this->upToDate === []) {
            return;
        }
        $after = $this->merges->mergeSignature();
        foreach (array_keys($this->upToDate) as $module) {
            if (!$this->settings->replaceIfUnchanged(self::settingKey($module), $this->signature, $after)) {
                unset($this->upToDate[$module]);
            }
        }
        $this->signature = $after;
    }

    private function recordFailure(string $module, string $key, string $stored, \Throwable $e): void
    {
        error_log('Member merge catch-up failed for ' . $module . ': ' . $e->getMessage());
        $alreadyJournalled = $this->failedAt($stored) !== null;
        try {
            $this->settings->replaceIfUnchanged($key, $stored, self::FAILED_PREFIX . $this->signature . '|' . time());
            if ($alreadyJournalled) {
                return;
            }
            $this->journal->log(
                $module,
                'member_merge_catch_up_failed',
                'error',
                'Les fusions de fiches déjà faites n\'ont pas pu être appliquées aux données du module ; '
                    . 'nouvel essai dans un quart d\'heure',
                ['error' => $e::class]
            );
        } catch (\Throwable) {
            // The journal or the settings are what failed: error_log() above
            // is all that is left, and the page must still render.
        }
    }

    /** When the catch-up last failed for the current signature, or null. */
    private function failedAt(string $stored): ?int
    {
        $prefix = self::FAILED_PREFIX . $this->signature . '|';
        if (!str_starts_with($stored, $prefix)) {
            return null;
        }
        $time = substr($stored, strlen($prefix));

        return ctype_digit($time) ? (int) $time : null;
    }

    private static function settingKey(string $module): string
    {
        return 'member_merge_caught_up_' . $module;
    }
}
