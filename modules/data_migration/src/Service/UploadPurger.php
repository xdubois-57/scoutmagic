<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;

/**
 * Deletes deposited files 24 hours after their run last moved.
 *
 * One rule for every state, because what matters is not where a run
 * stopped but that it stopped: done, abandoned, or idle at any step —
 * `uploaded`, `analysed`, `confirmed` never started, `interrupted`, and a
 * `running` run whose page went silent ({@see RunService::STALE_RUNNING_SECONDS},
 * after which it is shown and treated as interrupted). Every slice and
 * every state change stamps the run's last movement, so a run that is
 * moving has, by definition, moved within the last 24 hours: its file is
 * never touched.
 *
 * An active run that loses its file can go nowhere any more, so it becomes
 * `abandoned` — with what it imported kept, as D9 says. Nothing is ever
 * extracted next to the archive (members are read from the ZIP), so the
 * archive — or a form source's workspace — is the whole of what there is
 * to delete; a file no run references any more (a crash between the move
 * and the insert) goes too, on the same 24-hour rule.
 */
class UploadPurger
{
    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private RunRepository $runs,
        private UploadStorage $storage,
        private ?\Closure $clock = null
    ) {
    }

    /**
     * @return array{files: int, orphans: int}
     */
    public function purge(): array
    {
        $now = $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
        $stamp = $now->format('Y-m-d H:i:s');

        $this->runs->interruptRunningIdleSince(
            $now->sub(new \DateInterval('PT' . RunService::STALE_RUNNING_SECONDS . 'S'))->format('Y-m-d H:i:s')
        );

        $cutoff = $now->sub(new \DateInterval('PT' . RunService::FILE_RETENTION_SECONDS . 'S'));
        $purged = 0;
        foreach ($this->runs->findWithFileIdleSince($cutoff->format('Y-m-d H:i:s')) as $run) {
            $this->storage->delete($run->filePath);
            $changes = ['file_path' => null, 'file_purged_at' => $stamp];
            if ($run->isActive()) {
                $changes['state'] = Run::ABANDONED;
                $changes['finished_at'] = $stamp;
            }
            $this->runs->update($run, $changes, $stamp);
            $purged++;
        }

        $orphans = $this->storage->orphans($this->runs->referencedFilePaths(), $cutoff->getTimestamp());
        foreach ($orphans as $orphan) {
            $this->storage->delete($orphan);
        }

        return ['files' => $purged, 'orphans' => count($orphans)];
    }
}
