<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Repository;

use Modules\LlmConnector\Api\LlmTier;

class ProviderModelRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<int, array{id: int, provider_id: int, model_id: string, display_name: string, is_tier_cheap: bool, is_tier_capable: bool, is_tier_ocr: bool, last_seen_at: string}>
     */
    public function findByProvider(int $providerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM llm_provider_models WHERE provider_id = ? ORDER BY display_name'
        );
        $stmt->execute([$providerId]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->hydrate($row);
        }
        return $rows;
    }

    /**
     * Find the model assigned to a specific tier for a provider.
     *
     * @return array{id: int, provider_id: int, model_id: string, display_name: string}|null
     */
    public function findByProviderAndTier(int $providerId, LlmTier $tier): ?array
    {
        $column = match ($tier) {
            LlmTier::CHEAP => 'is_tier_cheap',
            LlmTier::CAPABLE => 'is_tier_capable',
            LlmTier::OCR => 'is_tier_ocr',
        };

        $stmt = $this->pdo->prepare(
            "SELECT * FROM llm_provider_models WHERE provider_id = ? AND {$column} = 1 LIMIT 1"
        );
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Upsert a model (insert if new, update last_seen_at if existing).
     */
    public function upsert(int $providerId, string $modelId, string $displayName): void
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT id FROM llm_provider_models WHERE provider_id = ? AND model_id = ?'
        );
        $stmt->execute([$providerId, $modelId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE llm_provider_models SET display_name = ?, last_seen_at = ? WHERE id = ?'
            );
            $stmt->execute([$displayName, $now, $existing['id']]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO llm_provider_models (provider_id, model_id, display_name, last_seen_at)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$providerId, $modelId, $displayName, $now]);
        }
    }

    /**
     * Assign a model to a tier. Clears the tier from any other model for the same provider first.
     */
    public function assignTier(int $modelId, LlmTier $tier): void
    {
        $column = match ($tier) {
            LlmTier::CHEAP => 'is_tier_cheap',
            LlmTier::CAPABLE => 'is_tier_capable',
            LlmTier::OCR => 'is_tier_ocr',
        };

        // Get the model's provider_id
        $stmt = $this->pdo->prepare('SELECT provider_id FROM llm_provider_models WHERE id = ?');
        $stmt->execute([$modelId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return;
        }

        $providerId = (int) $row['provider_id'];

        // Clear the tier from all models of this provider
        $stmt = $this->pdo->prepare(
            "UPDATE llm_provider_models SET {$column} = 0 WHERE provider_id = ?"
        );
        $stmt->execute([$providerId]);

        // Set the tier on the selected model
        $stmt = $this->pdo->prepare(
            "UPDATE llm_provider_models SET {$column} = 1 WHERE id = ?"
        );
        $stmt->execute([$modelId]);
    }

    /**
     * Remove a tier assignment from a model.
     */
    public function unassignTier(int $modelId, LlmTier $tier): void
    {
        $column = match ($tier) {
            LlmTier::CHEAP => 'is_tier_cheap',
            LlmTier::CAPABLE => 'is_tier_capable',
            LlmTier::OCR => 'is_tier_ocr',
        };

        $stmt = $this->pdo->prepare(
            "UPDATE llm_provider_models SET {$column} = 0 WHERE id = ?"
        );
        $stmt->execute([$modelId]);
    }

    /**
     * Automatically assign tiers for a provider using the resolved tier map.
     * Clears existing tier assignments for the provider, then sets the new ones.
     *
     * @param int $providerId
     * @param array{cheap: string|null, capable: string|null, ocr: string|null} $tierMap model_id per tier
     */
    public function autoAssignTiers(int $providerId, array $tierMap): void
    {
        // Clear all tier assignments for this provider
        $stmt = $this->pdo->prepare(
            'UPDATE llm_provider_models SET is_tier_cheap = 0, is_tier_capable = 0, is_tier_ocr = 0 WHERE provider_id = ?'
        );
        $stmt->execute([$providerId]);

        if ($tierMap['cheap'] !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE llm_provider_models SET is_tier_cheap = 1 WHERE provider_id = ? AND model_id = ?'
            );
            $stmt->execute([$providerId, $tierMap['cheap']]);
        }

        if ($tierMap['capable'] !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE llm_provider_models SET is_tier_capable = 1 WHERE provider_id = ? AND model_id = ?'
            );
            $stmt->execute([$providerId, $tierMap['capable']]);
        }

        if ($tierMap['ocr'] !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE llm_provider_models SET is_tier_ocr = 1 WHERE provider_id = ? AND model_id = ?'
            );
            $stmt->execute([$providerId, $tierMap['ocr']]);
        }
    }

    /**
     * Delete models for a provider that are NOT in the given list.
     * Used to clean up models no longer returned by the API.
     *
     * @param int $providerId
     * @param array<int, string> $keepModelIds
     */
    public function deleteModelsNotIn(int $providerId, array $keepModelIds): void
    {
        if (empty($keepModelIds)) {
            // Delete all models for this provider
            $stmt = $this->pdo->prepare('DELETE FROM llm_provider_models WHERE provider_id = ?');
            $stmt->execute([$providerId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepModelIds), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM llm_provider_models WHERE provider_id = ? AND model_id NOT IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$providerId], $keepModelIds));
    }

    /**
     * Delete models not seen in the last 30 days for a provider.
     */
    public function deleteStaleModels(int $providerId): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $stmt = $this->pdo->prepare(
            'DELETE FROM llm_provider_models WHERE provider_id = ? AND last_seen_at < ?'
        );
        $stmt->execute([$providerId, $cutoff]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, provider_id: int, model_id: string, display_name: string, is_tier_cheap: bool, is_tier_capable: bool, is_tier_ocr: bool, last_seen_at: string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'provider_id' => (int) $row['provider_id'],
            'model_id' => (string) $row['model_id'],
            'display_name' => (string) $row['display_name'],
            'is_tier_cheap' => (bool) ($row['is_tier_cheap'] ?? 0),
            'is_tier_capable' => (bool) ($row['is_tier_capable'] ?? 0),
            'is_tier_ocr' => (bool) ($row['is_tier_ocr'] ?? 0),
            'last_seen_at' => (string) $row['last_seen_at'],
        ];
    }
}
