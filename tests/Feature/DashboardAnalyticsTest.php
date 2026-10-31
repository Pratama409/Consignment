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
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The analytics dashboard shows only quantities that exist in the ledger: no prices, no invented values. */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private User $vendorA;

    private Vendor $a;

    private Vendor $b;

    private Item $stocked;

    private Item $consignedA;

    private Item $consignedB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->a = Vendor::create(['vendor_code' => 'AAA', 'vendor_name' => 'Vendor A']);
        $this->b = Vendor::create(['vendor_code' => 'BBB', 'vendor_name' => 'Vendor B']);
        $this->vendorA = User::factory()->vendor($this->a)->create();
        $location = Location::firstOrFail();
        InventoryPeriod::create(['cutoff_at' => '2026-10-01 00:00:00', 'status' => 'ACTIVE']);

        $this->stocked = $this->item('DASH-STOCK', 'STOCK', '10');
        $this->consignedA = $this->item('DASH-CONS-A', 'CONSIGNMENT', '20');
        $this->consignedB = $this->item('DASH-CONS-B', 'CONSIGNMENT', '5');
        $this->item('DASH-NOMIN', 'STOCK', '0');
        $pairA = VendorItem::create(['item_id' => $this->consignedA->id, 'vendor_id' => $this->a->id, 'location_id' => $location->id]);
        $pairB = VendorItem::create(['item_id' => $this->consignedB->id, 'vendor_id' => $this->b->id, 'location_id' => $location->id]);
        StockItemLocation::create(['item_id' => $this->stocked->id, 'location_id' => $location->id]);
        $this->receive($this->stocked, null, $location, '4.0000');
        $this->receive($this->consignedB, $pairB, $location, '50.0000');
        $this->consignedA->setRelation('pair', $pairA);
    }

    public function test_internal_dashboard_summarises_stock_levels_without_prices(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('metrics.total_stock', '54.0000')
            ->where('metrics.low_stock', 1)
            ->where('metrics.critical_stock', 1)
            ->where('metrics.active_vendors', Vendor::active()->count())
            ->where('metrics.total_items', Item::active()->count())
            ->where('lists.low_stock.0.code', 'DASH-STOCK')
            ->where('lists.low_stock.0.quantity', '4.0000')
            ->where('lists.low_stock.0.minimum', '10.0000')
            ->where('lists.low_stock.0.percent', 40)
            ->where('lists.critical_stock.0.code', 'DASH-CONS-A')
            ->where('lists.critical_stock.0.quantity', '0.0000')
            ->missing('metrics.stock_value')
            ->missing('metrics.ito'));
    }

    public function test_movement_chart_has_thirty_days_with_in_and_out_totals(): void
    {
        $this->actingAs($this->warehouse)->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('charts.movement', 30)
            ->where('charts.movement.29.date', '2026-10-31')
            ->where('charts.movement.29.in', 54)
            ->where('charts.movement.29.out', 0)
            ->where('charts.movement.0.date', '2026-10-02'));
    }

    public function test_composition_groups_stock_by_vendor_for_internal_users(): void
    {
        $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('charts.composition', fn ($slices) => collect($slices)->pluck('value', 'label')->all() === ['Vendor B' => 50, 'Stok internal' => 4]));
    }

    public function test_vendor_sees_only_its_own_figures(): void
    {
        $response = $this->actingAs($this->vendorA)->get('/');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total_stock', '0.0000')
            ->where('metrics.active_requests', null)
            ->where('metrics.active_vendors', null)
            ->where('lists.low_stock', [])
            ->where('lists.critical_stock.0.code', 'DASH-CONS-A')
            ->where('recent_transactions', [])
            ->where('charts.movement.29.in', 0));
        $content = $response->getContent();
        foreach (['DASH-STOCK', 'DASH-CONS-B', 'Vendor B'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $content);
        }
    }

    public function test_quick_links_follow_the_role(): void
    {
        $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('quick_links', fn ($links) => collect($links)->pluck('label')->all() === ['Data IN', 'Data OUT', 'PR, PO & Dokumen', 'Stock Sistem', 'Stock Opname']));
        $this->actingAs($this->vendorA)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('quick_links', fn ($links) => collect($links)->pluck('label')->all() === ['Vendor Refill', 'Stock Sistem', 'PR, PO & Dokumen']));
    }

    private function item(string $code, string $ownership, string $minimum): Item
    {
        return Item::create(['item_code' => $code, 'part_number' => 'PN-'.$code, 'item_name' => 'Item '.$code, 'ownership_type' => $ownership, 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => $minimum]);
    }

    private function receive(Item $item, ?VendorItem $pair, Location $location, string $quantity): void
    {
        app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($item->id, $pair?->id, $location->id, StockDirection::In, $quantity),
        ]));
    }
}
