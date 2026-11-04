<?php

namespace Tests\Unit\Operations\Purchasing;

use Modules\Operations\Purchasing\Support\VendorCompanyOwnershipPreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VendorCompanyOwnershipPreflightTest extends TestCase
{
    public function test_it_preserves_vendor_ids_and_resolves_property_and_hybrid_ownership(): void
    {
        $resolved = (new VendorCompanyOwnershipPreflight)->resolve([
            $this->row('vendor-property', 'V-1', 'property-a', null, 'company-a'),
            $this->row('vendor-hybrid', 'V-2', 'property-a', 'company-a', 'company-a'),
            $this->row('vendor-company', 'V-3', null, 'company-a', null),
        ]);

        $this->assertSame([
            'vendor-property' => 'company-a',
            'vendor-hybrid' => 'company-a',
            'vendor-company' => 'company-a',
        ], $resolved);
    }

    #[DataProvider('invalidOwnershipProvider')]
    public function test_it_fails_closed_for_invalid_ownership(object $row, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new VendorCompanyOwnershipPreflight)->resolve([$row]);
    }

    public static function invalidOwnershipProvider(): array
    {
        return [
            'missing property' => [(object) ['id' => 'vendor-1', 'vendor_code' => 'V-1', 'property_id' => 'missing', 'company_id' => null, 'property_company_id' => null], 'references a missing property'],
            'no owner' => [(object) ['id' => 'vendor-2', 'vendor_code' => 'V-2', 'property_id' => null, 'company_id' => null, 'property_company_id' => null], 'has neither company nor property ownership'],
            'hybrid mismatch' => [(object) ['id' => 'vendor-3', 'vendor_code' => 'V-3', 'property_id' => 'property-a', 'company_id' => 'company-b', 'property_company_id' => 'company-a'], 'conflicts with property'],
        ];
    }

    public function test_it_fails_closed_on_target_company_code_collision_without_merging(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vendor-1, vendor-2');

        (new VendorCompanyOwnershipPreflight)->resolve([
            $this->row('vendor-1', 'DUP', 'property-a', null, 'company-a'),
            $this->row('vendor-2', 'DUP', 'property-b', null, 'company-a'),
        ]);
    }

    private function row(
        string $id,
        string $code,
        ?string $propertyId,
        ?string $companyId,
        ?string $propertyCompanyId,
    ): object {
        return (object) [
            'id' => $id,
            'vendor_code' => $code,
            'property_id' => $propertyId,
            'company_id' => $companyId,
            'property_company_id' => $propertyCompanyId,
        ];
    }
}
