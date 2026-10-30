<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorRefillSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_refill_workflow_uses_normalized_uuid_tables(): void
    {
        $tables = [
            'vendor_refills', 'vendor_refill_lines', 'vendor_refill_revisions',
            'vendor_refill_shipments', 'vendor_refill_shipment_lines',
            'vendor_refill_receipts', 'vendor_refill_receipt_lines',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
            $this->assertTrue(Schema::hasColumn($table, 'id'));
        }
    }

    public function test_refill_lines_reject_duplicate_items_and_non_positive_quantities(): void
    {
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::create(['vendor_code' => 'VRV', 'vendor_name' => 'Vendor Refill']);
        $item = Item::create([
            'item_code' => 'REFILL-ITEM', 'part_number' => 'PN-REFILL', 'item_name' => 'Refill Item',
            'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY',
            'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 10,
        ]);
        $refillId = (string) Str::uuid();
        DB::table('vendor_refills')->insert([
            'id' => $refillId, 'refill_no' => 'VRV-VRV-20261030-0001', 'vendor_id' => $vendor->id,
            'status' => 'REQUESTED', 'requested_by' => $admin->id, 'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = ['vendor_refill_id' => $refillId, 'item_id' => $item->id, 'requested_quantity' => 10, 'created_at' => now(), 'updated_at' => now()];
        DB::table('vendor_refill_lines')->insert(['id' => (string) Str::uuid(), ...$base]);

        $this->expectException(QueryException::class);
        DB::table('vendor_refill_lines')->insert(['id' => (string) Str::uuid(), ...$base]);
    }

    public function test_refill_line_quantity_must_be_positive(): void
    {
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::create(['vendor_code' => 'QTY', 'vendor_name' => 'Quantity Vendor']);
        $item = Item::create([
            'item_code' => 'QTY-ITEM', 'part_number' => 'QTY-PN', 'item_name' => 'Quantity Item',
            'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY',
            'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 10,
        ]);
        $refillId = (string) Str::uuid();
        DB::table('vendor_refills')->insert(['id' => $refillId, 'refill_no' => 'VRV-QTY-20261030-0001', 'vendor_id' => $vendor->id, 'status' => 'REQUESTED', 'requested_by' => $admin->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('vendor_refill_lines')->insert(['id' => (string) Str::uuid(), 'vendor_refill_id' => $refillId, 'item_id' => $item->id, 'requested_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
}
