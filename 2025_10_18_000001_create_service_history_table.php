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
        Schema::create('service_history', function (Blueprint $table) {
            $table->id();
            $table->string('service_id')->unique(); // Unique service identifier
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('service_type')->default('Car Insurance'); // Type of service
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->string('payment_id')->nullable(); // Razorpay payment ID
            $table->string('order_id')->nullable(); // Razorpay order ID
            $table->string('payment_method')->nullable(); // Card, UPI, Net Banking, etc.
            $table->text('service_details')->nullable(); // JSON string for additional service details
            $table->timestamp('completed_at')->nullable(); // When payment was completed
            $table->timestamp('cancelled_at')->nullable(); // When service was cancelled
            $table->text('cancellation_reason')->nullable(); // Reason for cancellation
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['customer_email', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_history');
    }
};
