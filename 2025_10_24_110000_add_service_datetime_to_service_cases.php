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
        if (Schema::hasTable('service_cases') && !Schema::hasColumn('service_cases', 'service_datetime')) {
            Schema::table('service_cases', function (Blueprint $table) {
                $table->timestamp('service_datetime')->nullable()->after('service_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_cases') && Schema::hasColumn('service_cases', 'service_datetime')) {
            Schema::table('service_cases', function (Blueprint $table) {
                $table->dropColumn('service_datetime');
            });
        }
    }
};


