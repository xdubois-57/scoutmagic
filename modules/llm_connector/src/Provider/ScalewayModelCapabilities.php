<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/**
 * Documented chat candidates and vision hints, not substring guesses about IDs.
 *
 * Scaleway's published /v1/models schema supplies only id/object/created/owned_by,
 * so it cannot establish chat or image support. These exact identifiers and
 * modalities were checked against its official catalogue on 2026-11-04:
 * https://www.scaleway.com/en/docs/generative-apis/reference-content/supported-models/
 * Vision models use /v1/chat/completions, as its vision guide explicitly states.
 * Audio and code-only entries without a clear chat guarantee remain unverified.
 * An identifier absent here stays unavailable until its capabilities are checked;
 * discovering a new name never grants it a capability. Image support must also
 * pass the bounded synthetic challenge before it can back an image tier.
 */
final class ScalewayModelCapabilities
{
    /** @var array<string, array{chat: ?bool, vision: ?bool}> */
    private const MODELS = [
        'bge-multilingual-gemma2' => ['chat' => false, 'vision' => false],
        'deepseek-r1-distill-llama-70b' => ['chat' => true, 'vision' => false],
        'deepseek-r1-distill-llama-8b' => ['chat' => true, 'vision' => false],
        'deepseek-v4-flash-0731' => ['chat' => true, 'vision' => false],
        'deepseek-v4.1-flash-1017' => ['chat' => true, 'vision' => true],
        'devstral-2-123b-instruct-2512' => ['chat' => true, 'vision' => false],
        'devstral-small-2505' => ['chat' => true, 'vision' => false],
        'gemma-3-27b-it' => ['chat' => true, 'vision' => true],
        'gemma-4-26b-a4b-it' => ['chat' => true, 'vision' => true],
        'gemma-4-31b-it' => ['chat' => true, 'vision' => true],
        'glm-5.2' => ['chat' => true, 'vision' => false],
        'glm-5.3' => ['chat' => true, 'vision' => false],
        'gpt-oss-120b' => ['chat' => true, 'vision' => false],
        'gpt-oss-20b' => ['chat' => true, 'vision' => false],
        'holo2-30b-a3b' => ['chat' => true, 'vision' => true],
        'lightonocr-3-0.8b' => ['chat' => false, 'vision' => false],
        'llama-3-70b-instruct' => ['chat' => true, 'vision' => false],
        'llama-3-8b-instruct' => ['chat' => true, 'vision' => false],
        'llama-3.1-70b-instruct' => ['chat' => true, 'vision' => false],
        'llama-3.1-8b-instruct' => ['chat' => true, 'vision' => false],
        'llama-3.1-nemotron-70b-instruct' => ['chat' => true, 'vision' => false],
        'llama-3.3-70b-instruct' => ['chat' => true, 'vision' => false],
        'magistral-small-2506' => ['chat' => true, 'vision' => false],
        'minimax-m2.5' => ['chat' => null, 'vision' => null],
        'mistral-7b-instruct-v0.3' => ['chat' => true, 'vision' => false],
        'mistral-large-3-675b-instruct-2512' => ['chat' => true, 'vision' => true],
        'mistral-medium-3.5-128b' => ['chat' => true, 'vision' => true],
        'mistral-nemo-instruct-2407' => ['chat' => true, 'vision' => false],
        'mistral-small-24b-instruct-2501' => ['chat' => true, 'vision' => false],
        'mistral-small-3.1-24b-instruct-2503' => ['chat' => true, 'vision' => true],
        'mistral-small-3.2-24b-instruct-2506' => ['chat' => true, 'vision' => true],
        'mixtral-8x7b-instruct-v0.1' => ['chat' => true, 'vision' => false],
        'molmo-72b-0924' => ['chat' => true, 'vision' => true],
        'pixtral-12b-2409' => ['chat' => true, 'vision' => true],
        'qwen2.5-coder-32b-instruct' => ['chat' => null, 'vision' => null],
        'qwen3-235b-a22b-instruct-2507' => ['chat' => true, 'vision' => false],
        'qwen3-235b-a22b-thinking-2507' => ['chat' => true, 'vision' => false],
        'qwen3-coder-30b-a3b-instruct' => ['chat' => null, 'vision' => null],
        'qwen3-embedding-8b' => ['chat' => false, 'vision' => false],
        'qwen3.5-122b-a10b' => ['chat' => true, 'vision' => true],
        'qwen3.5-35b-a3b' => ['chat' => true, 'vision' => true],
        'qwen3.5-397b-a17b' => ['chat' => true, 'vision' => true],
        'qwen3.6-35b-a3b' => ['chat' => true, 'vision' => true],
        'qwen3.8-27b' => ['chat' => true, 'vision' => true],
        'sentence-t5-xxl' => ['chat' => false, 'vision' => false],
        'voxtral-small-24b-2507' => ['chat' => null, 'vision' => null],
        'whisper-large-v3' => ['chat' => false, 'vision' => false],
    ];

    /** @return array{chat: ?bool, vision: ?bool} */
    public static function forModel(string $modelId): array
    {
        return self::MODELS[$modelId] ?? ['chat' => null, 'vision' => null];
    }
}
