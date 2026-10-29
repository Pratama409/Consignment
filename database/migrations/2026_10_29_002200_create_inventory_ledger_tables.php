<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Inventory ledger supports MySQL and SQLite only.');
        }

        Schema::create('inventory_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->dateTime('cutoff_at')->unique();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'CLOSED'])->default('DRAFT');
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_period_id')->constrained()->restrictOnDelete();
            $table->string('transaction_no', 100)->unique();
            $table->enum('type', ['OPENING', 'REFILL_RECEIPT', 'MANUAL_RECEIPT', 'MATERIAL_ISSUE', 'ADJUSTMENT', 'REVERSAL']);
            $table->enum('status', ['DRAFT', 'POSTED'])->default('DRAFT');
            $table->string('source_type', 100);
            $table->uuid('source_id');
            $table->dateTime('physical_occurred_at');
            $table->dateTime('posted_at')->nullable()->index();
            $table->foreignUuid('operational_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->text('reason')->nullable();
            $table->foreignUuid('reversal_of_id')->nullable()->unique()->constrained('stock_transactions')->restrictOnDelete();
            $table->boolean('is_late_posting')->default(false);
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('stock_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transaction_id')->constrained()->restrictOnDelete();
            $this->bucketColumns($table);
            $table->foreignUuid('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->unsignedInteger('entry_sequence');
            $table->dateTime('physical_occurred_at');
            $table->timestamps();
            $table->unique(['stock_transaction_id', 'entry_sequence']);
            $table->index(['bucket_key', 'created_at']);
        });

        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $this->bucketColumns($table);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
            $table->unique('bucket_key');
        });

        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_ledger_entry_id')->unique()->constrained()->restrictOnDelete();
            $this->bucketColumns($table);
            $table->dateTime('received_at');
            $table->decimal('original_quantity', 18, 4);
            $table->decimal('remaining_quantity', 18, 4);
            $table->timestamps();
            $table->index(['bucket_key', 'received_at', 'id']);
        });

        Schema::create('stock_lot_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_ledger_entry_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_lot_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
            $table->unique(['stock_ledger_entry_id', 'stock_lot_id'], 'stock_allocation_entry_lot_unique');
        });

        $this->integrityGuards();
    }

    private function bucketColumns(Blueprint $table): void
    {
        $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
        $table->foreignUuid('vendor_item_id')->nullable()->constrained()->restrictOnDelete();
        $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
        $table->string('bucket_key', 100);
    }

    private function integrityGuards(): void
    {
        $stockKey = DB::getDriverName() === 'mysql' ? "CONCAT('STOCK:', NEW.item_id, ':', NEW.location_id)" : "'STOCK:' || NEW.item_id || ':' || NEW.location_id";
        $consignmentKey = DB::getDriverName() === 'mysql' ? "CONCAT('CONSIGNMENT:', NEW.vendor_item_id, ':', NEW.location_id)" : "'CONSIGNMENT:' || NEW.vendor_item_id || ':' || NEW.location_id";
        // MySQL's default collation ignores case; normalized bucket keys must be exact on both engines.
        $equals = fn (string $key): string => DB::getDriverName() === 'mysql' ? "CAST(NEW.bucket_key AS BINARY) = CAST({$key} AS BINARY)" : "NEW.bucket_key = {$key}";
        $stockMatches = $equals($stockKey);
        $consignmentMatches = $equals($consignmentKey);
        $validBucket = "((NEW.vendor_item_id IS NULL AND {$stockMatches} AND EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = 'STOCK'))
            OR (NEW.vendor_item_id IS NOT NULL AND {$consignmentMatches} AND EXISTS (SELECT 1 FROM vendor_items vi JOIN items i ON i.id = vi.item_id WHERE vi.id = NEW.vendor_item_id AND vi.item_id = NEW.item_id AND i.ownership_type = 'CONSIGNMENT')))";

        foreach (['INSERT', 'UPDATE'] as $event) {
            $suffix = strtolower($event);
            // Keep every inventory quantity within the same ten-integer/four-decimal range as its DTO.
            // SQLite NUMERIC has no fixed scale; reject excess precision instead of silently losing it.
            foreach (['stock_ledger_entries' => ['quantity'], 'stock_balances' => ['quantity'], 'stock_lots' => ['original_quantity', 'remaining_quantity'], 'stock_lot_allocations' => ['quantity']] as $table => $columns) {
                $invalid = implode(' OR ', array_map(fn ($column) => "ABS(NEW.{$column}) >= 10000000000 OR NEW.{$column} <> ROUND(NEW.{$column}, 4)", $columns));
                $this->guard("{$table}_precision_{$suffix}", $table, $event, $invalid, 'Quantity exceeds supported four-decimal range');
            }
            foreach (['stock_ledger_entries', 'stock_balances', 'stock_lots'] as $table) {
                $this->guard("{$table}_bucket_{$suffix}", $table, $event, "NOT {$validBucket}", 'Invalid inventory bucket');
            }
            $this->guard("stock_ledger_vendor_{$suffix}", 'stock_ledger_entries', $event,
                '(NEW.vendor_item_id IS NULL AND NEW.vendor_id IS NOT NULL) OR (NEW.vendor_item_id IS NOT NULL AND (NEW.vendor_id IS NULL OR NOT EXISTS (SELECT 1 FROM vendor_items WHERE id = NEW.vendor_item_id AND vendor_id = NEW.vendor_id)))', 'Invalid ledger Vendor snapshot');
            $this->guard("stock_ledger_quantity_{$suffix}", 'stock_ledger_entries', $event, 'NEW.quantity = 0 OR NEW.entry_sequence < 1', 'Ledger quantity must be nonzero and sequence positive');
            $this->guard("stock_balances_quantity_{$suffix}", 'stock_balances', $event, 'NEW.quantity < 0', 'Stock balance cannot be negative');
            $this->guard("stock_lots_quantity_{$suffix}", 'stock_lots', $event, 'NEW.original_quantity <= 0 OR NEW.remaining_quantity < 0 OR NEW.remaining_quantity > NEW.original_quantity', 'Invalid lot quantities');
            $this->guard("stock_allocations_quantity_{$suffix}", 'stock_lot_allocations', $event, 'NEW.quantity <= 0', 'Allocation quantity must be positive');
            $this->guard("stock_transactions_posted_{$suffix}", 'stock_transactions', $event,
                "NEW.status = 'POSTED' AND (NEW.posted_at IS NULL OR NEW.posted_by IS NULL OR NEW.operational_shift_id IS NULL)", 'Posted transactions require posting metadata');
        }

        foreach (['UPDATE', 'DELETE'] as $event) {
            $suffix = strtolower($event);
            foreach (['stock_ledger_entries', 'stock_lot_allocations'] as $table) {
                $this->guard("{$table}_immutable_{$suffix}", $table, $event, '1 = 1', 'Inventory history is immutable');
            }
            $this->guard("stock_transactions_immutable_{$suffix}", 'stock_transactions', $event, "OLD.status = 'POSTED'", 'Posted transactions are immutable');
        }

        $same = fn (string $column): string => DB::getDriverName() === 'mysql' ? "NEW.{$column} <=> OLD.{$column}" : "NEW.{$column} IS OLD.{$column}";
        $identity = implode(' AND ', array_map($same, ['id', 'stock_ledger_entry_id', 'item_id', 'vendor_item_id', 'location_id', 'bucket_key', 'received_at', 'original_quantity']));
        $this->guard('stock_lots_identity_update', 'stock_lots', 'UPDATE', "NOT ({$identity})", 'Lot identity is immutable');
        $this->guard('stock_lots_immutable_delete', 'stock_lots', 'DELETE', '1 = 1', 'Stock lots preserve inventory history');

        if (DB::getDriverName() === 'sqlite') {
            // REPLACE skips DELETE triggers when recursive_triggers is OFF. Detect all unique-key
            // collisions before insertion so immutable history does not depend on a connection PRAGMA.
            $collisions = [
                'stock_transactions' => "status = 'POSTED' AND (id = NEW.id OR transaction_no = NEW.transaction_no OR idempotency_key = NEW.idempotency_key OR (source_type = NEW.source_type AND source_id = NEW.source_id) OR reversal_of_id = NEW.reversal_of_id)",
                'stock_ledger_entries' => 'id = NEW.id OR (stock_transaction_id = NEW.stock_transaction_id AND entry_sequence = NEW.entry_sequence)',
                'stock_lot_allocations' => 'id = NEW.id OR (stock_ledger_entry_id = NEW.stock_ledger_entry_id AND stock_lot_id = NEW.stock_lot_id)',
                'stock_lots' => 'id = NEW.id OR stock_ledger_entry_id = NEW.stock_ledger_entry_id',
            ];
            foreach ($collisions as $table => $condition) {
                $this->guard("{$table}_immutable_replace", $table, 'INSERT', "EXISTS (SELECT 1 FROM {$table} WHERE {$condition})", 'Inventory history cannot be replaced');
            }
        }
    }

    private function guard(string $name, string $table, string $event, string $condition, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }

    public function down(): void
    {
        // Triggers are owned by their tables and are removed with them on both supported engines.
        Schema::dropIfExists('stock_lot_allocations');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('inventory_periods');
    }
};
