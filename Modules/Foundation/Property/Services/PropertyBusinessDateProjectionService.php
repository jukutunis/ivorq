<?php

namespace Modules\Foundation\Property\Services;

use Carbon\CarbonImmutable;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use RuntimeException;

class PropertyBusinessDateProjectionService
{
    public const ERROR_NOT_INITIALIZED = 'BD_A1_BUSINESS_DATE_NOT_INITIALIZED';
    public const ERROR_OPEN_UNAVAILABLE = 'BD_A1_OPEN_BUSINESS_DATE_UNAVAILABLE';
    public const ERROR_MULTIPLE_OPEN = 'BD_A1_MULTIPLE_OPEN_BUSINESS_DATES';
    public const ERROR_EVIDENCE_INCOMPLETE = 'BD_A1_OPEN_BUSINESS_DATE_EVIDENCE_INCOMPLETE';
    public const ERROR_TIMEZONE_MISMATCH = 'BD_A1_ACTIVE_TIMEZONE_MISMATCH';

    public function __construct(
        private readonly PropertyBusinessDateAuthorizationService $authorization,
    ) {}

    public function project(User $actor): array
    {
        $property = $this->authorization->authorizeView($actor);
        $businessDate = $this->resolveOpenBusinessDate($property->id);

        if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || $businessDate->is_open !== true) {
            throw new RuntimeException(self::ERROR_EVIDENCE_INCOMPLETE);
        }

        $timezoneSnapshot = trim((string) $businessDate->timezone_snapshot);
        if ($timezoneSnapshot === '' || $businessDate->opened_at === null || trim((string) $businessDate->opened_by) === '') {
            throw new RuntimeException(self::ERROR_EVIDENCE_INCOMPLETE);
        }

        if ((string) $property->timezone !== $timezoneSnapshot) {
            throw new RuntimeException(self::ERROR_TIMEZONE_MISMATCH);
        }

        $openedAt = CarbonImmutable::parse($businessDate->opened_at)->utc()->toISOString();
        $evaluatedAt = CarbonImmutable::now('UTC')->toISOString();
        $fingerprint = $this->fingerprint($businessDate, $openedAt);

        return [
            'status' => 'BUSINESS_DATE_OPEN',
            'source_classification' => 'PROPERTY_BUSINESS_DATE_SOURCE_PROVEN',
            'owner' => 'Business Date / Night Audit',
            'read_only' => true,
            'property_business_date_id' => (string) $businessDate->id,
            'property_id' => (string) $businessDate->property_id,
            'business_date' => $businessDate->business_date->format('Y-m-d'),
            'lifecycle_status' => PropertyBusinessDateStatusEnum::Open->value,
            'property_timezone' => $timezoneSnapshot,
            'opened_at' => $openedAt,
            'opened_by' => (string) $businessDate->opened_by,
            'source_fingerprint' => $fingerprint,
            'evaluated_at' => $evaluatedAt,
        ];
    }

    private function resolveOpenBusinessDate(string $propertyId): PropertyBusinessDate
    {
        $history = PropertyBusinessDate::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        if ($history->isEmpty()) {
            throw new RuntimeException(self::ERROR_NOT_INITIALIZED);
        }

        $disagreeingRows = $history->filter(function (PropertyBusinessDate $row): bool {
            $statusOpen = $row->status === PropertyBusinessDateStatusEnum::Open;
            $flagOpen = $row->is_open === true;

            return $statusOpen !== $flagOpen;
        });

        if ($disagreeingRows->isNotEmpty()) {
            throw new RuntimeException(self::ERROR_EVIDENCE_INCOMPLETE);
        }

        $openRows = $history
            ->filter(fn (PropertyBusinessDate $row): bool => $row->status === PropertyBusinessDateStatusEnum::Open && $row->is_open === true)
            ->values();

        if ($openRows->count() === 0) {
            throw new RuntimeException(self::ERROR_OPEN_UNAVAILABLE);
        }

        if ($openRows->count() > 1) {
            throw new RuntimeException(self::ERROR_MULTIPLE_OPEN);
        }

        return $openRows->first();
    }

    private function fingerprint(PropertyBusinessDate $businessDate, string $openedAt): string
    {
        $canonical = [
            'property_business_date_id' => (string) $businessDate->id,
            'property_id' => (string) $businessDate->property_id,
            'business_date' => $businessDate->business_date->format('Y-m-d'),
            'lifecycle_status' => $businessDate->status->value,
            'is_open' => $businessDate->is_open === true,
            'timezone_snapshot' => (string) $businessDate->timezone_snapshot,
            'opened_by' => (string) $businessDate->opened_by,
            'opened_at' => $openedAt,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }
}
