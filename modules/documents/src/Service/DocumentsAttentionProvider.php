<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * « 3 documents sont expirés » (#731): one aggregated point while any
 * shared document is past its expiry date, gone as soon as none is.
 *
 * It names no document: the list it leads to does, with a red « Expiré »
 * on each one concerned. An expired document stays online — the point is
 * an invitation to check it, not a takedown.
 */
class DocumentsAttentionProvider implements AttentionPointProvider
{
    public function __construct(private DocumentService $documents)
    {
    }

    public function sourceLabel(): string
    {
        return 'Documents';
    }

    /**
     * @return AttentionPoint[]
     */
    public function collect(int $scoutYearId): array
    {
        $expired = $this->documents->countExpired();
        if ($expired === 0) {
            return [];
        }

        return [new AttentionPoint(
            title: $expired === 1 ? '1 document est expiré' : $expired . ' documents sont expirés',
            why: 'Certains documents partagés doivent être vérifiés : ils restent en ligne, mais leur date de '
                . 'validité est dépassée. Repoussez-la s\'ils sont toujours valables, ou remplacez le fichier.',
            actionLabel: 'Voir les documents',
            actionUrl: '/admin/documents',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
