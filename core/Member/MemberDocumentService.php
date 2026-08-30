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
