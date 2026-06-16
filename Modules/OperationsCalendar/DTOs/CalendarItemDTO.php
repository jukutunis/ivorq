<?php

namespace Modules\OperationsCalendar\DTOs;

use Illuminate\Support\Carbon;
use Modules\OperationsCalendar\Enums\CalendarItemType;
use Modules\OperationsCalendar\Enums\CalendarSeverity;

class CalendarItemDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $source_domain,
        public readonly CalendarItemType $source_type,
        public readonly string $source_id,
        public readonly string $property_id,
        public readonly string $title,
        public readonly Carbon $start_datetime,
        public readonly Carbon $end_datetime,
        public readonly string $status,
        public readonly CalendarSeverity $severity,
        public readonly ?string $venue_id = null,
        public readonly array $metadata = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_domain' => $this->source_domain,
            'source_type' => $this->source_type->value,
            'source_id' => $this->source_id,
            'property_id' => $this->property_id,
            'title' => $this->title,
            'start_datetime' => $this->start_datetime->toIso8601String(),
            'end_datetime' => $this->end_datetime->toIso8601String(),
            'status' => $this->status,
            'severity' => $this->severity->value,
            'venue_id' => $this->venue_id,
            'metadata' => $this->metadata,
        ];
    }
}
