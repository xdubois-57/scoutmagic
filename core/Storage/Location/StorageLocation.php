<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Storage\Location\Config\LocationConfig;

/**
 * One declared destination for bytes.
 *
 * Declared centrally, CHOSEN locally: this object says where a destination
 * is and what it can do, and says nothing about who uses it. Each consumer
 * — the gallery, the off-site backup — keeps the identifier of the
 * location it picked in its own setting, and the Stockage screen reads
 * those to show what is served. There is deliberately no join table: an
 * assignment belongs to whoever made it, and a table in the middle would
 * make the storage page the owner of decisions it does not take.
 *
 * The secret is absent by construction — {@see $secretConfigured} says
 * only whether one exists. Reading the real value is
 * {@see StorageLocationRepository::getSecret()}, and nothing that renders,
 * journals or exports a location ever holds it.
 */
final class StorageLocation
{
    public function __construct(
        public readonly int $id,
        public readonly StorageLocationType $type,
        public readonly string $label,
        public readonly bool $isDefault,
        public readonly LocationConfig $config,
        public readonly bool $secretConfigured,
        public readonly ?string $lastCheckedAt,
        public readonly ?bool $lastCheckOk,
        public readonly ?string $lastCheckError,
        public readonly string $createdAt
    ) {
    }

    /**
     * @return list<StorageCapability>
     */
    public function capabilities(): array
    {
        return $this->type->capabilities();
    }

    public function supports(StorageCapability $capability): bool
    {
        return $this->type->supports($capability);
    }

    public function isLocal(): bool
    {
        return $this->type === StorageLocationType::Local;
    }

    /**
     * True when anything stored here is readable by anybody holding the
     * URL, for ever. See {@see Config\LocationConfig::
     * servesPubliclyWithoutExpiry()} — a consumer that carries its own
     * access control refuses such a location outright.
     */
    public function servesPubliclyWithoutExpiry(): bool
    {
        return $this->config->servesPubliclyWithoutExpiry();
    }

    /** One line naming where this points, safe to render. */
    public function describe(): string
    {
        return $this->config->describe();
    }
}
