<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Enums\OwnershipType;
use App\Enums\TrackingType;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\VendorItem;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public function test_stock_can_be_saved_without_a_vendor_and_receives_a_stable_internal_code(): void
    {
        $item = $this->save();

        $this->assertSame(OwnershipType::Stock, $item->ownership_type);
        $this->assertSame(TrackingType::Quantity, $item->tracking_type);
        $this->assertTrue(Str::isUuid($item->id));
        $this->assertSame('ITM-000001', $item->item_code);
        $this->assertCount(0, $item->vendorItems);

        $updated = $this->save(['part_number' => 'PN-REVISED', 'item_name' => 'Nama diperbaiki'], $item);
        $this->assertSame($item->id, $updated->id);
        $this->assertSame($item->item_code, $updated->item_code);
    }

    public function test_duplicate_part_number_and_name_are_allowed_even_with_different_ownership(): void
    {
        $stock = $this->save();
        $consignment = $this->save($this->consignment());

        $this->assertSame($stock->part_number, $consignment->part_number);
        $this->assertSame($stock->item_name, $consignment->item_name);
        $this->assertNotSame($stock->item_code, $consignment->item_code);
        $this->assertDatabaseCount('items', 2);
    }

    public function test_stock_with_a_vendor_is_rejected(): void
    {
        $vendor = $this->vendor();
        $this->invalid(fn () => $this->save(['vendor_items' => [$this->link($vendor)]]), 'vendor_items');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_consignment_without_any_vendor_is_rejected(): void
    {
        $this->invalid(fn () => $this->save(['ownership_type' => 'CONSIGNMENT']), 'vendor_items');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_consignment_with_only_inactive_relationships_is_rejected(): void
    {
        $payload = $this->consignment();
        $payload['vendor_items'][0]['is_active'] = false;
        $this->invalid(fn () => $this->save($payload), 'vendor_items');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_consignment_can_have_multiple_vendors_each_with_one_location(): void
    {
        $first = $this->vendor('A');
        $second = $this->vendor('B');
        $location = Location::create(['location_code' => 'FL503A']);
        $item = $this->save([
            'ownership_type' => 'CONSIGNMENT',
            'minimum_stock' => '25',
            'vendor_items' => [$this->link($first), $this->link($second, $location)],
        ]);

        $this->assertCount(2, $item->vendorItems);
        $this->assertSame('25.0000', $item->minimum_stock);
        $this->assertSame($location->id, $item->vendorItems->firstWhere('vendor_id', $second->id)->location->id);
        $this->assertSame($first->id, $item->vendorItems->firstWhere('vendor_id', $first->id)->vendor->id);
    }

    public function test_repeating_a_vendor_in_the_form_is_rejected(): void
    {
        $payload = $this->consignment();
        $payload['vendor_items'][] = $payload['vendor_items'][0];
        $this->invalid(fn () => $this->save($payload), 'vendor_items.0.vendor_id');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_an_inactive_vendor_cannot_be_selected_and_all_partial_writes_are_rolled_back(): void
    {
        $good = $this->vendor('GOOD');
        $inactive = $this->vendor('INACTIVE');
        $inactive->update(['is_active' => false]);

        $this->invalid(fn () => $this->save([
            'ownership_type' => 'CONSIGNMENT',
            'vendor_items' => [$this->link($good), $this->link($inactive)],
        ]), 'vendor_items');

        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('vendor_items', 0);
    }

    public function test_an_inactive_location_cannot_be_selected(): void
    {
        $location = Location::create(['location_code' => 'OLD', 'is_active' => false]);
        $payload = $this->consignment();
        $payload['vendor_items'][0]['location_id'] = $location->id;
        $this->invalid(fn () => $this->save($payload), 'vendor_items');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_an_inactive_unit_cannot_be_selected(): void
    {
        $unit = Unit::create(['code' => 'OLD', 'name' => 'Old', 'is_active' => false]);
        $this->invalid(fn () => $this->save(['unit_id' => $unit->id]), 'unit_id');
    }

    public function test_minimum_stock_can_be_adjusted_and_preserves_decimal_quantities(): void
    {
        $payload = $this->consignment();
        $item = $this->save($payload);
        $updated = $this->save([...$payload, 'minimum_stock' => '2.125'], $item);

        $this->assertSame('2.1250', $updated->minimum_stock);
        $this->assertSame($item->id, $updated->id);
        $this->assertSame($item->vendorItems->first()->id, $updated->vendorItems->first()->id);
    }

    public function test_negative_minimum_stock_is_rejected(): void
    {
        $this->invalid(fn () => $this->save(['minimum_stock' => '-1']), 'minimum_stock');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_minimum_with_more_than_four_decimal_places_is_rejected(): void
    {
        $this->invalid(fn () => $this->save(['minimum_stock' => '1.12345']), 'minimum_stock');
    }

    public function test_item_ownership_cannot_be_changed_after_creation(): void
    {
        $item = $this->save();
        $this->invalid(fn () => $this->save($this->consignment(), $item), 'ownership_type');
        $this->assertSame(OwnershipType::Stock, $item->fresh()->ownership_type);
        $this->assertDatabaseCount('vendor_items', 0);
    }

    public function test_internal_code_cannot_be_supplied_by_the_form_or_edited(): void
    {
        $this->invalid(fn () => $this->save(['item_code' => 'CUSTOM']), 'item_code');
        $item = $this->save();
        $this->invalid(fn () => $item->update(['item_code' => 'CUSTOM']), 'item_code');
    }

    public function test_serialized_workflows_are_not_enabled_in_v1(): void
    {
        $this->invalid(fn () => $this->save(['tracking_type' => 'SERIALIZED']), 'tracking_type');
    }

    public function test_relationship_location_changes_preserve_the_relationship_id(): void
    {
        $payload = $this->consignment();
        $item = $this->save($payload);
        $location = Location::create(['location_code' => 'FL504B']);
        $payload['vendor_items'][0]['location_id'] = $location->id;
        $updated = $this->save($payload, $item);

        $this->assertSame($item->vendorItems->first()->id, $updated->vendorItems->first()->id);
        $this->assertSame($location->id, $updated->vendorItems->first()->location_id);
    }

    public function test_vendor_replacement_retains_old_identity_and_reactivation_reuses_it(): void
    {
        $first = $this->vendor('A');
        $second = $this->vendor('B');
        $payload = ['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [$this->link($first)]];
        $item = $this->save($payload);
        $firstRelationId = $item->vendorItems->first()->id;

        $replaced = $this->save(['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [$this->link($second)]], $item);
        $this->assertFalse($replaced->vendorItems->firstWhere('vendor_id', $first->id)->is_active);
        $this->assertDatabaseCount('vendor_items', 2);

        $reactivated = $this->save($payload, $item);
        $this->assertSame($firstRelationId, $reactivated->vendorItems->firstWhere('vendor_id', $first->id)->id);
        $this->assertTrue($reactivated->vendorItems->firstWhere('vendor_id', $first->id)->is_active);
        $this->assertFalse($reactivated->vendorItems->firstWhere('vendor_id', $second->id)->is_active);
    }

    public function test_the_last_vendor_relationship_cannot_be_deactivated(): void
    {
        $item = $this->save($this->consignment());
        $relation = $item->vendorItems->first();
        $this->invalid(fn () => $relation->update(['is_active' => false]), 'vendor_items');
        $this->assertTrue($relation->fresh()->is_active);
    }

    public function test_vendor_relationship_identity_cannot_be_reassigned(): void
    {
        $item = $this->save($this->consignment());
        $other = $this->vendor('OTHER');
        $this->invalid(fn () => $item->vendorItems->first()->update(['vendor_id' => $other->id]), 'vendor_items');
    }

    public function test_failure_during_edit_restores_the_item_and_existing_relationships(): void
    {
        $payload = $this->consignment();
        $item = $this->save($payload);
        $inactive = $this->vendor('INACTIVE');
        $inactive->update(['is_active' => false]);
        $payload['vendor_items'][] = $this->link($inactive);

        $this->invalid(fn () => $this->save([...$payload, 'item_name' => 'Should roll back'], $item), 'vendor_items');
        $this->assertSame('Bearing', $item->fresh()->item_name);
        $this->assertDatabaseCount('vendor_items', 1);
        $this->assertTrue($item->vendorItems()->first()->is_active);
    }

    public function test_stock_can_have_multiple_internal_locations_and_reuse_old_placements(): void
    {
        $first = Location::create(['location_code' => 'RACK-A']);
        $second = Location::create(['location_code' => 'RACK-B']);
        $payload = ['stock_locations' => [['location_id' => $first->id], ['location_id' => $second->id]]];
        $item = $this->save($payload);
        $oldId = $item->stockItemLocations->firstWhere('location_id', $first->id)->id;
        $this->assertCount(2, $item->stockItemLocations);

        $this->save(['stock_locations' => [['location_id' => $second->id]]], $item);
        $this->assertFalse(StockItemLocation::findOrFail($oldId)->is_active);
        $restored = $this->save($payload, $item);
        $this->assertSame($oldId, $restored->stockItemLocations->firstWhere('location_id', $first->id)->id);
        $this->assertTrue(StockItemLocation::findOrFail($oldId)->is_active);
    }

    public function test_inactive_location_cannot_be_added_to_stock(): void
    {
        $location = Location::create(['location_code' => 'OLD', 'is_active' => false]);
        $this->invalid(fn () => $this->save(['stock_locations' => [['location_id' => $location->id]]]), 'stock_locations');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_consignment_cannot_have_internal_stock_placements(): void
    {
        $this->invalid(fn () => $this->save([
            ...$this->consignment(), 'stock_locations' => [['location_id' => $this->unassignedId()]],
        ]), 'stock_locations');
    }

    public function test_warehouse_is_not_a_vendor_and_pic_is_a_simple_editable_name(): void
    {
        $this->invalid(fn () => Vendor::create(['vendor_code' => ' warehouse ', 'vendor_name' => 'Internal']), 'vendor_code');
        $vendor = $this->vendor();
        $vendor->update(['pic_name' => 'Nama PIC']);
        $this->assertSame('Nama PIC', $vendor->fresh()->pic_name);
    }

    public function test_referenced_master_records_cannot_be_deactivated(): void
    {
        $payload = $this->consignment();
        $location = Location::create(['location_code' => 'RACK']);
        $payload['vendor_items'][0]['location_id'] = $location->id;
        $item = $this->save($payload);

        $this->invalid(fn () => $item->unit->update(['is_active' => false]), 'is_active');
        $this->invalid(fn () => $item->vendorItems->first()->vendor->update(['is_active' => false]), 'is_active');
        $this->invalid(fn () => $location->update(['is_active' => false]), 'is_active');
    }

    public function test_inactive_master_is_hidden_from_active_choices_and_historical_references_remain_readable(): void
    {
        $first = $this->vendor('A');
        $second = $this->vendor('B');
        $item = $this->save(['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [$this->link($first)]]);
        $this->save(['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [$this->link($second)]], $item);
        $first->update(['is_active' => false]);

        $this->assertFalse(Vendor::active()->whereKey($first->id)->exists());
        $historical = VendorItem::where('item_id', $item->id)->where('vendor_id', $first->id)->firstOrFail();
        $this->assertSame($first->id, $historical->vendor->id);
        $this->assertFalse($historical->vendor->is_active);
        $this->invalid(fn () => $this->save(['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [$this->link($first)]], $item), 'vendor_items');
    }

    public function test_items_can_be_deactivated_and_reactivated_without_changing_identity(): void
    {
        $item = $this->save();
        $inactive = $this->save(['is_active' => false], $item);
        $this->assertFalse(Item::active()->whereKey($item->id)->exists());
        $this->assertFalse($inactive->is_active);
        $active = $this->save(['is_active' => true], $item);
        $this->assertTrue($active->is_active);
        $this->assertSame($item->id, $active->id);
        $this->assertSame($item->item_code, $active->item_code);
    }

    public function test_master_records_are_not_permanently_deleted_through_models(): void
    {
        $item = $this->save();
        $this->invalid(fn () => $item->delete(), 'is_active');
        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_unassigned_cannot_be_disabled_or_renamed(): void
    {
        $location = Location::findOrFail($this->unassignedId());
        $this->invalid(fn () => $location->update(['is_active' => false]), 'location_code');
        $location->refresh();
        $this->invalid(fn () => $location->update(['location_code' => 'CHANGED']), 'location_code');
    }

    public function test_seeding_is_idempotent_and_does_not_overwrite_user_settings_or_create_demo_accounts(): void
    {
        Unit::where('code', 'M')->firstOrFail()->update(['name' => 'Meter edited', 'is_active' => false]);
        $this->seed(MasterDataSeeder::class);
        $this->assertDatabaseCount('units', 5);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('units', ['code' => 'M', 'name' => 'Meter edited', 'is_active' => false]);
    }

    private function save(array $overrides = [], ?Item $item = null): Item
    {
        return app(SaveItem::class)->handle([
            'part_number' => 'PN-001',
            'item_name' => 'Bearing',
            'ownership_type' => 'STOCK',
            'unit_id' => Unit::where('code', 'PC')->value('id'),
            'minimum_stock' => '25',
            ...$overrides,
        ], $item);
    }

    private function vendor(string $code = 'VENDOR'): Vendor
    {
        return Vendor::create(['vendor_code' => $code, 'vendor_name' => $code]);
    }

    private function consignment(): array
    {
        return ['ownership_type' => 'CONSIGNMENT', 'vendor_items' => [$this->link($this->vendor())]];
    }

    private function link(Vendor $vendor, ?Location $location = null): array
    {
        return ['vendor_id' => $vendor->id, 'location_id' => $location?->id ?? $this->unassignedId()];
    }

    private function unassignedId(): string
    {
        return Location::where('location_code', Location::UNASSIGNED)->value('id');
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected a validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
