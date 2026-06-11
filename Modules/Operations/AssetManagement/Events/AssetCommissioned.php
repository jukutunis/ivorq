<?php

namespace Modules\Operations\AssetManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\AssetManagement\Models\AssetCommissioning;

class AssetCommissioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AssetCommissioning $commissioning
    ) {}
}
