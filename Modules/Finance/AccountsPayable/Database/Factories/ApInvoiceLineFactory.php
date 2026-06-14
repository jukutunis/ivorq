<?php

namespace Modules\Finance\AccountsPayable\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\AccountsPayable\Models\ApInvoiceLine;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;

class ApInvoiceLineFactory extends Factory
{
    protected $model = ApInvoiceLine::class;

    public function definition(): array
    {
        return [
            'invoice_id' => ApInvoice::factory(),
            'receipt_line_id' => null, // Optional
            'description' => $this->faker->sentence(),
            'quantity' => 10,
            'unit_price' => 10,
            'subtotal_amount' => 100.00,
            'tax_amount' => 10.00,
            'total_amount' => 110.00,
        ];
    }
}
