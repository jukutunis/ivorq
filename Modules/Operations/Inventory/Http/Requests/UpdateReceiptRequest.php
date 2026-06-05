<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Shared\Services\CurrentPropertyService;

class UpdateReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receipt = InventoryReceipt::find($this->route('receipt'));

        return $receipt && $this->user()->can('update', $receipt);
    }

    public function rules(): array
    {
        $receiptId  = $this->route('receipt');
        $propertyId = app(CurrentPropertyService::class)->getId();

        return [
            'receipt_number' => ['sometimes', 'string', 'max:30',
                "unique:inventory_receipts,receipt_number,{$receiptId},id,property_id,{$propertyId},deleted_at,NULL",
            ],
            'supplier_name'  => ['nullable', 'string', 'max:255'],
            'reference'      => ['nullable', 'string', 'max:100'],
            'received_at'    => ['nullable', 'date'],
            'notes'          => ['nullable', 'string'],

            // Lines — optional on update; each line fully validated when present
            'lines'               => ['sometimes', 'array', 'min:1'],
            'lines.*.item_id'     => ['sometimes', 'string', 'size:26',
                Rule::exists('inventory_items', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.location_id' => ['sometimes', 'string', 'size:26',
                Rule::exists('inventory_locations', 'id')
                    ->where('property_id', $propertyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity'    => ['sometimes', 'numeric', 'min:0.001'],
            'lines.*.unit_cost'   => ['sometimes', 'numeric', 'min:0'],
            'lines.*.total_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes'       => ['nullable', 'string', 'max:500'],

            // Server-controlled lifecycle fields
            'status'       => ['prohibited'],
            'posted_at'    => ['prohibited'],
            'posted_by'    => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
        ];
    }
}
