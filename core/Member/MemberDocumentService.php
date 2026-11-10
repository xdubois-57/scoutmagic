<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

class MemberDocumentService
{
    public function __construct(private MemberDocumentRepository $repository)
    {
    }

    /**
     * The member's own page: the documents of the year being shown.
     *
     * @return MemberDocument[] Most recent first.
     */
    public function listForMember(int $memberId, int $scoutYearId): array
    {
        return $this->repository->findByMemberAndYear($memberId, $scoutYearId);
    }

    /**
     * The member's own page (« Espace des animés », issue #1094): the
     * documents of the window of seasons PrivateDocumentYears gives — the
     * site's year and the two before it, whatever year of the sheet is
     * shown — each with the label of its season, newest first.
     *
     * @param list<array{id: int, label: string}> $years
     * @return list<array{document: MemberDocument, year_label: string}>
     */
    public function listForMemberPage(int $memberId, array $years): array
    {
        $labels = [];
        foreach ($years as $year) {
            $labels[$year['id']] = $year['label'];
        }

        $rows = [];
        foreach ($this->repository->findByMemberAndYears($memberId, array_keys($labels)) as $document) {
            $rows[] = ['document' => $document, 'year_label' => $labels[$document->scoutYearId] ?? ''];
        }

        return $rows;
    }

    /**
     * The admin member sheet: every year, because the question a chef
     * d'unité is answering (« nous n'avons rien reçu ») is almost always
     * about a past season.
     *
     * @return MemberDocument[] Most recent first.
     */
    public function listForMemberAllYears(int $memberId): array
    {
        return $this->repository->findByMember($memberId);
    }

    public function findDocument(int $documentId): ?MemberDocument
    {
        return $this->repository->findById($documentId);
    }
}
