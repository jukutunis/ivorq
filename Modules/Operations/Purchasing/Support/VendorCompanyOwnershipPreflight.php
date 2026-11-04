<?php

namespace Modules\Operations\Purchasing\Support;

class VendorCompanyOwnershipPreflight
{
    /** @return array<string, string> Vendor ID keyed target Company IDs. */
    public function resolve(iterable $vendors): array
    {
        $targetByVendor = [];
        $vendorByCompanyCode = [];

        foreach ($vendors as $vendor) {
            if ($vendor->property_id !== null && $vendor->property_company_id === null) {
                throw new \RuntimeException("Vendor {$vendor->id} references a missing property {$vendor->property_id}.");
            }

            if ($vendor->company_id === null && $vendor->property_id === null) {
                throw new \RuntimeException("Vendor {$vendor->id} has neither company nor property ownership.");
            }

            if ($vendor->company_id !== null
                && $vendor->property_company_id !== null
                && $vendor->company_id !== $vendor->property_company_id) {
                throw new \RuntimeException(
                    "Vendor {$vendor->id} company {$vendor->company_id} conflicts with property {$vendor->property_id} company {$vendor->property_company_id}."
                );
            }

            $targetCompanyId = $vendor->company_id ?? $vendor->property_company_id;
            $collisionKey = $targetCompanyId."\0".$vendor->vendor_code;

            if (isset($vendorByCompanyCode[$collisionKey])) {
                throw new \RuntimeException(
                    "Vendor company/code collision for company {$targetCompanyId} and code {$vendor->vendor_code}: {$vendorByCompanyCode[$collisionKey]}, {$vendor->id}."
                );
            }

            $vendorByCompanyCode[$collisionKey] = $vendor->id;
            $targetByVendor[$vendor->id] = $targetCompanyId;
        }

        return $targetByVendor;
    }
}
