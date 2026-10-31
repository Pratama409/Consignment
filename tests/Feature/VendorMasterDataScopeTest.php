<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class VendorMasterDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $own;

    private Vendor $other;

    private Item $shared;

    private Item $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(MasterDataSeeder::class);
        $this->own = Vendor::create(['vendor_code' => 'OWN', 'vendor_name' => 'Own supplier']);
        $this->other = Vendor::create(['vendor_code' => 'SECRET', 'vendor_name' => 'Secret supplier']);
        $save = fn ($pn, $vendors) => app(SaveItem::class)->handle(['part_number' => $pn, 'item_name' => $pn, 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::first()->id, 'minimum_stock' => '0', 'is_active' => true, 'vendor_items' => array_map(fn ($vendor) => ['vendor_id' => $vendor->id, 'location_id' => Location::where('location_code', 'UNASSIGNED')->value('id')], $vendors)]);
        $this->shared = $save('SHARED', [$this->own, $this->other]);
        $this->foreign = $save('FOREIGN-SECRET', [$this->other]);
        $this->actingAs(User::factory()->vendor($this->own)->create());
    }

    public function test_vendor_index_and_detail_scope_rows_counts_options_and_relations(): void
    {
        $this->get('/master-data/barang')->assertInertia(fn (Assert $page) => $page->where('items.total', 1)->where('summary.total', 1)
            ->where('items.data.0.id', $this->shared->id)->where('items.data.0.vendor_count', 1)->has('items.data.0.placements', 1)
            ->has('options.vendors', 1)->where('options.vendors.0.id', $this->own->id)->where('options.categories', [])->where('options.locations', []));
        $response = $this->get('/master-data/barang/'.$this->shared->id)->assertInertia(fn (Assert $page) => $page->has('item.vendor_items', 1)->where('item.vendor_items.0.vendor_id', $this->own->id)->where('can_update', false));
        $this->assertStringNotContainsString('Secret supplier', $response->getContent());
        $this->get('/master-data/barang/'.$this->foreign->id)->assertForbidden();
    }

    public function test_forged_vendor_filter_and_foreign_item_routes_are_forbidden(): void
    {
        foreach (['/master-data/barang', '/master-data/barang/export'] as $url) {
            $this->get($url.'?vendor='.$this->other->id)->assertForbidden();
        }
        foreach (['/master-data/satuan', '/master-data/kategori', '/master-data/lokasi', '/master-data/import'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/master-data/vendor')->assertInertia(fn (Assert $page) => $page->where('records.total', 1)->where('summary.total', 1)->where('records.data.0.id', $this->own->id)->where('records.data.0.usage_count', 1));
        $this->get('/master-data/barang/'.$this->foreign->id.'/edit')->assertForbidden();
    }

    public function test_vendor_export_omits_foreign_items_and_other_vendors_on_shared_items(): void
    {
        $response = $this->get('/master-data/barang/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'vendor-export-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $rows = $book->getActiveSheet()->toArray();
            $this->assertCount(2, $rows);
            $this->assertSame('OWN', $rows[1][1]);
            $this->assertSame('SHARED', $rows[1][2]);
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_vendor_cannot_discover_other_vendor_placements_through_search(): void
    {
        $this->get('/master-data/barang?q=Secret%20supplier')->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
    }

    public function test_admin_and_warehouse_exports_preserve_all_vendors_and_inactive_relations(): void
    {
        $historical = Vendor::create(['vendor_code' => 'HISTORY', 'vendor_name' => 'Historical supplier']);
        $this->shared->vendorItems()->create(['vendor_id' => $historical->id, 'location_id' => Location::where('location_code', 'UNASSIGNED')->value('id'), 'is_active' => false]);
        app(SaveItem::class)->handle(['part_number' => 'WAREHOUSE-STOCK', 'item_name' => 'Warehouse stock', 'ownership_type' => 'STOCK', 'unit_id' => Unit::first()->id, 'minimum_stock' => '0', 'stock_locations' => [['location_id' => Location::where('location_code', 'UNASSIGNED')->value('id')]]]);

        foreach ([User::factory()->admin()->create(), User::factory()->warehouse()->create()] as $actor) {
            $response = $this->actingAs($actor)->get('/master-data/barang/export')->assertOk();
            $path = tempnam(sys_get_temp_dir(), 'unrestricted-export-');
            try {
                file_put_contents($path, $response->streamedContent());
                $book = IOFactory::load($path);
                $rows = array_slice($book->getActiveSheet()->toArray(), 1);
                $this->assertCount(5, $rows);
                $this->assertEqualsCanonicalizing([
                    ['OWN', 'SHARED', '1'], ['SECRET', 'SHARED', '1'], ['HISTORY', 'SHARED', '0'],
                    ['SECRET', 'FOREIGN-SECRET', '1'], ['WAREHOUSE', 'WAREHOUSE-STOCK', '1'],
                ], array_map(fn ($row) => [$row[1], $row[2], $row[10]], $rows));
                $book->disconnectWorksheets();
            } finally {
                unlink($path);
            }
        }
    }

    public function test_warehouse_retains_unrestricted_read_access_without_mutation_abilities(): void
    {
        $this->actingAs(User::factory()->warehouse()->create());
        foreach (['/master-data/barang', '/master-data/vendor', '/master-data/satuan', '/master-data/kategori', '/master-data/lokasi'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/master-data/barang/'.$this->foreign->id)->assertOk();
        $this->get('/master-data/barang')->assertInertia(fn (Assert $page) => $page->where('items.total', 2)->where('can.create', false));
    }
}
