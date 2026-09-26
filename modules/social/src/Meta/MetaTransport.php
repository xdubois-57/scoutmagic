<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Meta;

/**
 * The wire, and nothing else: status and body in, no interpretation.
 *
 * An interface so the tests can play Meta's part — every rule this
 * module applies to an answer lives in {@see MetaClient}, where a fake
 * transport exercises it.
 */
interface MetaTransport
{
    /**
     * @return array{status: int, body: string}|null null when no answer
     *         arrived at all
     */
    public function get(string $url): ?array;

    /**
     * @param array<string, string> $fields sent as a form
     * @return array{status: int, body: string}|null
     */
    public function postForm(string $url, array $fields): ?array;
}
