<?php

namespace Modules\Operations\AssetManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\AssetManagement\Models\Asset;

class AssetRetired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Asset $asset
    ) {}
}
