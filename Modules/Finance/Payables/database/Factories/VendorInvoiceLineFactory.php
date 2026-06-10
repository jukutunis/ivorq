<?php

namespace Modules\Finance\Payables\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Payables\Models\VendorInvoiceLine;
use Modules\Finance\Payables\Models\VendorInvoice;

class VendorInvoiceLineFactory extends Factory
{
    protected $model = VendorInvoiceLine::class;

    public function definition(): array
    {
        return [
            'vendor_invoice_id' => VendorInvoice::factory(),
            'description' => $this->faker->words(3, true),
            'quantity' => 10,
            'unit_price' => 100,
            'line_total' => 1000,
        ];
    }
}
