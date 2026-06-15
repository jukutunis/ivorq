<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            // Account is COMPANY LEVEL (Rule 1)
            $table->string('company_id', 26);
            
            $table->string('account_name');
            $table->string('account_type'); // Corporate, Travel Agent, etc.
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['company_id']);
        });

        Schema::create('account_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('account_id', 26);
            
            $table->string('first_name');
            $table->string('last_name');
            $table->string('contact_role');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            // Opportunity is PROPERTY LEVEL (Rule 2)
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('account_id', 26);
            
            $table->string('opportunity_name');
            $table->string('status');
            
            $table->decimal('estimated_revenue', 15, 4)->nullable();
            $table->date('expected_event_date')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['property_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
        });

        Schema::create('lost_businesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('opportunity_id', 26);
            
            $table->string('lost_reason');
            $table->string('lost_competitor');
            $table->date('lost_date');
            
            $table->decimal('lost_price', 15, 4)->nullable();
            $table->string('lost_venue')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('opportunity_id')->references('id')->on('opportunities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_businesses');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('account_contacts');
        Schema::dropIfExists('accounts');
    }
};
