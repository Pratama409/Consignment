<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('status', ['DRAFT', 'APPROVED', 'REJECTED', 'POSTED'])->default('DRAFT');
            // Exactly one of item_id (internal STOCK) or vendor_item_id (Vendor consignment).
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
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
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER stock_adjustments_owner_'.strtolower($event).' BEFORE '.$event." ON stock_adjustments WHEN (NEW.item_id IS NULL) = (NEW.vendor_item_id IS NULL) BEGIN SELECT RAISE(ABORT, 'Adjustment needs exactly one of item or vendor item'); END");
            }
        } else {
            DB::statement('ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustment_owner CHECK ((item_id IS NULL) <> (vendor_item_id IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
