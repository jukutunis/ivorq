<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Inventory\Models\InventoryReceipt;

class PostReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $receipt = InventoryReceipt::find($this->route('receipt'));

        return $receipt && $this->user()->can('post', $receipt);
    }

    public function rules(): array
    {
        return [
            // Posting is a pure state transition — no body accepted.
            // All data is read from the stored receipt by ReceiptService::post().
            'status'    => ['prohibited'],
            'posted_at' => ['prohibited'],
            'posted_by' => ['prohibited'],
        ];
    }
}
