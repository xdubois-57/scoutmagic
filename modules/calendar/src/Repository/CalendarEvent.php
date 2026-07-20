<?php

declare(strict_types=1);

namespace Modules\Calendar\Repository;

class CalendarEvent
{
    public function __construct(
        public readonly int $id,
        public readonly int $calendarId,
        public readonly string $title,
        public readonly string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly ?string $location,
        public readonly ?string $description,
        public readonly int $sequence,
        public readonly ?int $createdBy,
        public readonly string $updatedAt
    ) {
    }

    public function isAllDay(): bool
    {
        return $this->startTime === null;
    }

    public function isMultiDay(): bool
    {
        return $this->endDate !== null && $this->endDate !== $this->startDate;
    }
}
