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
        Schema::table('activities', function (Blueprint $table) {
            $table->string('booking_url')->nullable();
            $table->string('activity_price')->nullable();
            $table->decimal('activity_rating', 3, 2)->nullable();
            $table->string('activity_image_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['booking_url', 'activity_price', 'activity_rating', 'activity_image_url']);
        });
    }
};
