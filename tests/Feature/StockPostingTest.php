<?php

namespace Tests\Feature;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Inventory\BuildStockBucket;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\PostStockOut;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\AuditLog;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockItemLocation;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class StockPostingTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected Item $item;

    protected Location $location;

    protected InventoryPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->actor = User::factory()->admin()->create();
        $this->location = Location::firstOrFail();
        $this->item = $this->makeItem();
        StockItemLocation::create(['item_id' => $this->item->id, 'location_id' => $this->location->id]);
        $this->period = InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
    }

    protected function makeItem(string $ownership = 'STOCK'): Item
    {
        return Item::create(['item_code' => 'IT-'.Str::random(12), 'part_number' => 'PN-'.Str::random(12), 'item_name' => $ownership, 'ownership_type' => $ownership, 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
    }

    protected function line(string $quantity = '1.0000', StockDirection $direction = StockDirection::In, ?VendorItem $vendorItem = null): StockPostingLineData
    {
        return new StockPostingLineData($vendorItem?->item_id ?? $this->item->id, $vendorItem?->id, $this->location->id, $direction, $quantity);
    }

    protected function data(array $lines, ?string $key = null, ?string $source = null, StockTransactionType $type = StockTransactionType::ManualReceipt): StockPostingData
    {
        return new StockPostingData($type, 'receipt', $source ?? (string) Str::uuid(), CarbonImmutable::parse('2026-10-27 15:00:00', 'UTC'), $this->actor->id, $key ?? (string) Str::uuid(), 'Posting test', $lines);
    }

    protected function reject(callable $post): void
    {
        try {
            $post();
            $this->fail('Expected controlled stock validation failure.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_in_creates_immutable_positive_ledger_balance_lot_and_audit_with_posting_shift(): void
    {
        $transaction = app(PostStockIn::class)->handle($this->data([$this->line('12.3456')]));
        $entry = $transaction->ledgerEntries()->sole();
        $this->assertSame('12.3456', $entry->quantity);
        $this->assertSame('12.3456', StockBalance::sole()->quantity);
        $this->assertSame('12.3456', StockLot::sole()->remaining_quantity);
        $this->assertSame('12.3456', StockLot::sole()->original_quantity);
        $this->assertSame('2026-10-29 03:00:00', $transaction->posted_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 15:00:00', $transaction->physical_occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('DAY', $transaction->operationalShift->code);
        $this->assertSame('2026-10-29', $transaction->operationalShift->operational_date->toDateString());
        $this->assertSame($transaction->posted_at->format('c'), StockLot::sole()->received_at->format('c'));
        $this->assertTrue($transaction->is_late_posting);
        $this->assertTrue(Str::isUuid($transaction->id));
        $this->assertSame($this->actor->id, $transaction->posted_by);
        $this->assertSame($transaction->id, AuditLog::where('entity_type', $transaction->getMorphClass())->sole()->entity_id);
        $this->assertSame(1, StockBalance::sole()->version);
    }

    public function test_out_allocates_oldest_received_lots_and_preserves_ledger_history(): void
    {
        $first = app(PostStockIn::class)->handle($this->data([$this->line('2.0001')]));
        $this->travel(1)->minutes();
        $second = app(PostStockIn::class)->handle($this->data([$this->line('3.0002')]));
        $out = app(PostStockOut::class)->handle($this->data([$this->line('4.0002', StockDirection::Out)], type: StockTransactionType::MaterialIssue));
        $entry = $out->ledgerEntries()->sole();
        $this->assertSame('-4.0002', $entry->quantity);
        $this->assertSame('1.0001', StockBalance::sole()->quantity);
        $firstLot = $first->ledgerEntries()->sole()->lot;
        $secondLot = $second->ledgerEntries()->sole()->lot;
        $this->assertSame('0.0000', $firstLot->remaining_quantity);
        $this->assertSame('1.0001', $secondLot->remaining_quantity);
        $this->assertSame('2.0001', $entry->lotAllocations()->where('stock_lot_id', $firstLot->id)->sole()->quantity);
        $this->assertSame('2.0001', $entry->lotAllocations()->where('stock_lot_id', $secondLot->id)->sole()->quantity);
        $this->assertSame('2.0001', $first->ledgerEntries()->sole()->quantity);
    }

    public function test_consignment_buckets_keep_vendor_ownership_separate(): void
    {
        $item = $this->makeItem('CONSIGNMENT');
        $pairs = [];
        foreach (['ONE', 'TWO'] as $code) {
            $vendor = Vendor::create(['vendor_code' => $code, 'vendor_name' => $code]);
            $pairs[] = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => $this->location->id]);
        }
        foreach ($pairs as $pair) {
            $posted = app(PostStockIn::class)->handle($this->data([$this->line('2', vendorItem: $pair)]));
            $this->assertSame($pair->vendor_id, $posted->ledgerEntries()->sole()->vendor_id);
            $this->assertSame("CONSIGNMENT:{$pair->id}:{$this->location->id}", $posted->ledgerEntries()->sole()->bucket_key);
        }
        $this->assertDatabaseCount('stock_balances', 2);
        $this->reject(fn () => app(PostStockOut::class)->handle($this->data([$this->line('3', StockDirection::Out, $pairs[0])], type: StockTransactionType::MaterialIssue)));
        $this->assertSame(['2.0000', '2.0000'], StockBalance::all()->pluck('quantity')->all());
    }

    public function test_invalid_ownership_or_inactive_placement_rolls_back_every_line(): void
    {
        $unplaced = $this->makeItem();
        $invalid = new StockPostingLineData($unplaced->id, null, $this->location->id, StockDirection::In, '1');
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line(), $invalid])));
        $this->assertDatabaseCount('stock_transactions', 0);
        $this->assertDatabaseCount('stock_ledger_entries', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_lots', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->reject(fn () => app(BuildStockBucket::class)->handle($this->makeItem('CONSIGNMENT'), null, $this->location));
        StockItemLocation::query()->update(['is_active' => false]);
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line()])));
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_insufficient_stock_rolls_back_earlier_out_lines_and_allocations(): void
    {
        app(PostStockIn::class)->handle($this->data([$this->line('3')]));
        $this->reject(fn () => app(PostStockOut::class)->handle($this->data([$this->line('2', StockDirection::Out), $this->line('2', StockDirection::Out)], type: StockTransactionType::MaterialIssue)));
        $this->assertSame('3.0000', StockBalance::sole()->quantity);
        $this->assertSame('3.0000', StockLot::sole()->remaining_quantity);
        $this->assertDatabaseCount('stock_transactions', 1);
        $this->assertDatabaseCount('stock_ledger_entries', 1);
        $this->assertDatabaseCount('stock_lot_allocations', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_retries_by_idempotency_or_source_return_original_without_double_posting(): void
    {
        $data = $this->data([$this->line('1.0001')]);
        $original = app(PostStockIn::class)->handle($data);
        $this->assertSame($original->id, app(PostStockIn::class)->handle($data)->id);
        $this->assertSame($original->id, app(PostStockIn::class)->handle($this->data($data->lines, source: $data->sourceId))->id);
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line()], key: $data->idempotencyKey)));
        $this->assertSame('1.0001', StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_transactions', 1);
        $this->assertDatabaseCount('stock_lots', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_quantity_arithmetic_preserves_four_decimals_at_supported_limit(): void
    {
        app(PostStockIn::class)->handle($this->data([$this->line('9999999999.9998')]));
        app(PostStockIn::class)->handle($this->data([$this->line('0.0001')]));
        $this->assertSame('9999999999.9999', StockBalance::sole()->quantity);
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line('0.0001')])));
        app(PostStockOut::class)->handle($this->data([$this->line('9999999999.9999', StockDirection::Out)], type: StockTransactionType::MaterialIssue));
        $this->assertSame('0.0000', StockBalance::sole()->quantity);
        $this->assertSame(['0.0000', '0.0000'], StockLot::all()->pluck('remaining_quantity')->all());
        $this->assertDatabaseCount('stock_transactions', 3);
    }

    public function test_ordinary_posting_requires_active_period_and_never_accepts_opening(): void
    {
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line()], type: StockTransactionType::Opening)));
        $this->period->update(['status' => 'DRAFT']);
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line()])));
        $this->period->update(['status' => 'ACTIVE', 'cutoff_at' => '2026-10-30 00:00:00']);
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line()])));
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_opening_seam_only_accepts_opening_for_a_freshly_locked_draft_period_in_outer_transaction(): void
    {
        $this->period->update(['status' => 'DRAFT']);
        $this->reject(fn () => app(PostStockIn::class)->handleOpening($this->data([$this->line()]), $this->period));
        $this->period->update(['status' => 'CLOSED']);
        $this->reject(fn () => app(PostStockIn::class)->handleOpening($this->data([$this->line()], type: StockTransactionType::Opening), $this->period));
        $this->period->update(['status' => 'DRAFT']);
        DB::transaction(function (): void {
            $transaction = app(PostStockIn::class)->handleOpening($this->data([$this->line()], type: StockTransactionType::Opening), $this->period);
            $this->assertSame($this->period->id, $transaction->inventory_period_id);
        });
        $this->assertSame('1.0000', StockBalance::sole()->quantity);
    }

    public function test_wrong_direction_and_invalid_source_uuid_fail_without_mutations(): void
    {
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line(direction: StockDirection::Out)])));
        $this->reject(fn () => app(PostStockOut::class)->handle($this->data([$this->line()])));
        $this->reject(fn () => app(PostStockIn::class)->handle($this->data([$this->line()], source: 'not-a-uuid')));
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_opening_seam_requires_an_outer_transaction(): void
    {
        $data = $this->data([$this->line()], type: StockTransactionType::Opening);
        DB::rollBack();
        try {
            $this->reject(fn () => app(PostStockIn::class)->handleOpening($data, $this->period));
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_registered_source_is_locked_linked_and_missing_source_is_rejected(): void
    {
        Schema::create('posting_test_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inventory_period_id');
            $table->uuid('stock_transaction_id')->nullable();
        });
        Relation::morphMap(['posting_test_source' => PostingTestSource::class]);
        try {
            $source = PostingTestSource::create(['inventory_period_id' => $this->period->id]);
            $base = $this->data([$this->line()]);
            $data = new StockPostingData($base->type, 'posting_test_source', $source->id, $base->physicalOccurredAt, $base->actorId, $base->idempotencyKey, $base->reason, $base->lines);
            $transaction = app(PostStockIn::class)->handle($data);
            $this->assertSame($transaction->id, $source->fresh()->stock_transaction_id);
            $missing = new StockPostingData($base->type, 'posting_test_source', (string) Str::uuid(), $base->physicalOccurredAt, $base->actorId, (string) Str::uuid(), null, $base->lines);
            $this->reject(fn () => app(PostStockIn::class)->handle($missing));
            $this->period->update(['status' => 'DRAFT']);
            $otherPeriod = InventoryPeriod::create(['cutoff_at' => '2026-10-26 00:00:00']);
            $otherSource = PostingTestSource::create(['inventory_period_id' => $otherPeriod->id]);
            $mismatch = new StockPostingData(StockTransactionType::Opening, 'posting_test_source', $otherSource->id, $base->physicalOccurredAt, $base->actorId, (string) Str::uuid(), null, $base->lines);
            $this->reject(fn () => app(PostStockIn::class)->handleOpening($mismatch, $this->period));
            $this->assertDatabaseCount('stock_transactions', 1);
        } finally {
            Relation::morphMap([], false);
            Schema::dropIfExists('posting_test_sources');
        }
    }

    public function test_audit_failure_rolls_back_ledger_balance_and_lots(): void
    {
        $this->app->instance(WriteAuditLog::class, new class extends WriteAuditLog
        {
            public function handle(User $actor, string $action, Model $entity, array $before, array $after, ?string $reason = null): AuditLog
            {
                throw new RuntimeException('Audit storage unavailable');
            }
        });
        try {
            app(PostStockIn::class)->handle($this->data([$this->line()]));
            $this->fail('Expected audit storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('stock_transactions', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_ledger_entries', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    public function test_committed_retry_still_returns_original_after_period_is_closed(): void
    {
        $data = $this->data([$this->line()]);
        $original = app(PostStockIn::class)->handle($data);
        $this->period->update(['status' => 'CLOSED']);
        $this->assertSame($original->id, app(PostStockIn::class)->handle($data)->id);
        $this->assertSame('1.0000', StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_transactions', 1);
    }
}

class PostingTestSource extends Model
{
    use HasUuids;

    protected $table = 'posting_test_sources';

    protected $guarded = [];

    public $timestamps = false;
}
