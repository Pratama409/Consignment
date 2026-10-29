<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryAndItemFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_admin_manages_categories_with_uuid_codes_validation_and_stable_identity(): void
    {
        $this->post(route('categories.store'), ['code' => ' filter ', 'name' => 'Filter', 'is_active' => true])->assertSessionHasNoErrors();
        $category = Category::sole();
        $this->assertTrue(Str::isUuid($category->id));
        $this->assertSame('FILTER', $category->code);
        $this->put(route('categories.update', $category->id), ['code' => 'FILTER', 'name' => 'Filter barang', 'is_active' => true])->assertSessionHasNoErrors();
        $this->post(route('categories.store'), ['code' => 'filter', 'name' => 'Duplicate', 'is_active' => true])->assertSessionHasErrors('code');
        $this->post(route('categories.store'), ['code' => 'EMPTY', 'name' => '', 'is_active' => true])->assertSessionHasErrors('name');
        $this->get(route('categories.index', ['q' => 'Filter barang']))->assertInertia(fn (Assert $page) => $page->component('MasterData/References/Index')->where('records.data.0.id', $category->id)->where('records.data.0.usage_count', 0));
        $this->patch(route('categories.status', $category->id), ['is_active' => false])->assertSessionHasNoErrors();
        $this->post(route('categories.store'), ['code' => 'FILTER', 'name' => 'Inactive duplicate', 'is_active' => true])->assertSessionHasErrors('code');
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_category_is_optional_and_status_changes_preserve_the_chosen_category(): void
    {
        $item = $this->save();
        $this->assertNull($item->category_id);
        $category = Category::create(['code' => 'GET', 'name' => 'GET']);
        $item = $this->save(['category_id' => $category->id], $item);
        $this->get(route('items.show', $item))->assertInertia(fn (Assert $page) => $page->where('item.category.code', 'GET'));
        $this->patch(route('items.status', $item), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame($category->id, $item->fresh()->category_id);
        $this->save(['item_name' => 'Renamed'], $item);
        $this->assertSame($category->id, $item->fresh()->category_id);
        $this->save(['category_id' => null], $item);
        $this->assertNull($item->fresh()->category_id);
    }

    public function test_used_category_cannot_be_disabled_and_inactive_category_cannot_be_selected_or_reactivated(): void
    {
        $category = Category::create(['code' => 'FILTER', 'name' => 'Filter']);
        $item = $this->save(['category_id' => $category->id]);
        $this->patch(route('categories.status', $category->id), ['is_active' => false])->assertSessionHasErrors('is_active');
        $this->patch(route('items.status', $item), ['is_active' => false])->assertSessionHasNoErrors();
        $this->patch(route('categories.status', $category->id), ['is_active' => false])->assertSessionHasNoErrors();
        $this->post(route('items.store'), $this->payload(['category_id' => $category->id]))->assertSessionHasErrors('category_id');
        $this->get(route('items.edit', $item))->assertInertia(fn (Assert $page) => $page->where('options.categories.0.id', $category->id)->where('options.categories.0.is_active', false));
        $this->put(route('items.update', $item), $this->payload(['category_id' => $category->id, 'is_active' => false, 'item_name' => 'Retained inactive']))->assertSessionHasNoErrors();
        $this->patch(route('items.status', $item), ['is_active' => true])->assertSessionHasErrors('is_active');
        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_normal_users_can_read_categories_but_cannot_change_them(): void
    {
        $category = Category::create(['code' => 'FILTER', 'name' => 'Filter']);
        $this->actingAs(User::factory()->create());
        $this->get(route('categories.index'))->assertInertia(fn (Assert $page) => $page->where('can.create', false));
        $this->post(route('categories.store'), ['code' => 'DENIED', 'name' => 'Denied', 'is_active' => true])->assertForbidden();
        $this->put(route('categories.update', $category->id), ['code' => 'DENIED', 'name' => 'Denied', 'is_active' => true])->assertForbidden();
        $this->patch(route('categories.status', $category->id), ['is_active' => false])->assertForbidden();
        auth()->logout();
        $this->get(route('categories.index'))->assertRedirect(route('login'));
    }

    public function test_vendor_and_location_filters_must_match_the_same_active_relation(): void
    {
        $first = Vendor::create(['vendor_code' => 'A', 'vendor_name' => 'First']);
        $second = Vendor::create(['vendor_code' => 'B', 'vendor_name' => 'Second']);
        $rackA = Location::create(['location_code' => 'RACK-A']);
        $rackB = Location::create(['location_code' => 'RACK-B']);
        $category = Category::create(['code' => 'FILTER', 'name' => 'Filter']);
        $item = $this->save(['ownership_type' => 'CONSIGNMENT', 'category_id' => $category->id, 'vendor_items' => [
            ['vendor_id' => $first->id, 'location_id' => $rackA->id], ['vendor_id' => $second->id, 'location_id' => $rackB->id],
        ]]);
        $this->save(['part_number' => 'OTHER', 'stock_locations' => [['location_id' => $rackB->id]]]);
        $this->get(route('items.index', ['vendor' => $first->id, 'location' => $rackB->id]))->assertInertia(fn (Assert $page) => $page->where('items.total', 0)->where('summary.total', 2));
        $this->get(route('items.index', ['vendor' => $first->id, 'location' => $rackA->id, 'category' => $category->id]))->assertInertia(fn (Assert $page) => $page->where('items.total', 1)->where('items.data.0.id', $item->id)->where('items.data.0.vendor_count', 2));
        $this->get(route('items.index', ['location' => $rackB->id]))->assertInertia(fn (Assert $page) => $page->where('items.total', 2));
        $item->vendorItems()->where('vendor_id', $second->id)->firstOrFail()->update(['is_active' => false]);
        $this->get(route('items.index', ['vendor' => $second->id]))->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->get(route('items.index', ['location' => $rackB->id]))->assertInertia(fn (Assert $page) => $page->where('items.total', 1));
    }

    public function test_category_and_reference_search_do_not_duplicate_catalog_rows(): void
    {
        $category = Category::create(['code' => 'CAT-%', 'name' => 'Kelompok khusus']);
        $vendor = Vendor::create(['vendor_code' => 'SEARCH-VENDOR', 'vendor_name' => 'Nama pemasok']);
        $location = Location::create(['location_code' => 'SEARCH-RACK', 'location_name' => 'Rak khusus']);
        $item = $this->save(['category_id' => $category->id, 'ownership_type' => 'CONSIGNMENT', 'vendor_items' => [['vendor_id' => $vendor->id, 'location_id' => $location->id]]]);
        $this->save(['part_number' => 'UNCATEGORIZED']);
        foreach (['Nama pemasok', 'SEARCH-RACK', 'Rak khusus', 'Kelompok khusus', '%'] as $term) {
            $this->get(route('items.index', ['q' => $term]))->assertInertia(fn (Assert $page) => $page->where('items.total', 1)->where('items.data.0.id', $item->id));
        }
        $this->get(route('items.index', ['category' => 'uncategorized']))->assertInertia(fn (Assert $page) => $page->where('items.total', 1)->where('items.data.0.part_number', 'UNCATEGORIZED'));
        $this->get(route('items.index', ['category' => 'not-a-category']))->assertSessionHasErrors('category');
        $this->get(route('items.index', ['vendor' => (string) Str::uuid7()]))->assertSessionHasErrors('vendor');
    }

    public function test_database_foreign_key_protects_categories_even_when_model_events_are_bypassed(): void
    {
        $category = Category::create(['code' => 'FILTER', 'name' => 'Filter']);
        $this->save(['category_id' => $category->id]);
        $this->expectException(QueryException::class);
        DB::table('categories')->where('id', $category->id)->delete();
    }

    public function test_pagination_keeps_category_vendor_and_location_filters(): void
    {
        $category = Category::create(['code' => 'PAGE', 'name' => 'Pagination']);
        $vendor = Vendor::create(['vendor_code' => 'PAGE-V', 'vendor_name' => 'Vendor pagination']);
        $location = Location::create(['location_code' => 'PAGE-RACK']);
        for ($index = 0; $index < 11; $index++) {
            $this->save(['category_id' => $category->id, 'ownership_type' => 'CONSIGNMENT', 'vendor_items' => [['vendor_id' => $vendor->id, 'location_id' => $location->id]]]);
        }
        $this->get(route('items.index', ['category' => $category->id, 'vendor' => $vendor->id, 'location' => $location->id, 'page' => 2]))->assertInertia(fn (Assert $page) => $page->where('items.total', 11)->has('items.data', 1)->where('items.current_page', 2)
            ->where('items.prev_page_url', fn ($url) => str_contains($url, 'category='.$category->id) && str_contains($url, 'vendor='.$vendor->id) && str_contains($url, 'location='.$location->id)));
    }

    private function payload(array $changes = []): array
    {
        return [...['part_number' => 'PN', 'item_name' => 'Barang', 'ownership_type' => 'STOCK', 'unit_id' => Unit::where('code', 'PC')->value('id'), 'minimum_stock' => '0', 'is_active' => true, 'tracking_type' => 'QUANTITY', 'vendor_items' => [], 'stock_locations' => []], ...$changes];
    }

    private function save(array $changes = [], ?Item $item = null): Item
    {
        return app(SaveItem::class)->handle($this->payload($changes), $item);
    }
}
