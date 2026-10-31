<?php

namespace Tests\Feature;

use App\Actions\Inventory\ApproveStockOpname;
use App\Actions\Inventory\CancelStockOpname;
use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\Inventory\OpnameMovementLog;
use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\PostStockOut;
use App\Actions\Inventory\RecordOpnameCount;
use App\Actions\Inventory\ReopenOpnameLine;
use App\Actions\Inventory\ReverseStockTransaction;
use App\Actions\Inventory\ReviewStockAdjustment;
use App\Actions\Inventory\SubmitOpnameCount;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

/** Regressions for the whole-branch review of Stock Opname. */
class StockOpnameReviewFixesTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private const SYSTEM = '417.2468';

    private User $admin;

    private User $warehouse;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->location = Location::firstOrFail();
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
    }

    public function test_a_bucket_cannot_be_in_two_open_sessions(): void
    {
        $bucket = $this->stockedBucket('5.0000');
        $first = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$bucket->id]], $this->admin);

        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$bucket->id]], $this->admin));
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->admin));

        $other = $this->stockedBucket('3.0000');
        $this->assertNotNull(app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$other->id]], $this->admin));
        app(CancelStockOpname::class)->handle($first, $this->admin, 'Salah cakupan');
        $this->assertNotNull(app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$bucket->id]], $this->admin));
    }

    public function test_a_submitted_count_cannot_be_changed_after_another_line_is_reopened(): void
    {
        $x = $this->stockedBucket('5.0000');
        $y = $this->stockedBucket('4.0000');
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$x->id, $y->id]], $this->admin);
        $lineX = $session->lines()->where('stock_bucket_id', $x->id)->firstOrFail();
        $lineY = $session->lines()->where('stock_bucket_id', $y->id)->firstOrFail();
        app(RecordOpnameCount::class)->handle($lineX, '2', $this->warehouse);
        app(RecordOpnameCount::class)->handle($lineY, '4', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin);
        app(ReviewStockAdjustment::class)->handle($lineX->fresh()->adjustment, false, 'Hitung ulang', $this->admin);
        app(ReopenOpnameLine::class)->handle($lineX->fresh(), $this->admin);

        $this->expectValidation(fn () => app(RecordOpnameCount::class)->handle($lineY->fresh(), '1', $this->warehouse));
        $this->actingAs($this->warehouse)->get(route('stock-opname.show', $session))->assertInertia(fn (Assert $page) => $page
            ->where('lines', fn ($lines) => collect($lines)->every(fn ($line) => $line['can_count'] === ($line['id'] === $lineX->id))));
        $this->assertSame('2.0000', app(RecordOpnameCount::class)->handle($lineX->fresh(), '2', $this->warehouse)->counted_quantity);
        $this->assertSame('4.0000', $lineY->fresh()->counted_quantity);
    }

    public function test_resaving_the_same_count_keeps_movements_after_the_first_count_in_the_log(): void
    {
        $bucket = $this->stockedBucket('5.0000');
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$bucket->id]], $this->admin);
        $line = $session->lines()->firstOrFail();
        app(RecordOpnameCount::class)->handle($line, '2', $this->warehouse);
        $this->travel(5)->minutes();
        app(PostStockOut::class)->handle($this->posting($bucket, '3.0000', StockDirection::Out));
        $this->travel(5)->minutes();
        app(RecordOpnameCount::class)->handle($line->fresh(), '2', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);

        $this->assertCount(1, app(OpnameMovementLog::class)->forLine($line->fresh()));
    }

    public function test_cancel_is_refused_once_any_line_has_a_correction(): void
    {
        $x = $this->stockedBucket('5.0000');
        $y = $this->stockedBucket('4.0000');
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$x->id, $y->id]], $this->admin);
        foreach ($session->lines as $line) {
            app(RecordOpnameCount::class)->handle($line, '1', $this->warehouse);
        }
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin);
        $lineX = $session->lines()->where('stock_bucket_id', $x->id)->firstOrFail();
        app(ReviewStockAdjustment::class)->handle($lineX->adjustment, false, 'Hitung ulang', $this->admin);
        app(ReopenOpnameLine::class)->handle($lineX->fresh(), $this->admin);

        $this->expectValidation(fn () => app(CancelStockOpname::class)->handle($session->fresh(), $this->admin, 'Batalkan'));
    }

    public function test_the_sessions_posted_correction_cannot_be_reversed_while_it_is_open(): void
    {
        $bucket = $this->stockedBucket('5.0000');
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$bucket->id]], $this->admin);
        app(RecordOpnameCount::class)->handle($session->lines()->firstOrFail(), '2', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin);
        $adjustment = $session->lines()->firstOrFail()->adjustment;
        $approved = app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Disetujui', $this->admin);
        $transaction = app(PostStockAdjustment::class)->handle($approved, $this->admin, 'adjustment:'.$approved->id);

        $this->expectValidation(fn () => app(ReverseStockTransaction::class)->handle($transaction, $this->admin, 'Salah', 'reverse:opname'));
        $this->assertSame('2.0000', StockBalance::where('stock_bucket_id', $bucket->id)->sole()->quantity);
    }

    public function test_warehouse_cannot_read_system_quantity_of_a_bucket_being_counted(): void
    {
        $bucket = $this->stockedBucket(self::SYSTEM);
        $other = $this->stockedBucket('9.0000');
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$bucket->id]], $this->admin);
        $balance = StockBalance::where('stock_bucket_id', $bucket->id)->firstOrFail();

        $index = $this->actingAs($this->warehouse)->get(route('monitoring.system-stock.index'));
        $index->assertOk();
        $this->assertStringNotContainsString(self::SYSTEM, $index->getContent());
        $this->assertStringContainsString('9.0000', $index->getContent());
        $this->actingAs($this->warehouse)->get(route('monitoring.system-stock.show', $balance))->assertForbidden();
        $this->actingAs($this->warehouse)->get(route('monitoring.system-stock.show', StockBalance::where('stock_bucket_id', $other->id)->firstOrFail()))->assertOk();

        $this->assertStringContainsString(self::SYSTEM, $this->actingAs($this->admin)->get(route('monitoring.system-stock.index'))->getContent());
        $this->actingAs($this->admin)->get(route('monitoring.system-stock.show', $balance))->assertOk();
        app(CancelStockOpname::class)->handle($session, $this->admin, 'Selesai');
        $this->actingAs($this->warehouse)->get(route('monitoring.system-stock.show', $balance))->assertOk();
    }

    public function test_opname_screens_do_not_scale_queries_with_the_number_of_lines(): void
    {
        $buckets = [$this->stockedBucket('5.0000')->id, $this->stockedBucket('5.0000')->id];
        $small = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => $buckets], $this->admin);
        $many = [];
        for ($i = 0; $i < 10; $i++) {
            $many[] = $this->stockedBucket('5.0000')->id;
        }
        $large = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => $many], $this->admin);
        foreach ([$small, $large] as $session) {
            foreach ($session->lines as $line) {
                app(RecordOpnameCount::class)->handle($line, '1', $this->warehouse);
            }
            app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        }

        $counts = [];
        foreach ([$small, $large] as $session) {
            foreach (['stock-opname.show', 'stock-opname.report'] as $route) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->actingAs($this->admin)->get(route($route, $session))->assertOk();
                $counts[$route][] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        }

        $this->assertLessThanOrEqual($counts['stock-opname.show'][0] + 2, $counts['stock-opname.show'][1], 'Show queries grow with the number of lines.');
        $this->assertLessThanOrEqual($counts['stock-opname.report'][0] + 2, $counts['stock-opname.report'][1], 'Report queries grow with the number of lines.');
    }

    private function stockedBucket(string $quantity): StockBucket
    {
        $item = Item::create(['item_code' => 'RVW-'.Str::random(8), 'part_number' => 'PN-RVW', 'item_name' => 'Review item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $item->id, 'location_id' => $this->location->id]);
        $bucket = $this->bucketFor($item, null, $this->location);
        app(PostStockIn::class)->handle($this->posting($bucket, $quantity, StockDirection::In));

        return $bucket;
    }

    private function posting(StockBucket $bucket, string $quantity, StockDirection $direction): StockPostingData
    {
        return new StockPostingData(StockTransactionType::ManualReceipt, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($bucket->item_id, null, $bucket->location_id, $direction, $quantity),
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
