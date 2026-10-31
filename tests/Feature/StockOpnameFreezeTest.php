<?php

namespace Tests\Feature;

use App\Actions\Inventory\CreateStockAdjustment;
use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\PostStockOut;
use App\Actions\Inventory\ReverseStockTransaction;
use App\Actions\Inventory\ReviewStockAdjustment;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockOpnameScope;
use App\Enums\StockOpnameStatus;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\StockOpnameLine;
use App\Models\StockOpnameSession;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class StockOpnameFreezeTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private User $admin;

    private Item $item;

    private Location $location;

    private StockBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->location = Location::firstOrFail();
        $this->item = Item::create(['item_code' => 'FRZ-'.Str::random(8), 'part_number' => 'PN-FRZ', 'item_name' => 'Freeze item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $this->item->id, 'location_id' => $this->location->id]);
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
        app(PostStockIn::class)->handle($this->posting('5.0000', StockDirection::In));
        $this->bucket = $this->bucketFor($this->item, null, $this->location);
    }

    public function test_short_line_blocks_stock_in_and_out_on_its_bucket(): void
    {
        $this->openLine('2.0000', '5.0000');

        $this->expectValidation(fn () => app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out)));
        $this->expectValidation(fn () => app(PostStockIn::class)->handle($this->posting('1.0000', StockDirection::In)));
        $this->assertSame('5.0000', StockBalance::sole()->quantity);
    }

    public function test_balance_line_and_line_without_system_snapshot_do_not_freeze(): void
    {
        $session = $this->makeSession();
        $line = $session->lines()->create(['stock_bucket_id' => $this->bucket->id, 'counted_quantity' => '5.0000', 'system_quantity' => '5.0000']);
        app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out));

        $line->update(['counted_quantity' => '1.0000', 'system_quantity' => null]);
        app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out));

        $this->assertSame('3.0000', StockBalance::sole()->quantity);
    }

    public function test_reopened_line_with_snapshot_but_no_count_stays_frozen(): void
    {
        $line = $this->openLine('2.0000', '5.0000');
        $line->update(['counted_quantity' => null, 'counted_at' => null]);

        $this->expectValidation(fn () => app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out)));
    }

    public function test_only_the_lines_own_adjustment_may_post_to_a_frozen_bucket(): void
    {
        $line = $this->openLine('2.0000', '5.0000');
        $own = $this->approvedAdjustment('3.0000');
        $other = $this->approvedAdjustment('1.0000');
        $line->update(['stock_adjustment_id' => $own->id]);

        $this->expectValidation(fn () => app(PostStockAdjustment::class)->handle($other, $this->admin, 'adjustment:'.$other->id));
        app(PostStockAdjustment::class)->handle($own, $this->admin, 'adjustment:'.$own->id);

        $this->assertSame('2.0000', StockBalance::sole()->quantity);
    }

    public function test_bucket_is_unfrozen_once_its_adjustment_is_posted(): void
    {
        $line = $this->openLine('2.0000', '5.0000');
        $own = $this->approvedAdjustment('3.0000');
        $line->update(['stock_adjustment_id' => $own->id]);
        app(PostStockAdjustment::class)->handle($own, $this->admin, 'adjustment:'.$own->id);

        app(PostStockIn::class)->handle($this->posting('1.0000', StockDirection::In));

        $this->assertSame('3.0000', StockBalance::sole()->quantity);
    }

    public function test_reversal_on_a_frozen_bucket_is_refused(): void
    {
        $out = app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out, StockTransactionType::MaterialIssue));
        $this->openLine('2.0000', '4.0000');

        $this->expectValidation(fn () => app(ReverseStockTransaction::class)->handle($out, $this->admin, 'Salah input', 'reverse:frozen'));
        $this->assertSame('4.0000', StockBalance::sole()->quantity);
    }

    public function test_completed_and_cancelled_sessions_do_not_freeze(): void
    {
        foreach ([StockOpnameStatus::Completed, StockOpnameStatus::Cancelled] as $status) {
            $line = $this->openLine('2.0000', StockBalance::sole()->quantity);
            $line->session->update(['status' => $status]);
            app(PostStockIn::class)->handle($this->posting('1.0000', StockDirection::In));
        }

        $this->assertSame('7.0000', StockBalance::sole()->quantity);
    }

    public function test_bucket_in_two_open_sessions_stays_frozen_until_both_clear(): void
    {
        $first = $this->openLine('2.0000', '5.0000');
        $second = $this->openLine('3.0000', '5.0000');

        $first->session->update(['status' => StockOpnameStatus::Cancelled]);
        $this->expectValidation(fn () => app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out)));

        $second->session->update(['status' => StockOpnameStatus::Cancelled]);
        app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out));
        $this->assertSame('4.0000', StockBalance::sole()->quantity);
    }

    private function makeSession(): StockOpnameSession
    {
        return StockOpnameSession::create(['session_no' => 'SO-'.Str::random(10), 'scope_type' => StockOpnameScope::Full, 'status' => StockOpnameStatus::Counted, 'created_by' => $this->admin->id]);
    }

    private function openLine(string $counted, string $system): StockOpnameLine
    {
        return $this->makeSession()->lines()->create(['stock_bucket_id' => $this->bucket->id, 'counted_quantity' => $counted, 'system_quantity' => $system]);
    }

    private function approvedAdjustment(string $quantity): StockAdjustment
    {
        $draft = app(CreateStockAdjustment::class)->handle(['item_id' => $this->item->id, 'vendor_item_id' => null, 'location_id' => $this->location->id, 'direction' => 'OUT', 'quantity' => $quantity, 'reason' => 'Selisih Stock Opname'], $this->admin);

        return app(ReviewStockAdjustment::class)->handle($draft, true, 'Disetujui', $this->admin);
    }

    private function posting(string $quantity, StockDirection $direction, StockTransactionType $type = StockTransactionType::ManualReceipt): StockPostingData
    {
        return new StockPostingData($type, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($this->item->id, null, $this->location->id, $direction, $quantity),
        ]);
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
