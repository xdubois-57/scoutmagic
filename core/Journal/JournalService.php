<?php

declare(strict_types=1);

namespace Core\Journal;

class JournalService
{
    public function __construct(private JournalRepository $repository)
    {
    }

    /**
     * Log an event.
     *
     * @param string      $category    'core' or module_id
     * @param string      $type        e.g. 'login_success', 'import_desk'
     * @param string      $level       'info' or 'security'
     * @param string      $description Human-readable short description (no personal data!)
     * @param array<string, mixed> $context Optional JSON context
     * @param int|null    $userId      User account ID, null for system actions
     */
    public function log(
        string $category,
        string $type,
        string $level,
        string $description,
        array $context = [],
        ?int $userId = null
    ): void {
        $contextJson = !empty($context) ? json_encode($context) : null;
        $this->repository->insert($category, $type, $level, $description, $contextJson, $userId, self::currentIp());
    }

    /**
     * Best-effort client IP for the current request. Null on CLI / when unavailable.
     */
    private static function currentIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Clean up old entries based on retention setting.
     */
    public function cleanup(int $retentionDays): int
    {
        return $this->repository->deleteOlderThan($retentionDays);
    }
}
