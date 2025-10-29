<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_cases')) {
            return; // nothing to fix
        }

        // Create a correctly defined table with explicit column order/types
        Schema::create('service_cases__fixed', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('case_code', 50)->unique();
            $table->bigInteger('customer_id')->nullable();
            $table->string('agent_id', 45)->nullable();
            $table->string('service_type', 100)->nullable();
            $table->date('service_date')->nullable();
            $table->timestamp('service_datetime')->nullable();
            $table->string('status', 50)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('amount', 45)->nullable();
            $table->string('payment_status', 45)->nullable();
            $table->index('case_code');
            $table->index('customer_id');
        });

        // Copy rows mapping by column names (defensive: accept whatever exists)
        $rows = DB::table('service_cases')->orderBy('id')->get();
        foreach ($rows as $row) {
            DB::table('service_cases__fixed')->insert([
                'id' => $row->id ?? null,
                'case_code' => $row->case_code ?? null,
                'customer_id' => $row->customer_id ?? null,
                'agent_id' => $row->agent_id ?? null,
                'service_type' => $row->service_type ?? null,
                'service_date' => $row->service_date ?? null,
                'service_datetime' => $row->service_datetime ?? null,
                'status' => $row->status ?? null,
                'created_at' => $row->created_at ?? null,
                'updated_at' => $row->updated_at ?? null,
                'amount' => $row->amount ?? null,
                'payment_status' => $row->payment_status ?? null,
            ]);
        }

        // Replace old table with fixed one
        Schema::drop('service_cases');
        Schema::rename('service_cases__fixed', 'service_cases');
    }

    public function down(): void
    {
        // no-op: not attempting to revert to the broken state
    }
};


