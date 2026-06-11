<?php

namespace Modules\Operations\AssetManagement\Services;

use Modules\Operations\AssetManagement\Models\Asset;

class AssetQRCodeService
{
    public function generateUri(Asset $asset): string
    {
        return "ivorq://asset/{$asset->id}";
    }

    public function generateAssetNumber(string $propertyCode, string $departmentCode, int $sequence): string
    {
        $seq = str_pad($sequence, 6, '0', STR_PAD_LEFT);
        return "AST-{$propertyCode}-{$departmentCode}-{$seq}";
    }
}
