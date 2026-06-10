<?php

namespace Modules\Finance\Payables\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Payables\Models\VendorInvoice;

class VendorInvoiceRepository
{
    public function query(): Builder
    {
        return VendorInvoice::query();
    }

    public function find(string $id): ?VendorInvoice
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): VendorInvoice
    {
        return $this->query()->findOrFail($id);
    }

    public function existsByNumber(string $vendorId, string $invoiceNumber, string $propertyId): bool
    {
        return $this->query()
            ->where('vendor_id', $vendorId)
            ->where('invoice_number', $invoiceNumber)
            ->where('property_id', $propertyId)
            ->exists();
    }
}
