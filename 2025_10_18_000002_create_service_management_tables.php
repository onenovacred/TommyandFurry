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
        // Drop existing tables if they exist
        Schema::dropIfExists('service_cases');
        Schema::dropIfExists('service_customers');
        Schema::dropIfExists('service_types');

        // Create service_types table (matching existing structure)
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Create service_customers table (matching existing structure)
        Schema::create('service_customers', function (Blueprint $table) {
            $table->bigInteger('id')->autoIncrement();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Create service_cases table (matching existing structure)
        Schema::create('service_cases', function (Blueprint $table) {
            $table->bigInteger('id')->autoIncrement();
            $table->string('case_code', 50)->unique();
            $table->bigInteger('customer_id')->nullable();
            $table->string('agent_id', 45)->nullable();
            $table->string('service_type', 100)->nullable();
            $table->date('service_date')->nullable();
            $table->string('status', 50)->nulable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->string('amount', 45)->nullable();
            $table->string('payment_status', 45)->nullable();
            
            // Foreign key constraint
            $table->foreign('customer_id')->references('id')->on('service_customers');
            
            // Indexes
            $table->index('case_code');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_cases');
        Schema::dropIfExists('service_customers');
        Schema::dropIfExists('service_types');
    }
};
