<?php

namespace Tests\Feature;

use App\Actions\Receipts\PostManualReceipt;
use App\Http\Controllers\ManualDataInController;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualDataInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/manual-data-in', [ManualDataInController::class, 'index'])->name('manual-data-in.index');
        Route::post('/manual-data-in', [ManualDataInController::class, 'store'])->name('manual-data-in.store');
    }

    public function test_manual_receipt_uses_normalized_uuid_tables(): void
    {
        foreach (['manual_receipts', 'manual_receipt_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumn($table, 'id'));
        }
    }

    public function test_warehouse_posts_mixed_manual_receipt_once_with_whole_units(): void
    {
        [$warehouse, $stock, $consignment, $relation, $location] = $this->scenario();
        $key = (string) Str::uuid();
        $lines = [
            ['item_id' => $stock->id, 'location_id' => $location->id, 'quantity' => 2],
            ['item_id' => $consignment->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 3],
        ];
        $receipt = app(PostManualReceipt::class)->handle($warehouse, 'Urgent maintenance', null, 'Receiver', CarbonImmutable::now(), $lines, $key);
        $retry = app(PostManualReceipt::class)->handle($warehouse, 'Urgent maintenance', null, 'Receiver', CarbonImmutable::now(), $lines, $key);

        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame('POSTED', $receipt->status);
        $this->assertCount(2, $receipt->lines);
        $this->assertSame(['2.0000', '3.0000'], StockBalance::orderBy('quantity')->pluck('quantity')->all());
    }

    public function test_manual_receipt_rejects_fractional_quantity_and_invalid_consignment_pair(): void
    {
        [$warehouse, , $consignment, , $location] = $this->scenario();
        $this->expectException(ValidationException::class);
        app(PostManualReceipt::class)->handle($warehouse, 'Urgent', null, 'Receiver', CarbonImmutable::now(), [['item_id' => $consignment->id, 'location_id' => $location->id, 'quantity' => 1.5]], (string) Str::uuid());
    }

    public function test_only_warehouse_can_post_while_admin_can_view_page(): void
    {
        [$warehouse, $stock, , , $location] = $this->scenario();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/manual-data-in')->assertOk();
        $this->actingAs($admin)->post('/manual-data-in', ['reason' => 'Urgent', 'receiver_name' => 'Receiver', 'received_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'lines' => [['item_id' => $stock->id, 'location_id' => $location->id, 'quantity' => 1]]])->assertForbidden();
        $this->actingAs($warehouse)->get('/manual-data-in')->assertOk();
    }

    private function scenario(): array
    {
        $this->seed(MasterDataSeeder::class);
        InventoryPeriod::create(['cutoff_at' => now()->subDay(), 'status' => 'ACTIVE']);
        $warehouse = User::factory()->warehouse()->create();
        $location = Location::firstOrFail();
        $unit = Unit::firstOrFail();
        $stock = Item::create(['item_code' => 'DIN-STOCK', 'part_number' => 'DIN-STOCK', 'item_name' => 'Stock', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 0]);
        StockItemLocation::create(['item_id' => $stock->id, 'location_id' => $location->id]);
        $consignment = Item::create(['item_code' => 'DIN-CONS', 'part_number' => 'DIN-CONS', 'item_name' => 'Consignment', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 0]);
        $vendor = Vendor::create(['vendor_code' => 'DIN', 'vendor_name' => 'Data In Vendor']);
        $relation = VendorItem::create(['item_id' => $consignment->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);

        return [$warehouse, $stock, $consignment, $relation, $location];
    }
}
