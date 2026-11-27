<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Config\SettingService;
use Core\Journal\JournalService;

/**
 * « Armed, but no longer acting » — the state a test sandbox falls into
 * when its arm switch is still on and the installation no longer lets it
 * work (ARCHITECTURE.md §8.63, issue #1469).
 *
 * The mail capture and the simulated telephony are both a module's
 * (`test_tools`), and both stop the moment their installation profile is
 * lost — an update whose `config/app.php` no longer declares it, a
 * `base_url` that no longer matches — or the module is no longer loaded.
 * Before this class, "stop" meant "send for real again": every e-mail of a
 * reference installation, whose roster holds real members and parents,
 * went back to their real addresses without anybody deciding it, and the
 * real SOS line was driven again. This class turns that transition into a
 * block instead, held until a superadmin decides otherwise.
 *
 * **Core, not the module, and that is the point.** The very failure it
 * watches for is the module not loading; whatever records it and says so
 * has to work without it. Why the sandbox is lost is the module's to say
 * (`Modules\TestTools\Sandbox\SandboxLoss`, called by the composition
 * roots only, §7.5); what follows from it — the journal line, the block,
 * the attention point, the release — is core's.
 *
 * **One settings row per channel**, an internal one no page lists:
 * `''` while nothing is wrong, the reason while blocked, the reason plus
 * {@see self::RELEASED_SUFFIX} once a superadmin reopened real sending.
 * One row rather than a reason and a flag, so that every transition is a
 * single compare-and-swap ({@see SettingService::replaceIfUnchanged()}):
 * two requests seeing the same loss at the same moment write ONE journal
 * line between them, not one each.
 *
 * **A release lasts as long as the loss it answered.** When the sandbox
 * acts again — or its switch is disarmed — the row empties, release
 * included, so a later loss blocks again rather than inheriting a decision
 * taken about another one. A loss whose reason changes is a new loss for
 * the same reason.
 */
final class SandboxLossState
{
    public const CHANNEL_MAIL = 'mail';
    public const CHANNEL_TELEPHONY = 'telephony';

    /** @var list<string> */
    public const CHANNELS = [self::CHANNEL_MAIL, self::CHANNEL_TELEPHONY];

    /** `config/app.php` declares no capture flag at all. */
    public const REASON_PROFILE_MISSING = 'profile_missing';

    /** The flag is declared, but `base_url`'s host no longer satisfies it. */
    public const REASON_HOST_MISMATCH = 'host_mismatch';

    /** The profile holds, but the `test_tools` module is no longer enabled. */
    public const REASON_MODULE_UNLOADED = 'module_unloaded';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_PROFILE_MISSING,
        self::REASON_HOST_MISMATCH,
        self::REASON_MODULE_UNLOADED,
    ];

    public const SETTING_MAIL = 'sandbox_loss_mail';
    public const SETTING_TELEPHONY = 'sandbox_loss_telephony';

    public const RELEASED_SUFFIX = ':released';

    /** The page a superadmin decides on (Core\Http\Controller\SandboxLossController). */
    public const PAGE_URL = '/config/capture-suspendue';

    /**
     * The reason each channel was last recorded with during THIS request,
     * keyed by channel. Read by isBlocked() before the stored row, so a
     * row that could not be written still blocks: failing to remember a
     * loss must never be what reopens real sending.
     *
     * @var array<string, ?string>
     */
    private array $live = [];

    public function __construct(private readonly SettingService $settingService)
    {
    }

    /**
     * Declared by every entry point that records (public/index.php,
     * public/cron.php). Non-editable: the row is the application's
     * bookkeeping, changed only by record() and release().
     */
    public static function register(SettingService $settingService): void
    {
        $regex = '^(' . implode('|', self::REASONS) . ')(' . self::RELEASED_SUFFIX . ')?$';

        $settingService->register(
            self::SETTING_MAIL,
            '',
            'text',
            'Capture des e-mails armée sans effet',
            'Raison pour laquelle la capture des e-mails, encore armée, n\'agit plus, et si un super-administrateur '
                . 'a rouvert l\'envoi réel. Tenu par le site lui-même, jamais modifié à la main.',
            null,
            $regex,
            null,
            false
        );
        $settingService->register(
            self::SETTING_TELEPHONY,
            '',
            'text',
            'Téléphonie simulée armée sans effet',
            'Raison pour laquelle la téléphonie simulée, encore armée, n\'agit plus, et si un super-administrateur '
                . 'a rouvert la vraie ligne. Tenu par le site lui-même, jamais modifié à la main.',
            null,
            $regex,
            null,
            false
        );
    }

    /**
     * What the composition root found for one channel on this request:
     * a reason from {@see self::REASONS}, or null when the sandbox acts
     * or is not armed.
     *
     * Journals at level `security` ONLY when the stored reason changes to
     * a new one — not on every request, which would bury the journal of an
     * installation left in that state for a week. Back to null empties the
     * row (release included) and writes one `info` line.
     */
    public function record(string $channel, ?string $reason, JournalService $journal): void
    {
        self::assertChannel($channel);
        if ($reason !== null && !in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown sandbox loss reason: {$reason}");
        }

        $this->live[$channel] = $reason;

        $stored = $this->storedValue($channel);
        [$storedReason] = self::parse($stored);

        if ($reason === $storedReason) {
            return;
        }

        $next = $reason ?? '';
        if (!$this->settingService->replaceIfUnchanged(self::settingKey($channel), $stored, $next)) {
            // Another request recorded the same transition first, or the
            // row does not exist yet (an entry point that has not
            // registered it): either way this request journals nothing,
            // and isBlocked() still answers from $live.
            return;
        }

        if ($reason === null) {
            $journal->log(
                'core',
                self::eventPrefix($channel) . '_loss_cleared',
                'info',
                $channel === self::CHANNEL_MAIL
                    ? 'Capture des e-mails : plus de blocage, l\'envoi suit de nouveau sa règle ordinaire.'
                    : 'Téléphonie simulée : plus de blocage, la ligne SOS suit de nouveau sa règle ordinaire.',
                ['previous_reason' => $storedReason]
            );

            return;
        }

        $journal->log(
            'core',
            self::lossEvent($channel),
            'security',
            $channel === self::CHANNEL_MAIL
                ? 'Capture des e-mails armée mais sans effet (' . self::reasonLabel($reason) . ') : '
                    . 'l\'envoi est suspendu jusqu\'à la décision d\'un super-administrateur.'
                : 'Téléphonie simulée armée mais sans effet (' . self::reasonLabel($reason) . ') : '
                    . 'la vraie ligne SOS n\'est plus pilotée jusqu\'à la décision d\'un super-administrateur.',
            ['reason' => $reason]
        );
    }

    /** The reason this channel is lost for, or null when it is not. */
    public function reason(string $channel): ?string
    {
        self::assertChannel($channel);
        if (array_key_exists($channel, $this->live)) {
            return $this->live[$channel];
        }

        return self::parse($this->storedValue($channel))[0];
    }

    /** Lost, and not released by a superadmin: real sending stays shut. */
    public function isBlocked(string $channel): bool
    {
        $reason = $this->reason($channel);
        if ($reason === null) {
            return false;
        }

        return !$this->isReleasedFor($channel, $reason);
    }

    /** Lost, and a superadmin chose to reopen real sending anyway. */
    public function isReleased(string $channel): bool
    {
        $reason = $this->reason($channel);

        return $reason !== null && $this->isReleasedFor($channel, $reason);
    }

    /**
     * The superadmin's decision: reopen real sending on this channel while
     * the loss lasts. Journaled at level `security` with the acting
     * account. False when there was nothing to release.
     */
    public function release(string $channel, ?int $userId, JournalService $journal): bool
    {
        self::assertChannel($channel);
        $stored = $this->storedValue($channel);
        [$reason, $released] = self::parse($stored);
        if ($reason === null || $released) {
            return false;
        }

        if (!$this->settingService->replaceIfUnchanged(
            self::settingKey($channel),
            $stored,
            $reason . self::RELEASED_SUFFIX
        )) {
            return false;
        }

        $journal->log(
            'core',
            self::eventPrefix($channel) . '_loss_released',
            'security',
            $channel === self::CHANNEL_MAIL
                ? 'Envoi réel des e-mails rouvert par un super-administrateur alors que la capture est armée '
                    . 'mais sans effet.'
                : 'Vraie ligne SOS rouverte par un super-administrateur alors que la téléphonie simulée est armée '
                    . 'mais sans effet.',
            ['reason' => $reason],
            $userId
        );

        return true;
    }

    /** The loss line's event type: `mail_capture_lost` or `telephony_simulation_lost`. */
    public static function lossEvent(string $channel): string
    {
        return self::eventPrefix($channel) . '_lost';
    }

    /** What a reason means, in the words a superadmin reads. */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_PROFILE_MISSING => 'le profil d\'installation est absent de config/app.php',
            self::REASON_HOST_MISMATCH => 'l\'adresse du site ne correspond plus au profil d\'installation',
            self::REASON_MODULE_UNLOADED => 'le module Outils de test n\'est plus chargé',
            default => 'raison inconnue',
        };
    }

    /** What fixing a reason means, as an instruction. */
    public static function remedy(string $reason): string
    {
        return match ($reason) {
            self::REASON_PROFILE_MISSING => 'Rétablissez la ligne installation_profile dans config/app.php '
                . '(reference_installation ou local_installation).',
            self::REASON_HOST_MISMATCH => 'Rétablissez l\'adresse du site attendue par le profil déclaré dans '
                . 'config/app.php, ou corrigez ce profil.',
            self::REASON_MODULE_UNLOADED => 'Réactivez le module Outils de test dans Configuration › Modules, '
                . 'puis désarmez l\'outil depuis sa page si vous n\'en avez plus besoin.',
            default => '',
        };
    }

    private function isReleasedFor(string $channel, string $reason): bool
    {
        return $this->storedValue($channel) === $reason . self::RELEASED_SUFFIX;
    }

    private function storedValue(string $channel): string
    {
        return (string) ($this->settingService->get(self::settingKey($channel)) ?? '');
    }

    /**
     * @return array{0: ?string, 1: bool} the stored reason (null when none
     *         or unreadable) and whether it was released
     */
    private static function parse(string $stored): array
    {
        $released = str_ends_with($stored, self::RELEASED_SUFFIX);
        $reason = $released ? substr($stored, 0, -strlen(self::RELEASED_SUFFIX)) : $stored;

        return in_array($reason, self::REASONS, true) ? [$reason, $released] : [null, false];
    }

    private static function settingKey(string $channel): string
    {
        return $channel === self::CHANNEL_MAIL ? self::SETTING_MAIL : self::SETTING_TELEPHONY;
    }

    private static function eventPrefix(string $channel): string
    {
        return $channel === self::CHANNEL_MAIL ? 'mail_capture' : 'telephony_simulation';
    }

    private static function assertChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException("Unknown sandbox channel: {$channel}");
        }
    }
}
