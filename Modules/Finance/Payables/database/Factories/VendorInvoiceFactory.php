<?php

namespace Modules\Finance\Payables\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Finance\Payables\Enums\VendorInvoiceStatusEnum;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Foundation\Property\Models\Property;

class VendorInvoiceFactory extends Factory
{
    protected $model = VendorInvoice::class;

    public function definition(): array
    {
        return [
            'property_id' => Property::first()->id ?? null,
            'vendor_id' => Vendor::first()->id ?? null,
            'invoice_number' => 'INV-' . $this->faker->unique()->numberBetween(1000, 9999),
            'invoice_date' => $this->faker->date(),
            'due_date' => $this->faker->date(),
            'status' => VendorInvoiceStatusEnum::Draft,
            'subtotal' => 1000,
            'tax_amount' => 100,
            'discount_amount' => 0,
            'grand_total' => 1100,
            'remarks' => $this->faker->sentence(),
        ];
    }
}
