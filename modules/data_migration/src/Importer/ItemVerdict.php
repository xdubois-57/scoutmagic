<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * What an import says would become of one item.
 *
 * Four outcomes, matching the report's categories: import it, it is
 * already present (by the import's own heuristics — the module checks its
 * correspondence table first), ignore it for a reason, or ambiguous (looks
 * like something present without certainty: neither created nor merged,
 * counted with the ignored in the report).
 *
 * Two optional riders on « import »:
 * - {@see needingScoutYear()}: the item belongs to a scout year, by label.
 *   A past year this site does not have is offered for creation in the
 *   preview (D5); an item whose year is not created is ignored. The
 *   import reads the id back with {@see ImportContext::scoutYearId()}.
 * - {@see tagged()}: counters the import's own {@see Importer::previewNotes()}
 *   reads back (« 17 actualités n'ont pas d'image »).
 */
final class ItemVerdict
{
    public const IMPORT = 'import';
    public const PRESENT = 'present';
    public const IGNORED = 'ignored';
    public const AMBIGUOUS = 'ambiguous';

    /**
     * @param list<string> $tags
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly ?string $scoutYearLabel = null,
        public readonly array $tags = []
    ) {
    }

    public static function import(): self
    {
        return new self(self::IMPORT);
    }

    /** Already on this site, found by the import's own rule. */
    public static function present(): self
    {
        return new self(self::PRESENT);
    }

    /** @param string $reason French, short, without any name (D8) */
    public static function ignored(string $reason): self
    {
        return new self(self::IGNORED, $reason);
    }

    /** @param string $reason French, e.g. « Ressemble à « Ferme du Bois-Haut », Hamois » */
    public static function ambiguous(string $reason): self
    {
        return new self(self::AMBIGUOUS, $reason);
    }

    public function needingScoutYear(string $label): self
    {
        return new self($this->outcome, $this->reason, $label, $this->tags);
    }

    public function tagged(string ...$tags): self
    {
        return new self($this->outcome, $this->reason, $this->scoutYearLabel, [...$this->tags, ...$tags]);
    }
}
