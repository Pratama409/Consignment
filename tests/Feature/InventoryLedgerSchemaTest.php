<?php

namespace Tests\Feature;

use App\Actions\Shifts\ResolveOperationalShift;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\InventoryPeriodStatus;
use App\Enums\StockDirection;
use App\Enums\StockTransactionStatus;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockLot;
use App\Models\StockLotAllocation;
use App\Models\StockTransaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class InventoryLedgerSchemaTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private User $actor;

    private Item $item;

    private Location $location;

    private VendorItem $vendorItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->actor = User::factory()->admin()->create();
        $this->location = Location::firstOrFail();
        $this->item = $this->makeItem('STOCK');
        $vendor = Vendor::create(['vendor_code' => 'LEDGER', 'vendor_name' => 'Supplier']);
        $this->vendorItem = VendorItem::create(['item_id' => $this->makeItem('CONSIGNMENT')->id, 'vendor_id' => $vendor->id, 'location_id' => $this->location->id]);
    }

    private function makeItem(string $ownership): Item
    {
        return Item::create(['item_code' => 'IT-'.Str::random(12), 'part_number' => 'PN-'.Str::random(12), 'item_name' => $ownership, 'ownership_type' => $ownership, 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
    }

    private function transaction(array $overrides = []): StockTransaction
    {
        $period = InventoryPeriod::firstOrCreate(['cutoff_at' => '2026-10-29 00:00:00'], ['status' => InventoryPeriodStatus::Active, 'activated_by' => $this->actor->id, 'activated_at' => '2026-10-29 00:00:00']);
        $shift = app(ResolveOperationalShift::class)->handle(CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Makassar'));

        return StockTransaction::create([...[
            'inventory_period_id' => $period->id, 'transaction_no' => 'ST-'.Str::uuid(), 'type' => StockTransactionType::ManualReceipt,
            'status' => StockTransactionStatus::Posted, 'source_type' => 'manual_receipt', 'source_id' => (string) Str::uuid(),
            'physical_occurred_at' => '2026-10-29 01:00:00', 'posted_at' => '2026-10-29 02:00:00', 'operational_shift_id' => $shift->id,
            'created_by' => $this->actor->id, 'posted_by' => $this->actor->id, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Schema fixture',
        ], ...$overrides]);
    }

    private function bucket(?VendorItem $vendorItem = null): array
    {
        return ['stock_bucket_id' => $this->bucketFor($vendorItem?->item ?? $this->item, $vendorItem, $this->location)->id];
    }

    private function entry(StockTransaction $transaction, array $overrides = []): StockLedgerEntry
    {
        return $transaction->ledgerEntries()->create([...$this->bucket(), 'entry_sequence' => 1, 'quantity' => '12.3456', 'physical_occurred_at' => $transaction->physical_occurred_at, ...$overrides]);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected database integrity protection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_inventory_tables_exist_and_all_primary_and_foreign_identifiers_are_uuid_strings(): void
    {
        foreach (['inventory_periods', 'stock_transactions', 'stock_ledger_entries', 'stock_balances', 'stock_lots', 'stock_lot_allocations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
            foreach (Schema::getColumns($table) as $column) {
                if ($column['name'] === 'id' || str_ends_with($column['name'], '_id') || in_array($column['name'], ['created_by', 'posted_by', 'activated_by'], true)) {
                    $this->assertMatchesRegularExpression('/char|uuid|text/i', $column['type']);
                }
            }
        }
        $transaction = $this->transaction();
        $entry = $this->entry($transaction);
        $balance = StockBalance::create([...$this->bucket(), 'quantity' => '12.3456']);
        $lot = $entry->lot()->create([...$this->bucket(), 'received_at' => $transaction->posted_at, 'original_quantity' => '12.3456', 'remaining_quantity' => '12.3456']);
        $out = $this->entry($this->transaction(['type' => StockTransactionType::MaterialIssue]), ['quantity' => '-0.1234']);
        $allocation = $out->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => '0.1234']);
        foreach ([$transaction->inventoryPeriod, $transaction, $entry, $balance, $lot, $allocation] as $model) {
            $this->assertTrue(Str::isUuid($model->id));
            $this->assertSame('string', $model->getKeyType());
            $this->assertFalse($model->getIncrementing());
        }
        foreach ([$transaction->inventory_period_id, $transaction->operational_shift_id, $transaction->source_id, $transaction->created_by, $transaction->posted_by, $entry->stock_transaction_id, $entry->stock_bucket_id, $lot->stock_ledger_entry_id, $allocation->stock_lot_id] as $id) {
            $this->assertTrue(Str::isUuid($id));
        }
        $this->assertSame($this->actor->id, $transaction->creator->id);
        $this->assertSame($this->actor->id, $transaction->poster->id);
        $this->assertSame($transaction->id, $entry->transaction->id);
        $this->assertSame($entry->id, $lot->ledgerEntry->id);
        $this->assertSame($out->id, $allocation->ledgerEntry->id);
        $this->assertSame($lot->id, $allocation->lot->id);
        $this->assertSame('12.3456', $entry->fresh()->quantity);
        $this->assertSame('-0.1234', $out->fresh()->quantity);
        $this->assertSame('12.3456', $balance->fresh()->quantity);
        $this->assertSame('12.3456', $lot->fresh()->original_quantity);
        $this->assertSame('12.3456', $lot->fresh()->remaining_quantity);
        $this->assertSame('0.1234', $allocation->fresh()->quantity);
        $this->assertSame(StockTransactionStatus::Posted, $transaction->fresh()->status);
        $this->assertSame(InventoryPeriodStatus::Active, $transaction->inventoryPeriod->status);
    }

    public function test_stock_and_consignment_balance_buckets_are_unique_even_with_nullable_vendor_identity(): void
    {
        foreach ([$this->bucket(), $this->bucket($this->vendorItem)] as $bucket) {
            StockBalance::create([...$bucket, 'quantity' => '1.0001']);
            $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->insert([...$bucket, 'id' => (string) Str::uuid(), 'quantity' => '2.0002']));
        }
        $this->assertSame(2, StockBalance::count());
        $otherVendor = Vendor::create(['vendor_code' => 'OTHER', 'vendor_name' => 'Other']);
        $other = VendorItem::create(['item_id' => $this->vendorItem->item_id, 'vendor_id' => $otherVendor->id, 'location_id' => $this->location->id]);
        StockBalance::create([...$this->bucket($other), 'quantity' => '3.0003']);
        $this->assertSame(3, StockBalance::count());
    }

    public function test_balances_are_unique_per_bucket_and_every_row_needs_an_existing_bucket(): void
    {
        $unknown = (string) Str::uuid();
        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->insert(['id' => (string) Str::uuid(), 'stock_bucket_id' => $unknown, 'quantity' => '1.0000', 'version' => 0]));
        $entry = $this->entry($this->transaction());
        $this->assertDatabaseRejects(fn () => DB::table('stock_ledger_entries')->insert([...$entry->getAttributes(), 'id' => (string) Str::uuid(), 'entry_sequence' => 2, 'stock_bucket_id' => $unknown]));
        $this->assertDatabaseRejects(fn () => DB::table('stock_lots')->insert(['id' => (string) Str::uuid(), 'stock_ledger_entry_id' => $entry->id, 'stock_bucket_id' => $unknown, 'received_at' => now(), 'original_quantity' => '1.0000', 'remaining_quantity' => '1.0000']));
        $this->assertSame($this->vendorItem->vendor_id, $this->entry($this->transaction(), $this->bucket($this->vendorItem))->bucket->vendorId());
    }

    public function test_transaction_idempotency_source_and_entry_sequence_are_database_unique(): void
    {
        $transaction = $this->transaction();
        foreach ([['idempotency_key' => $transaction->idempotency_key], ['source_type' => $transaction->source_type, 'source_id' => $transaction->source_id], ['transaction_no' => $transaction->transaction_no]] as $duplicate) {
            $this->assertDatabaseRejects(fn () => $this->transaction($duplicate));
        }
        $this->transaction(['source_type' => 'other_document', 'source_id' => $transaction->source_id]);
        $this->entry($transaction);
        $this->assertDatabaseRejects(fn () => $this->entry($transaction));
    }

    public function test_referenced_period_shift_actor_and_master_rows_cannot_be_deleted_through_sql(): void
    {
        $transaction = $this->transaction();
        $this->entry($transaction, $this->bucket($this->vendorItem));
        foreach (['inventory_periods' => $transaction->inventory_period_id, 'operational_shifts' => $transaction->operational_shift_id, 'users' => $this->actor->id, 'items' => $this->vendorItem->item_id, 'vendor_items' => $this->vendorItem->id, 'vendors' => $this->vendorItem->vendor_id, 'locations' => $this->location->id] as $table => $id) {
            $this->assertDatabaseRejects(fn () => DB::table($table)->where('id', $id)->delete());
        }
        $this->assertSame(1, StockLedgerEntry::count());
    }

    public function test_posted_transactions_ledger_and_allocations_are_immutable_even_through_sql(): void
    {
        $transaction = $this->transaction();
        $entry = $this->entry($transaction);
        $lot = $entry->lot()->create([...$this->bucket(), 'received_at' => $transaction->posted_at, 'original_quantity' => '12.3456', 'remaining_quantity' => '12.3456']);
        $out = $this->entry($this->transaction(), ['quantity' => '-0.1234']);
        $allocation = $out->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => '0.1234']);
        foreach ([$transaction, $entry, $allocation] as $model) {
            $changes = $model instanceof StockTransaction ? ['reason' => 'overwrite'] : ['quantity' => '4.0000'];
            $this->assertDatabaseRejects(fn () => DB::table($model->getTable())->where('id', $model->id)->update($changes));
            $this->assertDatabaseRejects(fn () => DB::table($model->getTable())->where('id', $model->id)->delete());
            $this->assertDatabaseRejects(fn () => $model->update($changes));
            $this->assertDatabaseRejects(fn () => $model->delete());
        }
        $this->assertDatabaseRejects(fn () => DB::table('stock_lots')->where('id', $lot->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('stock_lots')->where('id', $lot->id)->update(['original_quantity' => '20.0000']));
        $lot->update(['remaining_quantity' => '12.2222']);
        $this->assertSame('12.2222', $lot->fresh()->remaining_quantity);
        $draft = $this->transaction(['status' => StockTransactionStatus::Draft]);
        $draft->update(['reason' => 'Draft may change']);
        $this->assertSame('Draft may change', $draft->fresh()->reason);
    }

    public function test_projection_and_lot_quantities_cannot_be_negative_or_exceed_the_original(): void
    {
        $balance = StockBalance::create([...$this->bucket(), 'quantity' => '12.3456']);
        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->where('id', $balance->id)->update(['quantity' => '-0.0001']));
        $entry = $this->entry($this->transaction());
        $lot = $entry->lot()->create([...$this->bucket(), 'received_at' => '2026-10-29 02:00:00', 'original_quantity' => '12.3456', 'remaining_quantity' => '12.3456']);
        foreach (['-0.0001', '12.3457'] as $invalid) {
            $this->assertDatabaseRejects(fn () => DB::table('stock_lots')->where('id', $lot->id)->update(['remaining_quantity' => $invalid]));
        }
        $this->assertDatabaseRejects(fn () => DB::table('stock_lot_allocations')->insert(['id' => (string) Str::uuid(), 'stock_ledger_entry_id' => $entry->id, 'stock_lot_id' => $lot->id, 'quantity' => '-1.0000']));
    }

    public function test_posting_dtos_are_immutable_with_positive_exact_decimal_input(): void
    {
        $line = new StockPostingLineData($this->item->id, null, $this->location->id, StockDirection::Out, '0.1234');
        $data = new StockPostingData(StockTransactionType::MaterialIssue, 'material_issue', (string) Str::uuid(), CarbonImmutable::parse('2026-10-29 10:00'), $this->actor->id, 'request-1', 'Issue', [$line]);
        $this->assertSame('0.1234', $data->lines[0]->quantity);
        $this->assertTrue((new \ReflectionClass($data))->isReadOnly());
        $this->assertTrue((new \ReflectionClass($line))->isReadOnly());
        $this->assertSame('2.0000', (new StockPostingLineData($this->item->id, null, $this->location->id, StockDirection::In, '2'))->quantity);
    }

    private function replaceRow(string $table, array $row): void
    {
        $columns = implode(', ', array_map(fn ($column) => "`{$column}`", array_keys($row)));
        $parameters = implode(', ', array_fill(0, count($row), '?'));
        $verb = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
        DB::statement("{$verb} INTO `{$table}` ({$columns}) VALUES ({$parameters})", array_values($row));
    }

    public function test_replacement_cannot_rewrite_immutable_history_with_recursive_triggers_disabled(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        $transaction = $this->transaction(['type' => StockTransactionType::Reversal, 'reversal_of_id' => $this->transaction()->id]);
        $entry = $this->entry($this->transaction());
        $lot = $entry->lot()->create([...$this->bucket(), 'received_at' => $entry->transaction->posted_at, 'original_quantity' => '12.3456', 'remaining_quantity' => '12.3456']);
        $out = $this->entry($this->transaction(), ['quantity' => '-0.1234']);
        $allocation = $out->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => '0.1234']);
        $unreferenced = $this->entry($this->transaction());
        $lotEntry = $this->entry($this->transaction());
        $unallocatedLot = $lotEntry->lot()->create([...$this->bucket(), 'received_at' => $lotEntry->transaction->posted_at, 'original_quantity' => '12.3456', 'remaining_quantity' => '12.3456']);

        foreach ([$transaction, $unreferenced, $allocation, $unallocatedLot] as $model) {
            $original = $model->fresh()->getAttributes();
            $column = $model instanceof StockTransaction ? 'reason' : ($model instanceof StockLot ? 'original_quantity' : 'quantity');
            $alternative = [...$original, 'id' => (string) Str::uuid(), $column => $column === 'reason' ? 'Replacement' : '20.0000'];
            if ($model instanceof StockTransaction) {
                $alternative = [...$alternative, 'transaction_no' => 'REPLACE-'.Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'source_id' => (string) Str::uuid(), 'reversal_of_id' => null];
                $keys = [['id'], ['transaction_no'], ['idempotency_key'], ['source_type', 'source_id'], ['reversal_of_id']];
            } elseif ($model instanceof StockLedgerEntry) {
                $alternative['stock_transaction_id'] = $this->transaction()->id;
                $keys = [['id'], ['stock_transaction_id', 'entry_sequence']];
            } elseif ($model instanceof StockLotAllocation) {
                $alternative['stock_ledger_entry_id'] = $this->entry($this->transaction(), ['quantity' => '-20.0000'])->id;
                $keys = [['id'], ['stock_ledger_entry_id', 'stock_lot_id']];
            } else {
                $alternative['stock_ledger_entry_id'] = $this->entry($this->transaction(), ['quantity' => '20.0000'])->id;
                $keys = [['id'], ['stock_ledger_entry_id']];
            }
            foreach ($keys as $key) {
                // Each candidate conflicts on only one unique key, so other constraints cannot mask a gap.
                $candidate = [...$alternative, ...array_intersect_key($original, array_flip($key))];
                $this->assertDatabaseRejects(fn () => $this->replaceRow($model->getTable(), $candidate));
                $this->assertSame($original, $model->fresh()->getAttributes());
            }
        }
    }

    public function test_supported_quantity_boundaries_round_trip_four_decimals_across_every_inventory_table(): void
    {
        foreach (['0.0001', '9999999999.9998', '9999999999.9999'] as $quantity) {
            $line = new StockPostingLineData($this->item->id, null, $this->location->id, StockDirection::In, $quantity);
            $entry = $this->entry($this->transaction(), ['quantity' => $line->quantity]);
            $balance = StockBalance::updateOrCreate($this->bucket(), ['quantity' => $line->quantity]);
            $lot = $entry->lot()->create([...$this->bucket(), 'received_at' => $entry->transaction->posted_at, 'original_quantity' => $line->quantity, 'remaining_quantity' => $line->quantity]);
            $out = $this->entry($this->transaction(), ['quantity' => '-'.$line->quantity]);
            $allocation = $out->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => $line->quantity]);
            foreach ([$entry, $balance, $allocation] as $model) {
                $this->assertSame($quantity, $model->fresh()->quantity);
            }
            $this->assertSame('-'.$quantity, $out->fresh()->quantity);
            $this->assertSame($quantity, $lot->fresh()->original_quantity);
            $this->assertSame($quantity, $lot->fresh()->remaining_quantity);
        }
    }

    public function test_database_rejects_quantities_outside_the_cross_database_supported_range(): void
    {
        $entry = $this->entry($this->transaction());
        $balance = StockBalance::create([...$this->bucket(), 'quantity' => '12.3456']);
        $lot = $entry->lot()->create([...$this->bucket(), 'received_at' => $entry->transaction->posted_at, 'original_quantity' => '12.3456', 'remaining_quantity' => '12.3456']);
        $out = $this->entry($this->transaction(), ['quantity' => '-0.1234']);
        $allocation = $out->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => '0.1234']);
        foreach ([$entry, $balance, $lot, $allocation] as $model) {
            $columns = $model instanceof StockLot ? ['original_quantity', 'remaining_quantity'] : ['quantity'];
            foreach ($columns as $column) {
                $invalid = ['10000000000.0000', '12345678901234.1234', '-12345678901234.1234'];
                if (DB::getDriverName() === 'sqlite') {
                    // MySQL DECIMAL rounds input to the declared scale before its triggers run.
                    $invalid[] = '1.23456';
                }
                foreach ($invalid as $quantity) {
                    $row = [...$model->getAttributes(), 'id' => (string) Str::uuid(), $column => $quantity];
                    if ($model instanceof StockLedgerEntry) {
                        $row['entry_sequence'] = 2;
                    } elseif ($model instanceof StockLot) {
                        $row['stock_ledger_entry_id'] = $this->entry($this->transaction())->id;
                    } elseif ($model instanceof StockLotAllocation) {
                        $row['stock_ledger_entry_id'] = $this->entry($this->transaction(), ['quantity' => '-0.1234'])->id;
                    } elseif ($model instanceof StockBalance) {
                        $this->assertDatabaseRejects(fn () => DB::table('stock_balances')->where('id', $model->id)->update([$column => $quantity]));

                        continue;
                    }
                    $this->assertDatabaseRejects(fn () => DB::table($model->getTable())->insert($row));
                }
            }
        }
    }

    public static function invalidQuantities(): array
    {
        return array_map(fn ($quantity) => [$quantity], ['0', '-0.0001', '0.00000', '1.23456', '1e3', 'NaN', '', '10000000000.0000', '12345678901234.1234', '100000000000000.0000']);
    }

    #[DataProvider('invalidQuantities')]
    public function test_posting_dto_rejects_nonpositive_overprecision_and_out_of_range_quantities(string $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StockPostingLineData($this->item->id, null, $this->location->id, StockDirection::In, $quantity);
    }
}
