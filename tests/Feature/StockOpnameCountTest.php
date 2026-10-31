<?php

namespace Tests\Feature;

use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\RecordOpnameCount;
use App\Actions\Inventory\SubmitOpnameCount;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockOpnameStatus;
use App\Enums\StockTransactionType;
use App\Models\AuditLog;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\StockOpnameLine;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class StockOpnameCountTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private Location $location;

    private Item $stocked;

    private Item $empty;

    private StockBucket $stockedBucket;

    private StockBucket $emptyBucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->location = Location::firstOrFail();
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
        $this->stocked = $this->makeItem('CNT-A');
        $this->empty = $this->makeItem('CNT-B');
        app(PostStockIn::class)->handle($this->posting($this->stocked, '5.0000'));
        $this->stockedBucket = $this->bucketFor($this->stocked, null, $this->location);
        $this->emptyBucket = $this->bucketFor($this->empty, null, $this->location);
    }

    public function test_admin_creates_a_full_session_with_only_buckets_that_hold_stock(): void
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL', 'note' => 'Hitung akhir bulan'], $this->admin);

        $this->assertSame(StockOpnameStatus::Counting, $session->status);
        $this->assertMatchesRegularExpression('/^SO-20261029-001$/', $session->session_no);
        $this->assertSame([$this->stockedBucket->id], $session->lines()->pluck('stock_bucket_id')->all());
        $this->assertTrue(app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->admin)->session_no === 'SO-20261029-002');
    }

    public function test_partial_session_uses_only_the_chosen_buckets(): void
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$this->emptyBucket->id]], $this->admin);

        $this->assertSame([$this->emptyBucket->id], $session->lines()->pluck('stock_bucket_id')->all());
    }

    public function test_session_creation_rules(): void
    {
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->warehouse));
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'CYCLE', 'bucket_ids' => [$this->stockedBucket->id]], $this->admin));
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'CYCLE', 'cycle_month' => '2026-13', 'bucket_ids' => [$this->stockedBucket->id]], $this->admin));
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => []], $this->admin));
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [(string) Str::uuid()]], $this->admin));
        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'WEIRD'], $this->admin));
        $cycle = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'CYCLE', 'cycle_month' => '2026-10', 'bucket_ids' => [$this->stockedBucket->id]], $this->admin);
        $this->assertSame('2026-10', $cycle->cycle_month);
    }

    public function test_full_session_with_no_stock_anywhere_is_rejected(): void
    {
        StockBucket::query()->whereKey($this->stockedBucket->id)->first()->balance()->update(['quantity' => '0.0000']);

        $this->expectValidation(fn () => app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->admin));
    }

    public function test_only_warehouse_records_counts(): void
    {
        $line = $this->openSessionLine();
        $vendor = User::factory()->vendor(Vendor::create(['vendor_code' => 'CNT-V', 'vendor_name' => 'Count Vendor']))->create();

        $this->expectValidation(fn () => app(RecordOpnameCount::class)->handle($line, '4.0000', $this->admin));
        $this->expectValidation(fn () => app(RecordOpnameCount::class)->handle($line, '4.0000', $vendor));
        $recorded = app(RecordOpnameCount::class)->handle($line, '4', $this->warehouse);

        $this->assertSame('4.0000', $recorded->counted_quantity);
        $this->assertSame($this->warehouse->id, $recorded->counted_by);
        $this->assertNotNull($recorded->counted_at);
        $this->assertNull($recorded->system_quantity);
    }

    public function test_count_quantity_is_validated(): void
    {
        $line = $this->openSessionLine();

        foreach (['-1', '1.23456', 'abc', '', '1e3'] as $bad) {
            $this->expectValidation(fn () => app(RecordOpnameCount::class)->handle($line, $bad, $this->warehouse));
        }
        $this->assertSame('0.0000', app(RecordOpnameCount::class)->handle($line, '0', $this->warehouse)->counted_quantity);
    }

    public function test_counts_cannot_change_after_submit(): void
    {
        $line = $this->openSessionLine();
        app(RecordOpnameCount::class)->handle($line, '4', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($line->session, $this->warehouse);

        $this->expectValidation(fn () => app(RecordOpnameCount::class)->handle($line->fresh(), '3', $this->warehouse));
    }

    public function test_submit_needs_every_line_counted_and_snapshots_the_balance(): void
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$this->stockedBucket->id, $this->emptyBucket->id]], $this->admin);
        $stockedLine = $session->lines()->where('stock_bucket_id', $this->stockedBucket->id)->first();
        $emptyLine = $session->lines()->where('stock_bucket_id', $this->emptyBucket->id)->first();
        app(RecordOpnameCount::class)->handle($stockedLine, '4', $this->warehouse);

        $this->expectValidation(fn () => app(SubmitOpnameCount::class)->handle($session, $this->warehouse));
        app(RecordOpnameCount::class)->handle($emptyLine, '0', $this->warehouse);
        $this->expectValidation(fn () => app(SubmitOpnameCount::class)->handle($session, $this->admin));
        $submitted = app(SubmitOpnameCount::class)->handle($session, $this->warehouse);

        $this->assertSame(StockOpnameStatus::Counted, $submitted->status);
        $this->assertSame($this->warehouse->id, $submitted->counted_by);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertSame('5.0000', $stockedLine->fresh()->system_quantity);
        $this->assertSame('0.0000', $emptyLine->fresh()->system_quantity);
    }

    public function test_a_later_posting_does_not_change_the_snapshot(): void
    {
        $line = $this->openSessionLine();
        app(RecordOpnameCount::class)->handle($line, '5', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($line->session, $this->warehouse);
        $line->update(['counted_quantity' => '5.0000']);
        app(PostStockIn::class)->handle($this->posting($this->stocked, '2.0000'));

        $this->assertSame('5.0000', $line->fresh()->system_quantity);
    }

    public function test_every_step_writes_an_audit_log(): void
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->admin);
        app(RecordOpnameCount::class)->handle($session->lines()->first(), '5', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);

        foreach (['stock_opname.created', 'stock_opname.counted', 'stock_opname.submitted'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), "Missing audit {$action}.");
        }
    }

    private function openSessionLine(): StockOpnameLine
    {
        return app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$this->stockedBucket->id]], $this->admin)->lines()->firstOrFail();
    }

    private function makeItem(string $prefix): Item
    {
        $item = Item::create(['item_code' => $prefix.'-'.Str::random(6), 'part_number' => 'PN-'.$prefix, 'item_name' => 'Count item '.$prefix, 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $item->id, 'location_id' => $this->location->id]);

        return $item;
    }

    private function posting(Item $item, string $quantity): StockPostingData
    {
        return new StockPostingData(StockTransactionType::ManualReceipt, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($item->id, null, $this->location->id, StockDirection::In, $quantity),
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
