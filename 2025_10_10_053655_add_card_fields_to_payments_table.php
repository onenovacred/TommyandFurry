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
            $table->bigInteger('amount')->nullable()->after('razorpay_signature');
            $table->string('currency')->default('INR')->after('amount');
            $table->string('status')->default('pending')->after('currency');
            $table->string('method')->nullable()->after('status');
            $table->string('card_last4')->nullable()->after('method');
            $table->string('card_network')->nullable()->after('card_last4');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['amount', 'currency', 'status', 'method', 'card_last4', 'card_network']);
        });
    }
};
