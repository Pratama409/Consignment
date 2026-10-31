<?php

namespace Tests\Feature;

use App\Actions\Refills\CloseVendorRefill;
use App\Actions\Refills\CreateRefillShipment;
use App\Actions\Refills\CreateVendorRefill;
use App\Actions\Refills\PostRefillReceipt;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockLedgerEntry;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VendorRefillReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private User $vendorUser;

    private Vendor $vendor;

    private VendorItem $relation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->vendor = Vendor::create(['vendor_code' => 'SHIP', 'vendor_name' => 'Shipment Vendor']);
        $this->vendorUser = User::factory()->vendor($this->vendor)->create();
        $item = Item::create(['item_code' => 'SHIP-ITEM', 'part_number' => 'SHIP-PN', 'item_name' => 'Shipment item', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 30]);
        $this->relation = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $this->vendor->id, 'location_id' => Location::firstOrFail()->id]);
        InventoryPeriod::create(['cutoff_at' => '2026-10-29 00:00:00', 'status' => 'ACTIVE']);
    }

    public function test_partial_receipts_post_exact_stock_once_and_complete_after_remainder(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 10]]);
        $line = $refill->lines->sole();
        $shipment = app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-001', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 10]], 'Vendor Delivery');
        $first = app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-001', now()->toImmutable(), [['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => 4]], 'receipt-key-1');
        $retry = app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-001', now()->toImmutable(), [['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => 4]], 'receipt-key-1');
        $this->assertSame($first->id, $retry->id);
        $this->assertSame('4.0000', StockBalance::sole()->quantity);
        $this->assertSame('4.0000', StockLedgerEntry::sole()->quantity);
        $this->assertSame('4.0000', StockLot::sole()->remaining_quantity);
        $this->assertSame('PARTIAL', $refill->fresh()->status);

        app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-001', now()->toImmutable(), [['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => 6]], 'receipt-key-2');
        $this->assertSame('10.0000', StockBalance::sole()->quantity);
        $this->assertSame('COMPLETED', $refill->fresh()->status);
        $this->assertSame('RECEIVED', $shipment->fresh()->status);
    }

    public function test_receipt_cannot_exceed_requested_quantity(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 5]]);
        $line = $refill->lines->sole();
        $shipment = app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-OVER', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 5]]);
        $this->expectException(ValidationException::class);
        app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-OVER', now()->toImmutable(), [['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => 6]], 'over-key');
    }

    public function test_split_location_rows_are_aggregated_before_over_receipt_validation(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 10]]);
        $line = $refill->lines->sole();
        $shipment = app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-SPLIT', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 10]]);
        $other = Location::create(['location_code' => 'OTHER', 'location_name' => 'Other']);
        StockBalance::create(['stock_bucket_id' => StockBucket::create(['vendor_item_id' => $this->relation->id, 'location_id' => $other->id])->id, 'quantity' => 1]);
        $this->expectException(ValidationException::class);
        app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-SPLIT', now()->toImmutable(), [
            ['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => 7],
            ['refill_line_id' => $line->id, 'location_id' => $other->id, 'quantity' => 7],
        ], 'split-key');
    }

    public function test_closed_refill_rejects_receipts_without_posting_stock(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 5]]);
        $line = $refill->lines->sole();
        $shipment = app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-CLOSED', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 5]]);
        app(CloseVendorRefill::class)->handle($refill, $this->admin, 'Dihentikan');
        try {
            app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-CLOSED', now()->toImmutable(), [['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => 1]], 'closed-key');
            $this->fail('Closed refill must reject receipt.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_cumulative_shipments_cannot_exceed_request(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 10]]);
        $line = $refill->lines->sole();
        app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-A', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 7]]);
        $this->expectException(ValidationException::class);
        app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-B', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 4]]);
    }

    public function test_incoming_refill_rejects_fractional_units(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 5]]);
        $line = $refill->lines->sole();
        $shipment = app(CreateRefillShipment::class)->handle($refill, $this->vendorUser, 'DO-WHOLE', now()->toImmutable(), [['refill_line_id' => $line->id, 'quantity' => 5]]);
        $this->expectException(ValidationException::class);
        app(PostRefillReceipt::class)->handle($shipment, $this->warehouse, 'DO-WHOLE', now()->toImmutable(), [['refill_line_id' => $line->id, 'location_id' => $this->relation->location_id, 'quantity' => '1.5']], 'fraction-key');
    }
}
