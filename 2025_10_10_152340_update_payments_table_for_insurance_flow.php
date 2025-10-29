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
        Schema::table('payments', function (Blueprint $table) {
            // Make payment fields nullable for pre-payment state
            $table->string('razorpay_payment_id')->nullable()->change();
            $table->string('razorpay_signature')->nullable()->change();
            
            // Add new fields for insurance flow (only the ones that don't exist)
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            
            // Car details
            $table->string('car_make')->nullable();
            $table->string('car_model')->nullable();
            $table->integer('year')->nullable();
            $table->string('insurance_type')->nullable();
            $table->integer('policy_duration')->nullable();
            
            // Additional fields
            $table->string('reference_id')->nullable();
            $table->decimal('quote_amount', 10, 2)->nullable();
            $table->timestamp('payment_completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Revert nullable changes
            $table->string('razorpay_payment_id')->nullable(false)->change();
            $table->string('razorpay_signature')->nullable(false)->change();
            
            // Drop added columns
            $table->dropColumn([
                'customer_name', 'customer_email', 'customer_phone',
                'car_make', 'car_model', 'year', 'insurance_type', 'policy_duration',
                'reference_id', 'quote_amount', 'payment_completed_at'
            ]);
        });
    }
};
