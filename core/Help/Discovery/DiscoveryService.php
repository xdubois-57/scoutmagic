<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Discovery;

use Core\Config\AppClock;
use Core\Config\SettingService;
use Core\Help\DiscoveryPriority;
use Core\Help\HelpService;
use Core\Help\HelpTopic;
use Core\Security\Role;

/**
 * Which help topics to offer as « Le saviez-vous ? » tips, to whom, and
 * in what order (ARCHITECTURE.md §8.95).
 *
 * **There is no change-of-role detection anywhere in here.** The whole
 * trigger is: does at least one topic exist, at this account's current
 * role, in an enabled module, that this account has never been shown? A
 * promotion makes new topics eligible mechanically, so does a module
 * somebody switched on, and so does a brand-new account — with no stored
 * previous role to reconcile against the staff year, the administrator's
 * preview or a scout-year transition, all three of which move a role
 * without announcing it.
 *
 * **The role filter is HelpService::listForRole() and nothing else.** It
 * is the single role gate of the whole site (§8.64): it already hands out
 * cumulative topics — a chief sees the `identified` ones too, deliberately,
 * since an animateur is usually also a parent — and a disabled module's
 * topics were never registered in the first place, so they cannot appear
 * here whatever this class does.
 */
class DiscoveryService
{
    public const SETTING_ENABLED = 'help_discovery_enabled';
    public const SETTING_INTERVAL_HOURS = 'help_discovery_interval_hours';
    public const SETTING_SNOOZE_DAYS = 'help_discovery_snooze_days';
    public const SETTING_BATCH_SIZE = 'help_discovery_batch_size';

    public const DEFAULT_INTERVAL_HOURS = 24;
    public const DEFAULT_SNOOZE_DAYS = 7;
    public const DEFAULT_BATCH_SIZE = 5;

    /**
     * Pages the dialog never opens on, as path prefixes — the whole path,
     * or the whole path plus a `/` and more.
     *
     * Each for its own reason. `/aide` IS the corpus, and a modal
     * covering the help index to advertise a help topic is a joke.
     * `/api/` answers JSON to a script, which has no modal to draw and no
     * reader to interrupt. `/login` is somebody who is not in yet, and the
     * offline page is somebody with no network — where the close call
     * would fail and nothing would be recorded.
     *
     * @var string[]
     */
    private const NEVER_ON = ['/aide', '/api', '/login', '/offline'];

    public function __construct(
        private readonly HelpService $helpService,
        private readonly SeenTopicRepository $seenTopics,
        private readonly SettingService $settings,
    ) {
    }

    /**
     * The batch to show right now — an empty list meaning "say nothing",
     * which is what the caller renders as "no dialog at all" rather than
     * as an empty one.
     *
     * In order: the unit's own switch, then the account's own delay, then
     * the eligible remainder, truncated.
     *
     * @return HelpTopic[]
     */
    public function nextTopics(Role $role, int $accountId): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $snoozedUntil = $this->seenTopics->snoozedUntil($accountId);
        if ($snoozedUntil !== null && $snoozedUntil > AppClock::now()) {
            return [];
        }

        return array_slice($this->ordered($role, $accountId, $snoozedUntil), 0, $this->batchSize());
    }

    /**
     * Every topic this account could still be shown, in the order they
     * would be shown in — the batch above is its first few.
     *
     * Deliberately NOT gated on the switch or on the snooze delay, unlike
     * nextTopics(): its two other callers are the endpoint revalidating
     * the ids a browser claims to have read (which must recognise exactly
     * what it handed out) and « Ne plus me proposer d'astuces » (which
     * consumes the whole remainder). Both act on a dialog that was
     * already, legitimately, on screen.
     *
     * @return HelpTopic[]
     */
    public function eligibleTopics(Role $role, int $accountId): array
    {
        return $this->ordered($role, $accountId, $this->seenTopics->snoozedUntil($accountId));
    }

    /**
     * eligibleTopics() with the snooze instant already in hand — the one
     * nextTopics() uses, so that reading it to decide whether to speak at
     * all and reading it to seed the order cost one query rather than two
     * on every page load.
     *
     * @return HelpTopic[]
     */
    private function ordered(Role $role, int $accountId, ?\DateTimeImmutable $snoozedUntil): array
    {
        $seenIds = $this->seenTopics->findSeenIds($accountId);
        $seen = array_fill_keys($seenIds, true);

        $eligible = [];
        foreach ($this->helpService->listForRole($role) as $topics) {
            foreach ($topics as $topic) {
                if ($topic->discovery === DiscoveryPriority::Off || isset($seen[$topic->id])) {
                    continue;
                }
                $eligible[] = $topic;
            }
        }

        $seed = $this->seed($accountId, $snoozedUntil, count($seenIds));

        usort($eligible, static function (HelpTopic $a, HelpTopic $b) use ($seed): int {
            return $a->discovery->rank() <=> $b->discovery->rank()
                ?: crc32($seed . $a->id) <=> crc32($seed . $b->id)
                ?: strcmp($a->id, $b->id);
        });

        return $eligible;
    }

    /**
     * What the dialog renders on this request, or null when it must not
     * be rendered at all — the single entry point the composition root
     * calls, so the whole decision is here and testable rather than
     * spread across an `{% if %}` in a template and a condition in
     * public/index.php.
     *
     * A card carries exactly what the dialog shows: the topic's FIRST
     * question as the hook (« Comment mettre le prénom de chacun dans un
     * e-mail groupé ? »), then the title, then the summary, then the link
     * to the whole topic. The question comes first because that is what
     * separates a tip from a table of contents.
     *
     * `more` says whether « Voir d'autres astuces » has anything to
     * offer, which is the one thing a card cannot say about itself.
     *
     * @return array{cards: array<int, array{id: string, title: string, summary: string,
     *     question: ?string, url: string}>, more: bool}|null
     */
    public function dialogForRequest(Role $role, ?int $accountId, string $method, string $path): ?array
    {
        if ($accountId === null || strtoupper($method) !== 'GET' || !$this->isOfferablePath($path)) {
            return null;
        }

        $cards = $this->nextTopics($role, $accountId);
        if ($cards === []) {
            return null;
        }

        return [
            'cards' => array_map(static fn (HelpTopic $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'summary' => $t->summary,
                'question' => $t->questions[0] ?? null,
                'url' => '/aide/' . $t->id,
            ], $cards),
            'more' => count($this->eligibleTopics($role, $accountId)) > count($cards),
        ];
    }

    /**
     * Whether a tip may interrupt this page at all — see NEVER_ON.
     *
     * Prefix, then boundary: `/aide` and `/aide/publipostage` are the
     * help, `/aidez-nous` would not be.
     */
    private function isOfferablePath(string $path): bool
    {
        foreach (self::NEVER_ON as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many tips one passage serves.
     */
    public function batchSize(): int
    {
        return $this->positiveSetting(self::SETTING_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);
    }

    /**
     * When the dialog may come back after an ordinary close.
     */
    public function nextOrdinaryOpening(): \DateTimeImmutable
    {
        return AppClock::now()->modify(
            '+' . $this->positiveSetting(self::SETTING_INTERVAL_HOURS, self::DEFAULT_INTERVAL_HOURS) . ' hours'
        );
    }

    /**
     * When it may come back after an explicit « Pas avant une semaine ».
     */
    public function nextOpeningAfterSnooze(): \DateTimeImmutable
    {
        return AppClock::now()->modify(
            '+' . $this->positiveSetting(self::SETTING_SNOOZE_DAYS, self::DEFAULT_SNOOZE_DAYS) . ' days'
        );
    }

    public function isEnabled(): bool
    {
        return (string) $this->settings->get(self::SETTING_ENABLED, null, '1') !== '0';
    }

    /**
     * The tie-breaking seed, and the reason it is not a shuffle().
     *
     * The dialog reappears on EVERY page load until it is closed —
     * `help_discovery_snoozed_until` is only ever set by an action — so a
     * fresh draw per request would serve a different batch from one page
     * to the next, re-showing the very cards somebody has just moved past.
     * This seed moves only when the stored state moves, which is to say
     * after each close, each postponement and each refusal: a stable
     * order for a whole browsing session, a different one at the next
     * passage, and two people of the same role who do not open on the
     * same tip.
     *
     * mt_srand() is forbidden here. It is global state of the request: it
     * would make this service untestable and would silently reshape every
     * random draw made later in the same request. The sort key computed
     * from this seed is pure.
     */
    private function seed(int $accountId, ?\DateTimeImmutable $snoozedUntil, int $countSeen): string
    {
        return (string) crc32($accountId . '|' . ($snoozedUntil?->format('c') ?? '') . '|' . $countSeen);
    }

    /**
     * A setting an administrator can empty or set to nonsense still has
     * to leave the feature in a usable state — a batch size of 0 would be
     * a dialog with nothing in it, and a negative interval a dialog that
     * never goes away.
     */
    private function positiveSetting(string $key, int $default): int
    {
        $value = (int) (string) $this->settings->get($key, null, (string) $default);

        return $value > 0 ? $value : $default;
    }
}
