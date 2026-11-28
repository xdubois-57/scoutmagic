<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

/** A MemberMergeListener that records its calls (issue #1462). */
final class RecordingMergeListener implements \Core\Member\Duplicate\MemberMergeListener
{
    /** @var list<array{int, int}> */
    public array $calls = [];

    /**
     * @param array<string, int> $preview
     */
    public function __construct(
        private array $preview,
        private int $moved,
        private ?\Throwable $failure = null
    ) {
    }

    public function mergePreview(int $duplicateMemberId): array
    {
        return $this->preview;
    }

    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->calls[] = [$keptMemberId, $duplicateMemberId];

        return $this->moved;
    }

    public function moduleId(): string
    {
        return 'presences';
    }
}
