<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Modules\Social\Meta\MetaTransport;

/**
 * Plays Meta: answers each request with the first scripted answer whose
 * URL fragment it contains, and records every request.
 */
final class FakeMetaTransport implements MetaTransport
{
    /** @var list<array{method: string, url: string, fields: array<string, string>}> */
    public array $requests = [];

    /**
     * @param array<string, array{status: int, body: string}|null> $answers
     */
    public function __construct(public array $answers)
    {
    }

    public function get(string $url): ?array
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'fields' => []];

        return $this->answer($url);
    }

    public function postForm(string $url, array $fields): ?array
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'fields' => $fields];

        return $this->answer($url);
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function answer(string $url): ?array
    {
        foreach ($this->answers as $fragment => $answer) {
            if (str_contains($url, $fragment)) {
                return $answer;
            }
        }

        throw new \LogicException('Unexpected request to Meta: ' . $url);
    }
}
