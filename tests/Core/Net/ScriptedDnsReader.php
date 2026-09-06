<?php

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\DnsRecordReader;

/**
 * A resolver that answers from a script rather than from the network.
 *
 * `DnsRecordReader::query()` is the one call to the system resolver and it
 * exists alone in its own method precisely so this can stand in for it. A
 * test that resolved a real name would be a test of somebody else's DNS,
 * on a runner that may not have one.
 *
 * A type the script does not mention answers `[]` — « rien de ce type »,
 * which is the ordinary answer for most types on most zones. `false` is
 * how the script says « le résolveur n'a pas répondu », and telling those
 * two apart is what the whole snapshot is for.
 *
 * In its own file rather than inside a test: two suites need it — the
 * reader's own, and the ticket intake that keeps what it reads.
 */
class ScriptedDnsReader extends DnsRecordReader
{
    /** @param array<int, array<int, array<string, mixed>>|false> $answers */
    public function __construct(private array $answers)
    {
        parent::__construct();
    }

    protected function query(string $host, int $type): array|false
    {
        return $this->answers[$type] ?? [];
    }
}
