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
        Schema::create('vendor_refills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('refill_no', 60)->unique();
            $table->foreignUuid('vendor_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['DRAFT', 'REQUESTED', 'REVISION_PENDING', 'APPROVED', 'SHIPPING', 'PARTIAL', 'COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'])->default('DRAFT')->index();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'status']);
        });
        Schema::create('vendor_refill_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_refill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity', 18, 4);
            $table->timestamps();
            $table->unique(['vendor_refill_id', 'item_id']);
        });
        Schema::create('vendor_refill_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_refill_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('proposed_quantity', 18, 4);
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->foreignUuid('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['vendor_refill_line_id', 'status']);
        });
        Schema::create('vendor_refill_shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_refill_id')->constrained()->restrictOnDelete();
            $table->string('vendor_document_no', 100);
            $table->string('expedition_name', 150)->nullable();
            $table->timestamp('shipped_at');
            $table->date('expected_arrival_date')->nullable();
            $table->enum('status', ['DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED'])->default('DISPATCHED');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['vendor_refill_id', 'vendor_document_no']);
        });
        Schema::create('vendor_refill_shipment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_refill_shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vendor_refill_line_id')->constrained()->restrictOnDelete();
            $table->decimal('shipped_quantity', 18, 4);
            $table->timestamps();
            $table->unique(['vendor_refill_shipment_id', 'vendor_refill_line_id']);
        });
        Schema::create('vendor_refill_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_no', 60)->unique();
            $table->foreignUuid('vendor_refill_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_refill_shipment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('vendor_do_number', 100);
            $table->timestamp('received_at');
            $table->foreignUuid('received_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['DRAFT', 'POSTED'])->default('DRAFT');
            $table->foreignUuid('stock_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['vendor_refill_id', 'vendor_do_number']);
        });
        Schema::create('vendor_refill_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_refill_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vendor_refill_line_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->decimal('received_quantity', 18, 4);
            $table->timestamps();
            $table->unique(['vendor_refill_receipt_id', 'vendor_refill_line_id', 'location_id'], 'refill_receipt_line_bucket_unique');
        });

        foreach ([
            'vendor_refill_lines' => 'requested_quantity',
            'vendor_refill_revisions' => 'proposed_quantity',
            'vendor_refill_shipment_lines' => 'shipped_quantity',
            'vendor_refill_receipt_lines' => 'received_quantity',
        ] as $table => $column) {
            $this->positive($table, $column);
        }
        foreach (['vendor_refill', 'refill_receipt'] as $name) {
            DB::table('number_sequences')->insert(['id' => (string) Str::uuid7(), 'name' => $name, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function positive(string $table, string $column): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'NEW', 'update' => 'NEW'] as $event => $row) {
                DB::unprepared("CREATE TRIGGER {$table}_{$column}_positive_{$event} BEFORE ".strtoupper($event)." ON {$table} WHEN {$row}.{$column} <= 0 BEGIN SELECT RAISE(ABORT, '{$column} must be positive'); END");
            }
        } else {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_positive CHECK ({$column} > 0)");
        }
    }

    public function down(): void
    {
        DB::table('number_sequences')->whereIn('name', ['vendor_refill', 'refill_receipt'])->delete();
        foreach (['vendor_refill_receipt_lines', 'vendor_refill_receipts', 'vendor_refill_shipment_lines', 'vendor_refill_shipments', 'vendor_refill_revisions', 'vendor_refill_lines', 'vendor_refills'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
