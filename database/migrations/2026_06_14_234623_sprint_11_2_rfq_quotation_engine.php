<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('rfq_vendors');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('rfqs');

        Schema::create('request_for_quotations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('purchase_request_id', 26)->nullable();
            $table->string('rfq_number', 50);
            $table->string('title', 255);
            $table->timestamp('deadline_at')->nullable();
            $table->string('status', 30)->default('DRAFT');
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->nullOnDelete();
            
            $table->unique(['property_id', 'rfq_number']);
        });

        Schema::create('request_for_quotation_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('request_for_quotation_id', 26);
            $table->char('purchase_request_line_id', 26)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 2);
            $table->char('unit_id', 26)->nullable(); // mapped from PR line
            $table->timestamps();

            $table->foreign('request_for_quotation_id', 'fk_rfq_lines_rfq')->references('id')->on('request_for_quotations')->cascadeOnDelete();
            $table->foreign('purchase_request_line_id', 'fk_rfq_lines_prl')->references('id')->on('purchase_request_lines')->nullOnDelete();
        });

        Schema::create('request_for_quotation_vendors', function (Blueprint $table) {
            $table->char('request_for_quotation_id', 26);
            $table->char('vendor_id', 26);
            $table->timestamps();

            $table->foreign('request_for_quotation_id', 'fk_rfqv_rfq')->references('id')->on('request_for_quotations')->cascadeOnDelete();
            $table->foreign('vendor_id', 'fk_rfqv_vendor')->references('id')->on('vendors')->restrictOnDelete();
            $table->unique(['request_for_quotation_id', 'vendor_id'], 'uk_rfqv_unique');
        });

        Schema::create('vendor_quotations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('request_for_quotation_id', 26);
            $table->char('vendor_id', 26);
            $table->string('quotation_number', 100)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status', 30)->default('DRAFT');
            $table->boolean('is_winner')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('request_for_quotation_id', 'fk_vq_rfq')->references('id')->on('request_for_quotations')->restrictOnDelete();
            $table->foreign('vendor_id', 'fk_vq_vendor')->references('id')->on('vendors')->restrictOnDelete();
            
            // Cannot have multiple active quotes from the same vendor for the same RFQ unless permitted, we enforce 1 for now.
            $table->unique(['request_for_quotation_id', 'vendor_id'], 'uk_vq_vendor_rfq');
        });

        Schema::create('vendor_quotation_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('vendor_quotation_id', 26);
            $table->char('request_for_quotation_line_id', 26);
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('vendor_quotation_id', 'fk_vql_vq')->references('id')->on('vendor_quotations')->cascadeOnDelete();
            $table->foreign('request_for_quotation_line_id', 'fk_vql_rfql')->references('id')->on('request_for_quotation_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_quotation_lines');
        Schema::dropIfExists('vendor_quotations');
        Schema::dropIfExists('request_for_quotation_vendors');
        Schema::dropIfExists('request_for_quotation_lines');
        Schema::dropIfExists('request_for_quotations');
    }
};
