<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockIn;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Queries\SystemStockQuery;
use App\Queries\TransactionRecapQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitoringQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_recap_and_system_stock_are_scoped_to_own_vendor(): void
    {
        $this->seed(MasterDataSeeder::class);
        InventoryPeriod::create(['cutoff_at' => now()->subDay(), 'status' => 'ACTIVE']);
        $admin = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $item = Item::create(['item_code' => 'MON', 'part_number' => 'MON-PN', 'item_name' => 'Monitor', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 1]);
        $relations = collect(['A', 'B'])->map(function ($code) use ($item, $location) {
            $vendor = Vendor::create(['vendor_code' => $code, 'vendor_name' => "Vendor {$code}"]);

            return VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);
        });
        foreach ($relations as $relation) {
            app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'monitoring-test', (string) Str::uuid(), CarbonImmutable::now(), $admin->id, (string) Str::uuid(), null, [new StockPostingLineData($item->id, $relation->id, $location->id, StockDirection::In, '5')]));
        }
        $vendorUser = User::factory()->vendor($relations[0]->vendor)->create();

        $this->assertSame(1, app(TransactionRecapQuery::class)->build($vendorUser, [])->count());
        $this->assertSame(1, app(SystemStockQuery::class)->build($vendorUser, [])->count());
        $this->assertSame($relations[0]->id, app(SystemStockQuery::class)->build($vendorUser, [])->first()->vendor_item_id);
    }

    public function test_recap_summary_uses_all_filtered_rows(): void
    {
        $this->seed(MasterDataSeeder::class);
        InventoryPeriod::create(['cutoff_at' => now()->subDay(), 'status' => 'ACTIVE']);
        $admin = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $item = Item::create(['item_code' => 'SUM', 'part_number' => 'SUM-PN', 'item_name' => 'Summary', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 1]);
        $vendor = Vendor::create(['vendor_code' => 'SUM', 'vendor_name' => 'Summary Vendor']);
        $relation = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);
        app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'monitoring-test', (string) Str::uuid(), CarbonImmutable::now(), $admin->id, (string) Str::uuid(), null, [new StockPostingLineData($item->id, $relation->id, $location->id, StockDirection::In, '7')]));

        $this->assertSame('7.0000', app(TransactionRecapQuery::class)->summaries($admin, [])['in']);
    }
}
