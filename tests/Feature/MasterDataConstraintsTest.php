<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Models\Location;
use App\Models\Unit;
use App\Models\Vendor;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    public static function uniqueCodes(): array
    {
        return [
            'unit' => ['units', ['code' => 'DUP', 'name' => 'Name']],
            'vendor' => ['vendors', ['vendor_code' => 'DUP', 'vendor_name' => 'Name']],
            'location' => ['locations', ['location_code' => 'DUP']],
        ];
    }

    #[DataProvider('uniqueCodes')]
    public function test_master_codes_remain_unique_when_records_are_inactive(string $table, array $attributes): void
    {
        DB::table($table)->insert([...$attributes, 'is_active' => false]);
        $this->expectException(QueryException::class);
        DB::table($table)->insert([...$attributes, 'is_active' => true]);
    }

    public function test_item_codes_are_unique_at_the_database_level(): void
    {
        $attributes = $this->itemAttributes();
        DB::table('items')->insert($attributes);
        $this->expectException(QueryException::class);
        DB::table('items')->insert($attributes);
    }

    public function test_database_rejects_negative_minimum_on_insert(): void
    {
        $this->expectException(QueryException::class);
        DB::table('items')->insert([...$this->itemAttributes(), 'minimum_stock' => '-0.0001']);
    }

    public function test_database_rejects_negative_minimum_on_update(): void
    {
        $id = $this->insertItem();
        $this->expectException(QueryException::class);
        DB::table('items')->where('id', $id)->update(['minimum_stock' => '-1']);
    }

    public function test_ownership_is_immutable_even_when_model_events_are_bypassed(): void
    {
        $id = $this->insertItem();
        $this->expectException(QueryException::class);
        DB::table('items')->where('id', $id)->update(['ownership_type' => 'CONSIGNMENT']);
    }

    public function test_invalid_ownership_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('items')->insert([...$this->itemAttributes(), 'ownership_type' => 'OTHER']);
    }

    public function test_invalid_tracking_type_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('items')->insert([...$this->itemAttributes(), 'tracking_type' => 'OTHER']);
    }

    public function test_foreign_keys_reject_missing_references(): void
    {
        $this->expectException(QueryException::class);
        DB::table('items')->insert([...$this->itemAttributes(), 'unit_id' => 999999]);
    }

    public function test_referenced_unit_cannot_be_deleted_even_through_direct_sql(): void
    {
        DB::table('items')->insert($this->itemAttributes());
        $this->expectException(QueryException::class);
        DB::table('units')->where('code', 'PC')->delete();
    }

    public function test_stock_cannot_be_linked_to_a_vendor_even_through_direct_sql(): void
    {
        $id = $this->insertItem();
        $vendor = Vendor::create(['vendor_code' => 'V', 'vendor_name' => 'Vendor']);
        $this->expectException(QueryException::class);
        DB::table('vendor_items')->insert(['item_id' => $id, 'vendor_id' => $vendor->id, 'location_id' => $this->locationId()]);
    }

    public function test_consignment_cannot_have_stock_placements_even_through_direct_sql(): void
    {
        $id = $this->insertItem(['ownership_type' => 'CONSIGNMENT']);
        $this->expectException(QueryException::class);
        DB::table('stock_item_locations')->insert(['item_id' => $id, 'location_id' => $this->locationId()]);
    }

    public function test_vendor_item_pair_is_unique_even_when_the_old_pair_is_inactive(): void
    {
        $first = Vendor::create(['vendor_code' => 'A', 'vendor_name' => 'A']);
        $second = Vendor::create(['vendor_code' => 'B', 'vendor_name' => 'B']);
        $item = app(SaveItem::class)->handle([
            'part_number' => 'PN', 'item_name' => 'Name', 'ownership_type' => 'CONSIGNMENT',
            'unit_id' => $this->unitId(), 'minimum_stock' => 0,
            'vendor_items' => [
                ['vendor_id' => $first->id, 'location_id' => $this->locationId()],
                ['vendor_id' => $second->id, 'location_id' => $this->locationId()],
            ],
        ]);
        $relation = $item->vendorItems->firstWhere('vendor_id', $first->id);
        $relation->update(['is_active' => false]);
        $this->expectException(QueryException::class);
        DB::table('vendor_items')->insert(['item_id' => $item->id, 'vendor_id' => $first->id, 'location_id' => $this->locationId()]);
    }

    public function test_stock_placement_pair_is_unique(): void
    {
        $id = $this->insertItem();
        $placement = ['item_id' => $id, 'location_id' => $this->locationId()];
        DB::table('stock_item_locations')->insert($placement);
        $this->expectException(QueryException::class);
        DB::table('stock_item_locations')->insert($placement);
    }

    public function test_referenced_vendor_item_parents_cannot_be_deleted(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'A', 'vendor_name' => 'A']);
        app(SaveItem::class)->handle([
            'part_number' => 'PN', 'item_name' => 'Name', 'ownership_type' => 'CONSIGNMENT',
            'unit_id' => $this->unitId(), 'minimum_stock' => 0,
            'vendor_items' => [['vendor_id' => $vendor->id, 'location_id' => $this->locationId()]],
        ]);
        $this->expectException(QueryException::class);
        DB::table('vendors')->where('id', $vendor->id)->delete();
    }

    private function itemAttributes(): array
    {
        return [
            'item_code' => 'SQL-001', 'part_number' => 'PN-001', 'item_name' => 'Name',
            'ownership_type' => 'STOCK', 'unit_id' => $this->unitId(), 'minimum_stock' => '0',
        ];
    }

    private function insertItem(array $changes = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('items')->insert([...$this->itemAttributes(), ...$changes, 'id' => $id]);

        return $id;
    }

    private function unitId(): string
    {
        return Unit::where('code', 'PC')->value('id');
    }

    private function locationId(): string
    {
        return Location::where('location_code', Location::UNASSIGNED)->value('id');
    }
}
