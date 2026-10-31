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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private Item $item;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->location = Location::firstOrFail();
        $this->item = Item::create(['item_code' => 'CORR-'.Str::random(8), 'part_number' => 'PN-CORR', 'item_name' => 'Correction item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $this->item->id, 'location_id' => $this->location->id]);
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
    }

    public function test_warehouse_creates_draft_but_only_admin_approves_and_posts_it_with_audit(): void
    {
        $adjustment = app(CreateStockAdjustment::class)->handle($this->adjustmentData('IN'), $this->warehouse);
        $this->assertSame('DRAFT', $adjustment->status->value);
        $this->assertDatabaseCount('stock_transactions', 0);

        $this->expectValidation(fn () => app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Approved', $this->warehouse));
        $approved = app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Approved after count', $this->admin);
        $transaction = app(PostStockAdjustment::class)->handle($approved, $this->admin, 'adjustment:'.$approved->id);

        $this->assertSame('POSTED', $approved->fresh()->status->value);
        $this->assertSame('2.5000', StockBalance::sole()->quantity);
        $this->assertSame($transaction->id, $approved->fresh()->stock_transaction_id);
        $this->assertGreaterThanOrEqual(3, AuditLog::where('entity_id', $approved->id)->count());
        $this->expectValidation(fn () => app(ReviewStockAdjustment::class)->handle($approved->fresh(), false, 'late edit', $this->admin));
    }

    public function test_rejected_adjustment_never_changes_stock_and_reason_is_required(): void
    {
        $this->expectValidation(fn () => app(CreateStockAdjustment::class)->handle($this->adjustmentData('IN', ''), $this->warehouse));
        $adjustment = app(CreateStockAdjustment::class)->handle($this->adjustmentData('IN'), $this->warehouse);
        $rejected = app(ReviewStockAdjustment::class)->handle($adjustment, false, 'Tidak sesuai bukti', $this->admin);
        $this->assertSame('REJECTED', $rejected->status->value);
        $this->expectValidation(fn () => app(PostStockAdjustment::class)->handle($rejected, $this->admin, 'rejected'));
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_reversal_is_admin_only_idempotent_and_restores_out_fifo_lots(): void
    {
        $in = app(PostStockIn::class)->handle($this->posting('5.0000', StockDirection::In, StockTransactionType::ManualReceipt));
        $out = app(PostStockOut::class)->handle($this->posting('3.0000', StockDirection::Out, StockTransactionType::MaterialIssue));
        $this->assertSame('2.0000', StockBalance::sole()->quantity);

        $this->expectValidation(fn () => app(ReverseStockTransaction::class)->handle($out, $this->warehouse, 'Wrong role', 'reverse:out'));
        $reversal = app(ReverseStockTransaction::class)->handle($out, $this->admin, 'MR dibatalkan', 'reverse:out');
        $this->assertSame('5.0000', StockBalance::sole()->quantity);
        $this->assertSame('5.0000', StockLot::sole()->remaining_quantity);
        $this->assertSame($reversal->id, app(ReverseStockTransaction::class)->handle($out, $this->admin, 'MR dibatalkan', 'reverse:out')->id);
        $this->assertSame($out->id, $reversal->reversal_of_id);

        app(PostStockOut::class)->handle($this->posting('1.0000', StockDirection::Out, StockTransactionType::MaterialIssue));
        $this->expectValidation(fn () => app(ReverseStockTransaction::class)->handle($in, $this->admin, 'Tidak boleh', 'reverse:consumed-in'));
    }

    public function test_adjustment_item_resolves_from_vendor_item_for_consignment(): void
    {
        $consignment = Item::create(['item_code' => 'CORR-C-'.Str::random(6), 'part_number' => 'PN-CORR-C', 'item_name' => 'Consignment correction', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        $pair = VendorItem::create(['item_id' => $consignment->id, 'vendor_id' => Vendor::create(['vendor_code' => 'CORR-V', 'vendor_name' => 'Correction Vendor'])->id, 'location_id' => $this->location->id]);

        $adjustment = app(CreateStockAdjustment::class)->handle([...$this->adjustmentData('IN'), 'item_id' => $consignment->id, 'vendor_item_id' => $pair->id], $this->warehouse);
        $this->assertNull($adjustment->item_id);
        $this->assertSame($consignment->id, $adjustment->item->id);

        $approved = app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Disetujui', $this->admin);
        app(PostStockAdjustment::class)->handle($approved, $this->admin, 'adjustment:'.$approved->id);
        $this->assertSame('2.5000', StockBalance::whereHas('bucket', fn ($query) => $query->where('vendor_item_id', $pair->id))->sole()->quantity);
    }

    public function test_correction_form_offers_vendor_item_at_a_former_default_location(): void
    {
        $consignment = Item::create(['item_code' => 'CORR-M-'.Str::random(6), 'part_number' => 'PN-CORR-M', 'item_name' => 'Moved correction', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        $pair = VendorItem::create(['item_id' => $consignment->id, 'vendor_id' => Vendor::create(['vendor_code' => 'CORR-M', 'vendor_name' => 'Moved Vendor'])->id, 'location_id' => $this->location->id]);
        $adjustment = app(CreateStockAdjustment::class)->handle([...$this->adjustmentData('IN'), 'item_id' => $consignment->id, 'vendor_item_id' => $pair->id], $this->warehouse);
        app(PostStockAdjustment::class)->handle(app(ReviewStockAdjustment::class)->handle($adjustment, true, 'Disetujui', $this->admin), $this->admin, 'adjustment:moved');
        $moved = Location::create(['location_code' => 'CORR-MOVED', 'location_name' => 'Moved']);
        $pair->update(['location_id' => $moved->id]);

        $this->actingAs($this->warehouse)->get(route('stock-corrections.create'))->assertInertia(fn ($page) => $page
            ->where('vendor_items', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $pair->id && $row['location_id'] === $this->location->id)
                && collect($rows)->contains(fn ($row) => $row['id'] === $pair->id && $row['location_id'] === $moved->id)));
    }

    private function adjustmentData(string $direction, string $reason = 'Selisih hasil hitung fisik'): array
    {
        return ['item_id' => $this->item->id, 'vendor_item_id' => null, 'location_id' => $this->location->id, 'direction' => $direction, 'quantity' => '2.5000', 'reason' => $reason];
    }

    private function posting(string $quantity, StockDirection $direction, StockTransactionType $type): StockPostingData
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
