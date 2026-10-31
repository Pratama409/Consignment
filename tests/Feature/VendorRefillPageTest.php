<?php

namespace Tests\Feature;

use App\Actions\Refills\CreateVendorRefill;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VendorRefillPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_create_and_submit_refill(): void
    {
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::create(['vendor_code' => 'PAGE', 'vendor_name' => 'Page Vendor']);
        $item = Item::create(['item_code' => 'PAGE-ITEM', 'part_number' => 'PAGE-PN', 'item_name' => 'Page item', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 10]);
        VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => Location::firstOrFail()->id]);
        $this->actingAs($admin)->get(route('refills.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Refills/Create')->has('vendors', 1));
        $this->actingAs($admin)->post(route('refills.store'), ['vendor_id' => $vendor->id, 'notes' => 'Test', 'lines' => [['item_id' => $item->id, 'quantity' => 5]]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('vendor_refills', ['vendor_id' => $vendor->id, 'status' => 'REQUESTED']);
    }

    public function test_vendor_index_is_scoped_and_warehouse_uses_incoming_page(): void
    {
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->admin()->create();
        $warehouse = User::factory()->warehouse()->create();
        $first = Vendor::create(['vendor_code' => 'ONE', 'vendor_name' => 'One']);
        $second = Vendor::create(['vendor_code' => 'TWO', 'vendor_name' => 'Two']);
        $vendorUser = User::factory()->vendor($first)->create();
        $item = Item::create(['item_code' => 'SCOPE', 'part_number' => 'SCOPE', 'item_name' => 'Scope', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 10]);
        foreach ([$first, $second] as $vendor) {
            VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => Location::firstOrFail()->id]);
            app(CreateVendorRefill::class)->handle($vendor, $admin, [['item_id' => $item->id, 'quantity' => 5]]);
        }
        $this->actingAs($vendorUser)->get(route('refills.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('refills.total', 1)->where('refills.data.0.vendor', 'One'));
        $this->actingAs($warehouse)->get(route('incoming-refills.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Refills/Incoming'));
        $this->actingAs($vendorUser)->get(route('incoming-refills.index'))->assertForbidden();
    }

    public function test_warehouse_cannot_create_refill(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->warehouse()->create())->get(route('refills.create'))->assertForbidden();
    }
}
