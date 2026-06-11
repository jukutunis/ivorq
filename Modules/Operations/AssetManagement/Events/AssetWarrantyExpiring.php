<?php

namespace Modules\Operations\AssetManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\AssetManagement\Models\AssetWarranty;

class AssetWarrantyExpiring
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AssetWarranty $warranty,
        public readonly int $daysRemaining
    ) {}
}
