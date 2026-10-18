<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Config\SettingService;
use Core\Service\DateInput;
use Modules\LlmConnector\Repository\ProviderRepository;

/**
 * Whether the last call to the AI provider failed, and when (issue #718 § C
 * point 9) — so the attention page can say the AI is broken without asking
 * it.
 *
 * Until now the outcome lived only in the journal, which is append-only
 * and has no "latest event of this kind" to read cheaply. Two internal
 * settings (module.json, not editable) are the whole state: a failure
 * writes both, anything proving the provider answers clears both — a
 * request that completed, or a connection test on the configuration page.
 *
 * Best-effort on every write: bookkeeping must never turn a successful
 * answer into an error, nor hide the provider's own failure behind a
 * database one.
 */
class LastFailureStore
{
    public const SETTING_AT = 'llm_last_failure_at';
    public const SETTING_CODE = 'llm_last_failure_code';

    private const MODULE = 'llm_connector';

    public function __construct(private SettingService $settingService)
    {
    }

    public function recordFailure(int $code, \DateTimeImmutable $at): void
    {
        try {
            $this->settingService->setInternal(self::SETTING_AT, $at->format('Y-m-d H:i:s'), self::MODULE);
            $this->settingService->setInternal(self::SETTING_CODE, (string) $code, self::MODULE);
        } catch (\Throwable) {
            // See the class comment.
        }
    }

    /** Writes only when there is a failure to clear, never on every request. */
    public function clear(): void
    {
        try {
            if ((string) $this->settingService->get(self::SETTING_AT, self::MODULE, '') === '') {
                return;
            }
            $this->settingService->setInternal(self::SETTING_AT, '', self::MODULE);
            $this->settingService->setInternal(self::SETTING_CODE, '', self::MODULE);
        } catch (\Throwable) {
            // See the class comment.
        }
    }

    /**
     * A passing connection test proves the provider answers again: the
     * point about its last failed call should not wait for the next real
     * request to go away — but only when the provider tested is the one
     * requests go to (ProviderRepository::findFirstActive()).
     */
    public function clearAfterPassingTest(int $testedProviderId, ProviderRepository $providers): void
    {
        try {
            $active = $providers->findFirstActive();
        } catch (\Throwable) {
            return;
        }
        if ($active !== null && $active['id'] === $testedProviderId) {
            $this->clear();
        }
    }

    /** @return array{at: \DateTimeImmutable, code: int}|null */
    public function lastFailure(): ?array
    {
        $at = DateInput::fromStorage((string) $this->settingService->get(self::SETTING_AT, self::MODULE, ''));
        if ($at === null) {
            return null;
        }

        return ['at' => $at, 'code' => (int) $this->settingService->get(self::SETTING_CODE, self::MODULE, '0')];
    }
}
