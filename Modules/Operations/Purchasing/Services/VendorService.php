<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorContact;
use Modules\Operations\Purchasing\Repositories\VendorRepository;
use Modules\Operations\Purchasing\Repositories\VendorContactRepository;

class VendorService
{
    public function __construct(
        protected VendorRepository $vendorRepository,
        protected VendorContactRepository $vendorContactRepository
    ) {}

    public function createVendorWithContacts(array $vendorData, array $contactsData = []): Vendor
    {
        return DB::transaction(function () use ($vendorData, $contactsData) {
            $vendor = $this->vendorRepository->create($vendorData);

            if (!empty($contactsData)) {
                foreach ($contactsData as $index => $contactData) {
                    $contactData['vendor_id'] = $vendor->id;
                    // First contact is primary if not specified
                    if (!isset($contactData['is_primary'])) {
                        $contactData['is_primary'] = ($index === 0);
                    }
                    $this->vendorContactRepository->create($contactData);
                }
            }

            return $vendor->fresh(['category', 'contacts']);
        });
    }

    public function updateVendorWithContacts(string $vendorId, array $vendorData, ?array $contactsData = null): Vendor
    {
        return DB::transaction(function () use ($vendorId, $vendorData, $contactsData) {
            $vendor = $this->vendorRepository->update($vendorId, $vendorData);

            if ($contactsData !== null) {
                // To keep it simple, we delete old and recreate, or we sync.
                // For this foundation, we sync by updating existing (if id provided) or creating new.
                // Contacts not in the payload might be deleted depending on requirements, but let's just do upsert.
                $existingContactIds = collect($contactsData)->pluck('id')->filter()->toArray();
                
                // Remove contacts not in payload
                VendorContact::where('vendor_id', $vendorId)
                    ->whereNotIn('id', $existingContactIds)
                    ->delete();

                foreach ($contactsData as $contactData) {
                    $contactData['vendor_id'] = $vendorId;
                    if (!empty($contactData['id'])) {
                        $this->vendorContactRepository->update($contactData['id'], $contactData);
                    } else {
                        $this->vendorContactRepository->create($contactData);
                    }
                }
            }

            return $vendor->fresh(['category', 'contacts']);
        });
    }

    public function toggleApproval(string $vendorId): Vendor
    {
        $vendor = $this->vendorRepository->findOrFail($vendorId);
        
        return $this->vendorRepository->update($vendorId, [
            'is_approved' => !$vendor->is_approved
        ]);
    }
}
