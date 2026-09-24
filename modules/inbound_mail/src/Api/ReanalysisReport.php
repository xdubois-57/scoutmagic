<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What « Relancer l'analyse » found (`InboundMailInterface::reanalyzeUnlinked()`),
 * and the sentence every triage screen says it with — so the camps and the
 * rentals never word the same result two ways.
 */
final class ReanalysisReport
{
    public function __construct(
        public readonly int $examined,
        public readonly int $linked,
        public readonly int $proposed
    ) {
    }

    /**
     * @param array{examined: int, linked: int, proposed: int} $report
     */
    public static function fromArray(array $report): self
    {
        return new self($report['examined'], $report['linked'], $report['proposed']);
    }

    public function message(): string
    {
        if ($this->examined === 0) {
            return 'Aucun message en attente : tout ce qui est conservé est déjà rattaché.';
        }

        $found = [];
        if ($this->linked > 0) {
            $found[] = $this->linked . ' rattachement' . ($this->linked > 1 ? 's' : '');
        }
        if ($this->proposed > 0) {
            $found[] = $this->proposed . ' proposition' . ($this->proposed > 1 ? 's' : '');
        }

        return sprintf(
            '%d message%s réexaminé%s : %s. La lecture des pièces jointes se poursuit en arrière-plan.',
            $this->examined,
            $this->examined > 1 ? 's' : '',
            $this->examined > 1 ? 's' : '',
            $found === [] ? 'rien de neuf pour l\'instant' : implode(' et ', $found)
        );
    }
}
