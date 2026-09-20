<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Modules\OfficialDocuments\Api\DocumentLink;
use Modules\OfficialDocuments\Api\MemberOfficialDocumentsProvider;
use Modules\OfficialDocuments\Api\OfficialDocumentsSummary;


/**
 * The block this module contributes to a member's own page.
 *
 * Lives in `Service\` rather than `Api\`: the interface is what other code
 * names, the implementation is this module's own business (ARCHITECTURE.md
 * §7.5). Only the composition root ever writes this class's name.
 */
final class MemberDocumentsSummaryService implements MemberOfficialDocumentsProvider
{
    /**
     * The sentence the whole module exists around, said once, here.
     *
     * On the web page and never on the PDF: the federation's document says
     * what it says, and the site does not add mentions to it.
     */
    public const WARNING = 'Un document imprimé mais non signé n\'a aucune valeur. '
        . 'Seule la version signée et remise à l\'animateur compte.';

    public function __construct(private readonly ?HealthSheetService $healthSheets = null)
    {
    }

    public function summaryFor(int $memberYearId, int $memberId): OfficialDocumentsSummary
    {
        return new OfficialDocumentsSummary(
            [
                new DocumentLink(
                    'Autorisation parentale',
                    'Formulaire de la fédération, pré-rempli',
                    '/members/' . $memberYearId . '/autorisation-parentale'
                ),
                new DocumentLink(
                    'Fiche santé',
                    $this->healthSheetNote($memberId),
                    '/members/' . $memberYearId . '/fiche-sante'
                ),
            ],
            self::WARNING
        );
    }

    /**
     * The one line under the health sheet link.
     *
     * Says whether there is anything on file and when it was last touched
     * — and NOTHING about what it contains. That date is read without
     * decrypting a thing (`HealthSheetRepository::lastUsedAt()`): a note
     * beside a link is not a reason to put a child's health data through a
     * cipher.
     */
    private function healthSheetNote(int $memberId): string
    {
        $lastUsed = $this->healthSheets?->lastUsedAt($memberId);

        return $lastUsed === null
            ? 'À compléter une fois, réutilisable ensuite'
            : 'Complétée le ' . $lastUsed->format('d/m/Y');
    }
}
