<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Maintenance\Health\Security\Advisory;
use Core\Maintenance\Health\Security\ReferenceSource;
use Core\Maintenance\Health\Security\ReferenceSourceUnavailable;
use Core\Maintenance\Health\Security\ReleaseBranch;

/**
 * Synthetic reference sources: what each would answer, which ones fail,
 * and every request made — so a test can say how many times the NVD was
 * asked, and that the page asked nothing.
 */
final class FakeReferenceSource implements ReferenceSource
{
    /** @var list<string> */
    public array $requests = [];

    /** @var array<string, true> sources that throw: 'php.net', 'endoflife:<product>', 'nvd' */
    public array $failing = [];

    /**
     * @param list<ReleaseBranch> $php
     * @param array<string, list<ReleaseBranch>> $endOfLife by product
     * @param array<string, list<Advisory>> $advisories by CPE name; a CPE not listed has none
     */
    public function __construct(
        public array $php = [],
        public array $endOfLife = [],
        public array $advisories = [],
    ) {
    }

    public function phpBranches(): array
    {
        $this->requests[] = 'php.net';
        $this->failIf('php.net');

        return $this->php;
    }

    public function endOfLifeBranches(string $product): array
    {
        $this->requests[] = 'endoflife:' . $product;
        $this->failIf('endoflife:' . $product);

        return $this->endOfLife[$product] ?? [];
    }

    public function advisories(string $cpeName): array
    {
        $this->requests[] = 'nvd:' . $cpeName;
        $this->failIf('nvd');

        return $this->advisories[$cpeName] ?? [];
    }

    /** @return list<string> */
    public function nvdRequests(): array
    {
        return array_values(array_filter($this->requests, static fn(string $r): bool => str_starts_with($r, 'nvd:')));
    }

    private function failIf(string $source): void
    {
        if (isset($this->failing[$source])) {
            throw new ReferenceSourceUnavailable($source . ' is down');
        }
    }
}
