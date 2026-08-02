<?php

declare(strict_types=1);

namespace Core\Member;

class MemberDocumentService
{
    public function __construct(private MemberDocumentRepository $repository)
    {
    }

    /**
     * @return MemberDocument[] Most recent first.
     */
    public function listForMember(int $memberId, int $scoutYearId): array
    {
        return $this->repository->findByMemberAndYear($memberId, $scoutYearId);
    }
}
