<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_cases', 'status')) {
            Schema::table('service_cases', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('service_cases', 'status')) {
            Schema::table('service_cases', function (Blueprint $table) {
                $table->string('status', 50)->nullable();
            });
        }
    }
};


