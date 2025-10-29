<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('insurance_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_start_date');
            $table->string('contract_end_date');
            $table->string('product_id');
            $table->string('ncb_transfer');
            $table->integer('ncb_percentage')->nullable();
            $table->integer('contract_tenure')->nullable();
            $table->string('policy_type')->nullable();
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->decimal('premium_amount', 10, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_contracts');
    }
};