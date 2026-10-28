<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MasterPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_reference_forms_save_pic_and_keep_stable_ids(): void
    {
        $this->post(route('vendors.store'), ['code' => ' v-a ', 'name' => 'Vendor A', 'pic_name' => 'Ibu Rina', 'is_active' => true])->assertSessionHasNoErrors()->assertRedirect(route('vendors.index'));
        $vendor = Vendor::sole();
        $this->assertSame('V-A', $vendor->vendor_code);
        $this->put(route('vendors.update', $vendor->id), ['code' => 'V-B', 'name' => 'Vendor B', 'pic_name' => 'Pak Budi', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame('Pak Budi', $vendor->fresh()->pic_name);
        $this->get(route('vendors.index', ['q' => 'Vendor B']))->assertInertia(fn (Assert $page) => $page->component('MasterData/References/Index')->where('records.total', 1)->where('records.data.0.id', $vendor->id)->where('records.data.0.pic_name', 'Pak Budi'));
        $this->post(route('locations.store'), ['code' => ' rack-01 ', 'name' => '', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('locations', ['location_code' => 'RACK-01', 'location_name' => null]);
    }

    public function test_reference_validation_protects_system_location_and_used_vendor(): void
    {
        $system = Location::where('location_code', Location::UNASSIGNED)->firstOrFail();
        $this->patch(route('locations.status', $system->id), ['is_active' => false])->assertSessionHasErrors('is_active');
        $this->put(route('locations.update', $system->id), ['code' => 'OTHER', 'name' => 'Changed', 'is_active' => true])->assertSessionHasErrors('code');
        $this->post(route('vendors.store'), ['code' => 'WAREHOUSE', 'name' => 'Internal', 'is_active' => true])->assertSessionHasErrors('code');
        $vendor = Vendor::create(['vendor_code' => 'V1', 'vendor_name' => 'First']);
        app(SaveItem::class)->handle($this->payload(['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [['vendor_id' => $vendor->id, 'location_id' => $system->id, 'is_active' => true]]]));
        $this->patch(route('vendors.status', $vendor->id), ['is_active' => false])->assertSessionHasErrors('is_active');
        $this->assertTrue($vendor->fresh()->is_active);
        $this->post(route('vendors.store'), ['code' => 'v1', 'name' => 'Duplicate', 'is_active' => true])->assertSessionHasErrors('code');
    }

    public function test_barang_create_detail_edit_and_status_preserve_identity_and_placements(): void
    {
        $location = Location::where('location_code', Location::UNASSIGNED)->firstOrFail();
        $data = $this->payload(['stock_locations' => [['location_id' => $location->id, 'is_active' => true]]]);
        $this->post(route('items.store'), $data)->assertSessionHasNoErrors();
        $item = Item::sole();
        $linkId = $item->stockItemLocations()->sole()->id;
        $this->get(route('items.show', $item))->assertInertia(fn (Assert $page) => $page->component('MasterData/Items/Show')->where('item.item_code', $item->item_code)->has('item.stock_locations', 1));
        $this->get(route('items.edit', $item))->assertInertia(fn (Assert $page) => $page->component('MasterData/Items/Form')->where('item.ownership_type', 'STOCK')->where('options.unassigned_id', $location->id));
        $this->put(route('items.update', $item), [...$data, 'minimum_stock' => '123.4567'])->assertRedirect(route('items.show', $item))->assertSessionHasNoErrors();
        $this->assertSame('123.4567', $item->fresh()->minimum_stock);
        $this->assertSame($linkId, $item->stockItemLocations()->sole()->id);
        $this->put(route('items.update', $item), [...$data, 'ownership_type' => 'CONSIGNMENT'])->assertSessionHasErrors();
        $this->patch(route('items.status', $item), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->is_active);
        $this->assertSame($linkId, $item->stockItemLocations()->sole()->id);
    }

    public function test_item_search_filters_and_summary_use_actual_records(): void
    {
        app(SaveItem::class)->handle($this->payload());
        app(SaveItem::class)->handle($this->payload(['part_number' => 'OTHER', 'item_name' => 'Other item', 'is_active' => false]));
        $this->get(route('items.index', ['q' => '%', 'ownership' => 'STOCK', 'status' => 'active']))->assertInertia(fn (Assert $page) => $page->component('MasterData/Items/Index')->where('items.total', 1)->where('summary.total', 2)->where('summary.active', 1)->where('items.data.0.part_number', '000123.%'));
        $this->get(route('items.index', ['sort' => 'not_a_column']))->assertSessionHasErrors('sort');
    }

    public function test_guests_and_read_only_users_cannot_mutate_any_master(): void
    {
        $item = app(SaveItem::class)->handle($this->payload());
        $vendor = Vendor::create(['vendor_code' => 'V1', 'vendor_name' => 'First']);
        $location = Location::where('location_code', Location::UNASSIGNED)->firstOrFail();
        $this->actingAs(User::factory()->create());
        foreach (['items.index', 'vendors.index', 'locations.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('items.show', $item))->assertOk();
        $this->get(route('items.create'))->assertForbidden();
        $this->get(route('items.edit', $item))->assertForbidden();
        $this->post(route('items.store'), $this->payload())->assertForbidden();
        $this->put(route('items.update', $item), $this->payload())->assertForbidden();
        $this->patch(route('items.status', $item), ['is_active' => false])->assertForbidden();
        foreach (['vendors' => $vendor->id, 'locations' => $location->id] as $resource => $id) {
            $this->post(route($resource.'.store'), ['code' => 'NEW', 'name' => 'New', 'is_active' => true])->assertForbidden();
            $this->put(route($resource.'.update', $id), ['code' => 'CHANGED', 'name' => 'Changed', 'is_active' => true])->assertForbidden();
            $this->patch(route($resource.'.status', $id), ['is_active' => false])->assertForbidden();
        }
        $this->get(route('imports.index'))->assertForbidden();
        $this->get(route('imports.template'))->assertForbidden();
        $this->post(route('imports.store'))->assertForbidden();
        auth()->logout();
        $this->get(route('items.index'))->assertRedirect(route('login'));
        $this->get(route('vendors.index'))->assertRedirect(route('login'));
        $this->get(route('items.export'))->assertRedirect(route('login'));
    }

    private function payload(array $changes = []): array
    {
        return [...['part_number' => '000123.%', 'item_name' => 'Bearing', 'ownership_type' => 'STOCK', 'unit_id' => Unit::where('code', 'PC')->value('id'), 'minimum_stock' => '0', 'tracking_type' => 'QUANTITY', 'is_active' => true, 'stock_locations' => [], 'vendor_items' => []], ...$changes];
    }
}
