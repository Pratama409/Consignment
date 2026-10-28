<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_item_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['item_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_item_locations');
    }
};
