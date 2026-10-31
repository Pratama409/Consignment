<?php

namespace Tests\Feature;

use App\Actions\Inventory\ApproveStockOpname;
use App\Actions\Inventory\CancelStockOpname;
use App\Actions\Inventory\CloseStockOpname;
use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\Inventory\OpnameMovementLog;
use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\PostStockOut;
use App\Actions\Inventory\RecordOpnameCount;
use App\Actions\Inventory\ReopenOpnameLine;
use App\Actions\Inventory\ReviewStockAdjustment;
use App\Actions\Inventory\SubmitOpnameCount;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockDirection;
use App\Enums\StockOpnameStatus;
use App\Enums\StockTransactionType;
use App\Models\AuditLog;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
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

class StockOpnameResolutionTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private Location $location;

    private Item $item;

    private StockBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->location = Location::firstOrFail();
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
        $this->item = $this->makeItem('RES');
        app(PostStockIn::class)->handle($this->posting($this->item, '5.0000', StockDirection::In));
        $this->bucket = $this->bucketFor($this->item, null, $this->location);
    }

    public function test_approving_a_short_line_creates_a_draft_out_adjustment(): void
    {
        $session = $this->countedSession('2');

        $approved = app(ApproveStockOpname::class)->handle($session, $this->admin);

        $adjustment = $approved->lines()->firstOrFail()->adjustment;
        $this->assertSame(StockOpnameStatus::Resolving, $approved->status);
        $this->assertSame($this->admin->id, $approved->approved_by);
        $this->assertSame(StockAdjustmentStatus::Draft, $adjustment->status);
        $this->assertSame('OUT', $adjustment->direction->value);
        $this->assertSame('3.0000', $adjustment->quantity);
        $this->assertSame('Selisih Stock Opname '.$session->session_no, $adjustment->reason);
        $this->assertSame($this->bucket->id, StockBucket::where('item_id', $adjustment->item_id)->where('location_id', $adjustment->location_id)->firstOrFail()->id);
    }

    public function test_approving_an_over_line_creates_an_in_adjustment(): void
    {
        $approved = app(ApproveStockOpname::class)->handle($this->countedSession('7'), $this->admin);

        $adjustment = $approved->lines()->firstOrFail()->adjustment;
        $this->assertSame('IN', $adjustment->direction->value);
        $this->assertSame('2.0000', $adjustment->quantity);
    }

    public function test_a_session_without_differences_completes_on_approval(): void
    {
        $approved = app(ApproveStockOpname::class)->handle($this->countedSession('5'), $this->admin);

        $this->assertSame(StockOpnameStatus::Completed, $approved->status);
        $this->assertNotNull($approved->completed_at);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_only_admin_approves_and_only_a_counted_session(): void
    {
        $session = $this->countedSession('2');

        $this->expectValidation(fn () => app(ApproveStockOpname::class)->handle($session, $this->warehouse));
        app(ApproveStockOpname::class)->handle($session, $this->admin);
        $this->expectValidation(fn () => app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin));
    }

    public function test_approval_is_all_or_nothing(): void
    {
        $second = $this->makeItem('RES2');
        app(PostStockIn::class)->handle($this->posting($second, '4.0000', StockDirection::In));
        $secondBucket = $this->bucketFor($second, null, $this->location);
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->admin);
        foreach ($session->lines as $line) {
            app(RecordOpnameCount::class)->handle($line, '1', $this->warehouse);
        }
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        $second->update(['is_active' => false]);

        $this->expectValidation(fn () => app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin));

        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertSame(StockOpnameStatus::Counted, $session->fresh()->status);
        $this->assertNull($session->lines()->where('stock_bucket_id', $secondBucket->id)->first()->stock_adjustment_id);
    }

    public function test_full_resolution_leaves_the_counted_quantity_and_unfreezes_the_bucket(): void
    {
        $session = app(ApproveStockOpname::class)->handle($this->countedSession('2'), $this->admin);
        $adjustment = $session->lines()->firstOrFail()->adjustment;

        $this->expectValidation(fn () => app(CloseStockOpname::class)->handle($session, $this->admin));
        $approved = app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Disetujui', $this->admin);
        app(PostStockAdjustment::class)->handle($approved, $this->admin, 'adjustment:'.$approved->id);
        $closed = app(CloseStockOpname::class)->handle($session->fresh(), $this->admin);

        $this->assertSame(StockOpnameStatus::Completed, $closed->status);
        $this->assertNotNull($closed->completed_at);
        $this->assertSame('2.0000', StockBalance::sole()->quantity);
        app(PostStockIn::class)->handle($this->posting($this->item, '1.0000', StockDirection::In));
        $this->assertSame('3.0000', StockBalance::sole()->quantity);
    }

    public function test_rejected_adjustment_keeps_the_bucket_frozen_and_can_be_recounted(): void
    {
        $session = app(ApproveStockOpname::class)->handle($this->countedSession('2'), $this->admin);
        $line = $session->lines()->firstOrFail();
        app(ReviewStockAdjustment::class)->handle($line->adjustment, false, 'Hitung ulang', $this->admin);

        $this->expectValidation(fn () => app(PostStockOut::class)->handle($this->posting($this->item, '1.0000', StockDirection::Out)));
        $this->expectValidation(fn () => app(ReopenOpnameLine::class)->handle($line->fresh(), $this->warehouse));

        $reopened = app(ReopenOpnameLine::class)->handle($line->fresh(), $this->admin);
        $this->assertNull($reopened->counted_quantity);
        $this->assertNull($reopened->stock_adjustment_id);
        $this->assertSame('5.0000', $reopened->system_quantity);
        $this->assertSame(StockOpnameStatus::Counting, $session->fresh()->status);
        $this->expectValidation(fn () => app(PostStockOut::class)->handle($this->posting($this->item, '1.0000', StockDirection::Out)));

        app(RecordOpnameCount::class)->handle($reopened, '4', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($session->fresh(), $this->warehouse);
        $again = app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin);
        $this->assertSame('1.0000', $again->lines()->firstOrFail()->adjustment->quantity);
    }

    public function test_reopen_only_applies_to_a_rejected_adjustment(): void
    {
        $session = app(ApproveStockOpname::class)->handle($this->countedSession('2'), $this->admin);

        $this->expectValidation(fn () => app(ReopenOpnameLine::class)->handle($session->lines()->firstOrFail(), $this->admin));
    }

    public function test_cancel_needs_a_reason_and_unfreezes(): void
    {
        $session = $this->countedSession('2');

        $this->expectValidation(fn () => app(CancelStockOpname::class)->handle($session, $this->admin, ' '));
        $this->expectValidation(fn () => app(CancelStockOpname::class)->handle($session, $this->warehouse, 'Salah cakupan'));
        $this->expectValidation(fn () => app(PostStockOut::class)->handle($this->posting($this->item, '1.0000', StockDirection::Out)));

        $cancelled = app(CancelStockOpname::class)->handle($session, $this->admin, 'Salah cakupan');
        $this->assertSame(StockOpnameStatus::Cancelled, $cancelled->status);
        $this->assertSame('Salah cakupan', AuditLog::where('action', 'stock_opname.cancelled')->firstOrFail()->reason);
        app(PostStockOut::class)->handle($this->posting($this->item, '1.0000', StockDirection::Out));
    }

    public function test_cancel_is_refused_once_resolving(): void
    {
        $session = app(ApproveStockOpname::class)->handle($this->countedSession('2'), $this->admin);

        $this->expectValidation(fn () => app(CancelStockOpname::class)->handle($session, $this->admin, 'Terlambat'));
    }

    public function test_movement_log_lists_postings_after_the_count_but_not_the_sessions_own_adjustment(): void
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL'], $this->admin);
        $line = $session->lines()->firstOrFail();
        app(RecordOpnameCount::class)->handle($line, '5', $this->warehouse);
        $this->travel(5)->minutes();
        $in = app(PostStockIn::class)->handle($this->posting($this->item, '1.0000', StockDirection::In));
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);

        $approved = app(ApproveStockOpname::class)->handle($session->fresh(), $this->admin);
        $adjustment = $approved->lines()->firstOrFail()->adjustment;
        app(PostStockAdjustment::class)->handle(app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Disetujui', $this->admin), $this->admin, 'adjustment:'.$adjustment->id);

        $log = app(OpnameMovementLog::class)->forLine($line->fresh());
        $this->assertCount(1, $log);
        $this->assertSame($in->transaction_no, $log->first()['transaction_no']);
        $this->assertSame('1.0000', $log->first()['quantity']);
        $this->assertSame('MANUAL_RECEIPT', $log->first()['type']);
    }

    private function countedSession(string $counted): StockOpnameSession
    {
        $session = app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [$this->bucket->id]], $this->admin);
        app(RecordOpnameCount::class)->handle($session->lines()->firstOrFail(), $counted, $this->warehouse);

        return app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
    }

    private function makeItem(string $prefix): Item
    {
        $item = Item::create(['item_code' => $prefix.'-'.Str::random(6), 'part_number' => 'PN-'.$prefix, 'item_name' => 'Resolution item '.$prefix, 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $item->id, 'location_id' => $this->location->id]);

        return $item;
    }

    private function posting(Item $item, string $quantity, StockDirection $direction): StockPostingData
    {
        return new StockPostingData(StockTransactionType::ManualReceipt, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($item->id, null, $this->location->id, $direction, $quantity),
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
