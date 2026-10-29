<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('status', ['DRAFT', 'APPROVED', 'REJECTED', 'POSTED'])->default('DRAFT');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['IN', 'OUT']);
            $table->decimal('quantity', 18, 4);
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignUuid('stock_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
