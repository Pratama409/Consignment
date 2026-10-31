<?php

namespace Tests\Feature;

use App\Actions\Inventory\ApproveStockOpname;
use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\RecordOpnameCount;
use App\Actions\Inventory\SubmitOpnameCount;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\StockOpnameSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class StockOpnameReportTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private Item $item;

    private Location $location;

    private StockBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create(['name' => 'Gita Leader', 'position' => 'General Leader']);
        $this->warehouse = User::factory()->warehouse()->create(['name' => 'Wawan Gudang', 'position' => 'Staf Warehouse']);
        $this->location = Location::firstOrFail();
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
        $this->item = Item::create(['item_code' => 'REP-ITEM', 'part_number' => 'PN-REP', 'item_name' => 'Report item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $this->item->id, 'location_id' => $this->location->id]);
        app(PostStockIn::class)->handle($this->posting('5.0000'));
        $this->bucket = $this->bucketFor($this->item, null, $this->location);
    }

    public function test_admin_report_shows_people_difference_and_movements_after_the_count(): void
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$this->bucket->id]], $this->admin);
        app(RecordOpnameCount::class)->handle($session->lines()->firstOrFail(), '4', $this->warehouse);
        $this->travel(5)->minutes();
        $in = app(PostStockIn::class)->handle($this->posting('1.0000'));
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin);

        $this->actingAs($this->admin)->get(route('stock-opname.report', $session))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Opname/Report')
            ->where('session.number', $session->session_no)
            ->where('session.creator.name', 'Gita Leader')
            ->where('session.counter.name', 'Wawan Gudang')
            ->where('session.counter.position', 'Staf Warehouse')
            ->where('session.approver.name', 'Gita Leader')
            ->where('session.approver.position', 'General Leader')
            ->where('lines.0.system_quantity', '6.0000')
            ->where('lines.0.counted_quantity', '4.0000')
            ->where('lines.0.difference', '-2.0000')
            ->where('lines.0.state', 'SHORT')
            ->where('lines.0.movements.0.transaction_no', $in->transaction_no));
    }

    public function test_warehouse_and_vendor_cannot_open_the_report(): void
    {
        $session = StockOpnameSession::create(['session_no' => 'SO-REPORT', 'scope_type' => 'FULL', 'created_by' => $this->admin->id]);
        $vendor = User::factory()->vendor(Vendor::create(['vendor_code' => 'REP-V', 'vendor_name' => 'Report Vendor']))->create();

        $this->actingAs($this->warehouse)->get(route('stock-opname.report', $session))->assertForbidden();
        $this->actingAs($vendor)->get(route('stock-opname.report', $session))->assertForbidden();
    }

    private function posting(string $quantity): StockPostingData
    {
        return new StockPostingData(StockTransactionType::ManualReceipt, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($this->item->id, null, $this->location->id, StockDirection::In, $quantity),
        ]);
    }
}
