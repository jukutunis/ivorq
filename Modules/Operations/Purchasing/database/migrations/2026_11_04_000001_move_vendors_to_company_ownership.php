<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Operations\Purchasing\Support\VendorCompanyOwnershipPreflight;

return new class extends Migration
{
    public function up(): void
    {
        $vendors = DB::table('vendors')
            ->leftJoin('properties', 'properties.id', '=', 'vendors.property_id')
            ->select([
                'vendors.id',
                'vendors.vendor_code',
                'vendors.property_id',
                'vendors.company_id',
                'properties.company_id as property_company_id',
            ])
            ->orderBy('vendors.id')
            ->get();

        $targetCompanies = (new VendorCompanyOwnershipPreflight)->resolve($vendors);

        foreach ($vendors as $vendor) {
            if ($vendor->company_id === null) {
                DB::table('vendors')->where('id', $vendor->id)->update([
                    'company_id' => $targetCompanies[$vendor->id],
                ]);
            }
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique('vendors_property_id_vendor_code_unique');
            $table->dropIndex('vendors_property_id_vendor_category_id_index');
            $table->dropIndex('vendors_property_id_is_active_index');
            $table->dropIndex('vendors_property_id_name_index');
            $table->dropForeign(['property_id']);
            $table->dropColumn('property_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->char('company_id', 26)->nullable(false)->change();
            $table->unique(['company_id', 'vendor_code'], 'vendors_company_vendor_code_unique');
            $table->index(['company_id', 'vendor_category_id'], 'vendors_company_vendor_category_index');
            $table->index(['company_id', 'is_active'], 'vendors_company_is_active_index');
            $table->index(['company_id', 'name'], 'vendors_company_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->char('property_id', 26)->nullable()->after('id');
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->unique(['property_id', 'vendor_code']);
            $table->index(['property_id', 'vendor_category_id']);
            $table->index(['property_id', 'is_active']);
            $table->index(['property_id', 'name']);

            $table->dropUnique('vendors_company_vendor_code_unique');
            $table->dropIndex('vendors_company_vendor_category_index');
            $table->dropIndex('vendors_company_is_active_index');
            $table->dropIndex('vendors_company_name_index');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->char('company_id', 26)->nullable()->change();
        });
    }
};
