<?php

namespace Modules\Operations\Purchasing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PurchasingDocumentNumberAllocator
{
    public function allocate(string $propertyId, string $documentType, int $year): string
    {
        $documentType = strtoupper($documentType);

        if (! in_array($documentType, ['PR', 'PO'], true)) {
            throw new RuntimeException('Unsupported purchasing document number type.');
        }

        DB::table('purchasing_document_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'document_type' => $documentType,
            'sequence_year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('purchasing_document_sequences')
            ->where('property_id', $propertyId)
            ->where('document_type', $documentType)
            ->where('sequence_year', $year)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new RuntimeException('Purchasing document sequence could not be allocated.');
        }

        $next = ((int) $sequence->last_number) + 1;

        DB::table('purchasing_document_sequences')->where('id', $sequence->id)->update([
            'last_number' => $next,
            'updated_at' => now(),
        ]);

        return sprintf('%s-%d-%06d', $documentType, $year, $next);
    }
}
