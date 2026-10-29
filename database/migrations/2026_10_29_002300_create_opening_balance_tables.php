<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_period_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('status', ['PREVIEW', 'ACTIVATED'])->default('PREVIEW');
            $table->string('source', 255);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('activated_at')->nullable();
            $table->foreignUuid('stock_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('opening_balance_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('opening_balance_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('bucket_key', 100);
            $table->decimal('quantity', 18, 4);
            $table->json('source_data');
            $table->timestamps();
            $table->unique(['opening_balance_batch_id', 'bucket_key'], 'opening_batch_bucket_unique');
            $table->unique(['opening_balance_batch_id', 'row_number'], 'opening_batch_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_lines');
        Schema::dropIfExists('opening_balance_batches');
    }
};
