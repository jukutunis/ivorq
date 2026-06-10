<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Purchasing\Models\VendorContact;
use Shared\Exceptions\NotFoundException;

class VendorContactRepository
{
    public function getByVendorId(string $vendorId): Collection
    {
        return VendorContact::where('vendor_id', $vendorId)->latest()->get();
    }

    public function find(string $id): VendorContact
    {
        $contact = VendorContact::find($id);

        throw_if(! $contact, new NotFoundException('VendorContact'));

        return $contact;
    }

    public function findOrFail(string $id): VendorContact
    {
        return VendorContact::findOrFail($id);
    }

    public function create(array $data): VendorContact
    {
        if (!empty($data['is_primary'])) {
            $this->resetPrimaryContact($data['vendor_id']);
        }
        
        return VendorContact::create($data)->fresh();
    }

    public function update(string $id, array $data): VendorContact
    {
        $contact = $this->find($id);
        
        if (!empty($data['is_primary']) && !$contact->is_primary) {
            $this->resetPrimaryContact($contact->vendor_id);
        }

        $contact->update($data);

        return $contact->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    private function resetPrimaryContact(string $vendorId): void
    {
        VendorContact::where('vendor_id', $vendorId)->update(['is_primary' => false]);
    }
}
