<?php

declare(strict_types=1);

namespace Modules\Trombinoscope\Service;

use Core\Module\FunctionFlagsProvider;
use Modules\Trombinoscope\Repository\FunctionFlagsRepository;

/**
 * Implements the core FunctionFlagsProvider hook so the Config Desk
 * configuration page can let admins mark, per function, whether it's a
 * section's "responsable" — without the core page hardcoding any function
 * name. Every active chief/chief-d'unité member is shown on the
 * trombinoscope regardless; this only controls the highlighted lead card.
 */
class FunctionFlagsService implements FunctionFlagsProvider
{
    public function __construct(private FunctionFlagsRepository $repository)
    {
    }

    public function getSectionLabel(): string
    {
        return 'Trombinoscope';
    }

    public function getLeadLabel(): string
    {
        return 'Responsable de section';
    }

    public function getLeadFlags(): array
    {
        return $this->repository->getLeadFlags();
    }

    public function setLead(int $functionId, bool $lead): void
    {
        $this->repository->setLead($functionId, $lead);
    }
}
