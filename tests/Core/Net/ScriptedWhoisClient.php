<?php

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\WhoisClient;

/**
 * A registry chain that answers from a script rather than from port 43.
 *
 * `WhoisClient::ask()` is the one socket and it exists alone in its own
 * method precisely so this can stand in for it. A test that opened a real
 * connection would be a test of somebody else's registry, on a runner that
 * very probably cannot reach one — and would be rate-limited for trying.
 *
 * A server the script does not mention answers null: « personne n'a
 * répondu », which is the ordinary case on a host with no outbound 43.
 */
class ScriptedWhoisClient extends WhoisClient
{
    /** @var array<int, array{0: string, 1: string}> */
    private array $asked = [];

    /** @param array<string, array<string, string>> $answers server => query => response */
    public function __construct(private array $answers)
    {
        parent::__construct();
    }

    protected function ask(string $server, string $query, float $deadline): ?string
    {
        $this->asked[] = [$server, $query];

        return $this->answers[$server][$query] ?? null;
    }

    /** @return array<int, array{0: string, 1: string}> */
    public function asked(): array
    {
        return $this->asked;
    }

    public function timesAsked(string $server, string $query): int
    {
        return count(array_filter(
            $this->asked,
            static fn(array $call): bool => $call === [$server, $query]
        ));
    }
}
