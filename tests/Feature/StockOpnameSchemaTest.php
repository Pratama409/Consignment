<?php

namespace Tests\Feature;

use App\Enums\StockOpnameScope;
use App\Enums\StockOpnameStatus;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\StockOpnameLine;
use App\Models\StockOpnameSession;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class StockOpnameSchemaTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private User $admin;

    private StockBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $item = Item::create(['item_code' => 'SO-'.Str::random(8), 'part_number' => 'PN-SO', 'item_name' => 'Opname item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        $this->bucket = $this->bucketFor($item, null, Location::firstOrFail());
    }

    public function test_a_bucket_cannot_appear_twice_in_one_session(): void
    {
        $session = $this->makeSession('SO-20261031-001');
        $session->lines()->create(['stock_bucket_id' => $this->bucket->id]);

        $this->expectException(QueryException::class);
        $session->lines()->create(['stock_bucket_id' => $this->bucket->id]);
    }

    public function test_session_number_is_unique(): void
    {
        $this->makeSession('SO-20261031-001');

        $this->expectException(QueryException::class);
        $this->makeSession('SO-20261031-001');
    }

    public function test_short_difference_and_state(): void
    {
        $line = $this->line('200.0000', '300.0000');

        $this->assertSame('-100.0000', $line->difference());
        $this->assertSame('SHORT', $line->state());
    }

    public function test_over_difference_and_state(): void
    {
        $line = $this->line('5.0000', '3.0000');

        $this->assertSame('2.0000', $line->difference());
        $this->assertSame('OVER', $line->state());
    }

    public function test_equal_quantities_are_balance(): void
    {
        $line = $this->line('7.0000', '7.0000');

        $this->assertSame('0.0000', $line->difference());
        $this->assertSame('BALANCE', $line->state());
    }

    public function test_difference_is_null_until_counted_and_snapshotted(): void
    {
        $this->assertNull($this->line(null, null)->difference());
        $this->assertNull($this->line('4.0000', null)->state());
        $this->assertNull($this->line(null, '4.0000')->state());
    }

    public function test_open_statuses_are_counting_counted_and_resolving(): void
    {
        $open = collect(StockOpnameStatus::cases())->filter->isOpen()->map->value->values()->all();

        $this->assertSame(['COUNTING', 'COUNTED', 'RESOLVING'], $open);
    }

    private function makeSession(string $number): StockOpnameSession
    {
        return StockOpnameSession::create(['session_no' => $number, 'scope_type' => StockOpnameScope::Full, 'status' => StockOpnameStatus::Counting, 'created_by' => $this->admin->id]);
    }

    private function line(?string $counted, ?string $system): StockOpnameLine
    {
        $line = $this->makeSession('SO-'.Str::random(10))->lines()->create(['stock_bucket_id' => $this->bucket->id, 'counted_quantity' => $counted, 'system_quantity' => $system]);

        return $line->fresh();
    }
}
