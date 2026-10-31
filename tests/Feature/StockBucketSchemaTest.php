<?php

namespace Tests\Feature;

use App\Actions\Inventory\ResolveStockBucket;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\VendorItem;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class StockBucketSchemaTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private Item $stockItem;

    private Item $consignmentItem;

    private Location $location;

    private VendorItem $vendorItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->location = Location::firstOrFail();
        $this->stockItem = $this->makeItem('STOCK');
        $this->consignmentItem = $this->makeItem('CONSIGNMENT');
        $vendor = Vendor::create(['vendor_code' => 'BUCKET', 'vendor_name' => 'Bucket Vendor']);
        $this->vendorItem = VendorItem::create(['item_id' => $this->consignmentItem->id, 'vendor_id' => $vendor->id, 'location_id' => $this->location->id]);
        StockItemLocation::create(['item_id' => $this->stockItem->id, 'location_id' => $this->location->id]);
    }

    private function makeItem(string $ownership): Item
    {
        return Item::create(['item_code' => 'IT-'.Str::random(12), 'part_number' => 'PN-'.Str::random(12), 'item_name' => $ownership, 'ownership_type' => $ownership, 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
    }

    private function insertBucket(array $attributes): void
    {
        DB::table('stock_buckets')->insert(['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(), ...$attributes]);
    }

    private function assertRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected database integrity protection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_bucket_requires_exactly_one_of_item_or_vendor_item(): void
    {
        $this->assertRejects(fn () => $this->insertBucket(['item_id' => null, 'vendor_item_id' => null, 'location_id' => $this->location->id]));
        $this->assertRejects(fn () => $this->insertBucket(['item_id' => $this->stockItem->id, 'vendor_item_id' => $this->vendorItem->id, 'location_id' => $this->location->id]));
    }

    public function test_stock_bucket_item_must_be_stock_ownership(): void
    {
        $this->assertRejects(fn () => $this->insertBucket(['item_id' => $this->consignmentItem->id, 'vendor_item_id' => null, 'location_id' => $this->location->id]));
    }

    public function test_vendor_item_identity_cannot_change_through_sql_but_location_can(): void
    {
        $other = Vendor::create(['vendor_code' => 'ID-OTHER', 'vendor_name' => 'Other']);
        $otherItem = $this->makeItem('CONSIGNMENT');
        $moved = Location::create(['location_code' => 'ID-MOVED', 'location_name' => 'Moved']);

        $this->assertRejects(fn () => DB::table('vendor_items')->where('id', $this->vendorItem->id)->update(['vendor_id' => $other->id]));
        $this->assertRejects(fn () => DB::table('vendor_items')->where('id', $this->vendorItem->id)->update(['item_id' => $otherItem->id]));
        DB::table('vendor_items')->where('id', $this->vendorItem->id)->update(['location_id' => $moved->id]);
        $this->assertSame($moved->id, $this->vendorItem->fresh()->location_id);
    }

    public function test_duplicate_item_location_rejected(): void
    {
        $this->bucketFor($this->stockItem, null, $this->location);
        $this->assertRejects(fn () => $this->insertBucket(['item_id' => $this->stockItem->id, 'vendor_item_id' => null, 'location_id' => $this->location->id]));
    }

    public function test_duplicate_vendor_item_location_rejected(): void
    {
        $this->bucketFor($this->consignmentItem, $this->vendorItem, $this->location);
        $this->assertRejects(fn () => $this->insertBucket(['item_id' => null, 'vendor_item_id' => $this->vendorItem->id, 'location_id' => $this->location->id]));
    }

    public function test_bucket_identity_update_and_delete_rejected(): void
    {
        $bucket = $this->bucketFor($this->stockItem, null, $this->location);
        $other = Location::create(['location_code' => 'OTHER', 'location_name' => 'Other']);
        $this->assertRejects(fn () => DB::table('stock_buckets')->where('id', $bucket->id)->update(['location_id' => $other->id]));
        $this->assertRejects(fn () => DB::table('stock_buckets')->where('id', $bucket->id)->delete());
    }

    public function test_resolver_creates_once_and_returns_same_row(): void
    {
        $resolver = app(ResolveStockBucket::class);
        $first = $resolver->handle($this->stockItem, null, $this->location);
        $second = $resolver->handle($this->stockItem, null, $this->location);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, StockBucket::count());
        $this->assertSame($this->stockItem->id, $first->resolvedItem()->id);
        $this->assertSame($this->consignmentItem->id, $resolver->handle($this->consignmentItem, $this->vendorItem, $this->location)->resolvedItem()->id);
    }

    public function test_resolver_rejects_inactive_item_and_foreign_location(): void
    {
        $other = Location::create(['location_code' => 'ELSEWHERE', 'location_name' => 'Elsewhere']);
        $resolver = app(ResolveStockBucket::class);

        $this->assertValidation(fn () => $resolver->handle($this->consignmentItem, $this->vendorItem, $other));
        $this->assertValidation(fn () => $resolver->handle($this->stockItem, null, $other));
        $this->stockItem->forceFill(['is_active' => false])->save();
        $this->assertValidation(fn () => $resolver->handle($this->stockItem, null, $this->location));
        $this->assertSame(0, StockBucket::count());
    }

    private function assertValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }
    }
}
