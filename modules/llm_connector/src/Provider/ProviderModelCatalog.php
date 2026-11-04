<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/** Provider evidence, rather than model names or an LLM's opinion, gates tiers. */
final class ProviderModelCatalog
{
    /** @var array<string, array{id: string, display_name: string, chat: ?bool, vision: ?bool}> */
    private readonly array $models;

    /**
     * Unknown capabilities stay unknown: they are never promoted to support.
     * Conflicting entries for one identifier are also unverified.
     *
     * @param list<array{id: string, display_name: string, chat?: ?bool, vision?: ?bool}> $models
     */
    public function __construct(array $models)
    {
        $byId = [];
        foreach ($models as $model) {
            if ($model['id'] === '') {
                continue;
            }
            $id = $model['id'];
            $entry = [
                'id' => $id,
                'display_name' => $model['display_name'],
                'chat' => $model['chat'] ?? null,
                'vision' => $model['vision'] ?? null,
            ];
            if (isset($byId[$id])
                && ($byId[$id]['chat'] !== $entry['chat'] || $byId[$id]['vision'] !== $entry['vision'])) {
                $entry['chat'] = null;
                $entry['vision'] = null;
            }
            $byId[$id] = $entry;
        }
        $this->models = $byId;
    }

    /** @return list<array{id: string, display_name: string}> */
    public function chatModels(): array
    {
        $models = [];
        foreach ($this->models as $model) {
            if ($model['chat'] === true) {
                $models[] = ['id' => $model['id'], 'display_name' => $model['display_name']];
            }
        }

        return $models;
    }

    /** Documentation establishes chat candidates; only probe evidence grants images here. */
    public function withImageProbes(ModelProbeCache $cache): self
    {
        $models = [];
        foreach ($this->models as $model) {
            $status = $cache->resultFor($model['id']);
            $model['vision'] = $status === ModelProbeStatus::VISION_VERIFIED ? true : null;
            if ($status === ModelProbeStatus::CHAT_UNSUPPORTED) {
                $model['chat'] = false;
                $model['vision'] = false;
            }
            $models[] = $model;
        }
        return new self($models);
    }

    public function withoutChatModel(string $id): self
    {
        $models = $this->models;
        if (isset($models[$id])) {
            $models[$id]['chat'] = false;
            $models[$id]['vision'] = false;
        }
        return new self(array_values($models));
    }

    /** @return list<string> */
    public function chatModelIds(): array
    {
        return array_column($this->chatModels(), 'id');
    }

    /** @return list<string> */
    public function visionModelIds(): array
    {
        $ids = [];
        foreach ($this->models as $model) {
            if ($model['chat'] === true && $model['vision'] === true) {
                $ids[] = $model['id'];
            }
        }

        return $ids;
    }

    /** @return array<string, string> model identifier => metadata-only refusal reason */
    public function exclusions(): array
    {
        $excluded = [];
        foreach ($this->models as $model) {
            if ($model['chat'] !== true) {
                $excluded[$model['id']] = $model['chat'] === false
                    ? 'chat_endpoint_unsupported' : 'chat_capability_unverified';
            }
        }

        return $excluded;
    }
}
