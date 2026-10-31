<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_no', 60)->unique();
            $table->string('vendor_do_number', 100)->nullable();
            $table->text('reason');
            $table->string('receiver_name', 150);
            $table->timestamp('received_at');
            $table->foreignUuid('received_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['DRAFT', 'POSTED'])->default('DRAFT')->index();
            $table->foreignUuid('stock_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();
        });
        Schema::create('manual_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('manual_receipt_id');
            $table->foreign('manual_receipt_id', 'manual_receipt_line_receipt_fk')->references('id')->on('manual_receipts')->cascadeOnDelete();
            // Exactly one of item_id (internal STOCK) or vendor_item_id (Vendor consignment); the item of a Vendor pair is read through it.
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->decimal('received_quantity', 18, 4);
            $table->timestamps();
            $table->unique(['manual_receipt_id', 'item_id', 'location_id'], 'manual_receipt_line_item_unique');
            $table->unique(['manual_receipt_id', 'vendor_item_id', 'location_id'], 'manual_receipt_line_vendor_item_unique');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER manual_receipt_lines_owner_'.strtolower($event).' BEFORE '.$event." ON manual_receipt_lines WHEN (NEW.item_id IS NULL) = (NEW.vendor_item_id IS NULL) BEGIN SELECT RAISE(ABORT, 'Receipt line needs exactly one of item or vendor item'); END");
            }
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER manual_receipt_lines_quantity_whole_'.strtolower($event).' BEFORE '.$event." ON manual_receipt_lines WHEN NEW.received_quantity <= 0 OR NEW.received_quantity != CAST(NEW.received_quantity AS INTEGER) BEGIN SELECT RAISE(ABORT, 'received_quantity must be a positive whole number'); END");
            }
        } else {
            DB::statement('ALTER TABLE manual_receipt_lines ADD CONSTRAINT manual_receipt_line_owner CHECK ((item_id IS NULL) <> (vendor_item_id IS NULL))');
            DB::statement('ALTER TABLE manual_receipt_lines ADD CONSTRAINT manual_receipt_line_quantity_whole CHECK (received_quantity > 0 AND received_quantity = FLOOR(received_quantity))');
        }
        DB::table('number_sequences')->insertOrIgnore(['id' => (string) Str::uuid7(), 'name' => 'manual_receipt', 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('number_sequences')->where('name', 'manual_receipt')->delete();
        Schema::dropIfExists('manual_receipt_lines');
        Schema::dropIfExists('manual_receipts');
    }
};
