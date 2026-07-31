<?php

namespace Modules\Operations\Housekeeping\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
use Shared\Services\CurrentPropertyService;

class ConsumeCheckoutTurnoverHandoffsCommand extends Command
{
    protected $signature = 'housekeeping:consume-checkout-turnover-handoffs
        {property_id : Authoritative Property ULID to process}
        {--limit=25 : Maximum handoffs to process, capped at 100}
        {--lease=60 : Claim lease seconds, 1 through 300}';

    protected $description = 'Consume eligible FD-C2 checkout handoffs into Housekeeping turnover intake evidence.';

    public function handle(
        HousekeepingCheckoutTurnoverIntakeService $consumer,
        CurrentPropertyService $currentProperty,
    ): int {
        $propertyId = (string) $this->argument('property_id');
        $limit = max(1, min(100, (int) $this->option('limit')));
        $lease = max(1, min(300, (int) $this->option('lease')));

        $property = \Modules\Foundation\Property\Models\Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->first();

        if (! $property) {
            $this->line(json_encode([
                'property_id' => $propertyId,
                'outcome' => 'failed',
                'safe_marker' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            ], JSON_UNESCAPED_SLASHES));
            
            $currentProperty->clear();
            return self::FAILURE;
        }

        $processed = 0;
        $delivered = 0;
        $replayed = 0;
        $failed = 0;

        $currentProperty->setPropertyId($propertyId);

        try {
            while ($processed < $limit) {
                try {
                    $result = $consumer->consumeNextAvailable($propertyId, $lease);
                    if ($result === null) {
                        break;
                    }

                    $processed++;
                    if ($result->handoffDeliveryStatus === 'DELIVERED') {
                        $delivered++;
                    }
                    if ($result->replayed) {
                        $replayed++;
                    }

                    $this->line(json_encode($result->toSafeArray(), JSON_UNESCAPED_SLASHES));
                } catch (DomainException $exception) {
                    $processed++;
                    $failed++;
                    $this->line(json_encode([
                        'property_id' => $propertyId,
                        'outcome' => 'failed',
                        'safe_marker' => $this->safeMarker($exception),
                    ], JSON_UNESCAPED_SLASHES));
                }
            }
        } finally {
            $currentProperty->clear();
        }

        $this->info(json_encode([
            'property_id' => $propertyId,
            'processed' => $processed,
            'delivered' => $delivered,
            'replayed' => $replayed,
            'failed' => $failed,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function safeMarker(DomainException $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/^[A-Z0-9_]{1,100}$/', $message) === 1
            ? $message
            : HousekeepingCheckoutTurnoverIntakeService::ERROR_INTERNAL_RETRYABLE_FAILURE;
    }
}
