<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_pricings', function (Blueprint $table) {
            $table->id();
            $table->string('service_key'); // e.g., Training, Daily Dog Walking
            $table->string('package'); // hourly|monthly|yearly
            $table->integer('units'); // e.g., 1,2,3,4,5 / 1,3,5,7,9 / 1,2,3
            $table->integer('price_rupees'); // integer rupees
            $table->timestamps();
            $table->unique(['service_key', 'package', 'units']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_pricings');
    }
};


