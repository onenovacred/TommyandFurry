<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_cases') && !Schema::hasColumn('service_cases', 'package')) {
            Schema::table('service_cases', function (Blueprint $table) {
                $table->string('package', 20)->nullable()->after('service_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_cases') && Schema::hasColumn('service_cases', 'package')) {
            Schema::table('service_cases', function (Blueprint $table) {
                $table->dropColumn('package');
            });
        }
    }
};


