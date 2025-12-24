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
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('city');
            $table->string('city_ascii')->index();
            $table->decimal('lat', 10, 8);
            $table->decimal('lng', 11, 8);
            $table->string('country');
            $table->string('iso2');
            $table->string('admin_name')->nullable();
            $table->bigInteger('population')->nullable();
            $table->timestamps();
            
            // Index for search performance
            $table->index(['city_ascii', 'country']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
