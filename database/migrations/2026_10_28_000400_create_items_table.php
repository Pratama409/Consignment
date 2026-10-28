<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 50)->unique();
            $table->string('part_number', 100)->index();
            $table->string('item_name', 200);
            $table->enum('ownership_type', ['STOCK', 'CONSIGNMENT']);
            $table->enum('tracking_type', ['QUANTITY', 'SERIALIZED'])->default('QUANTITY');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['ownership_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
