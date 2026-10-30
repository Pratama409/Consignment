<?php

namespace Tests\Feature;

use App\Actions\Refills\CloseVendorRefill;
use App\Actions\Refills\CreateVendorRefill;
use App\Actions\Refills\ProposeRefillRevision;
use App\Actions\Refills\ReviewRefillRevision;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Refills\RefillSuggestion;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VendorRefillWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendorUser;

    private Vendor $vendor;

    private VendorItem $relation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->vendor = Vendor::create(['vendor_code' => 'ACME', 'vendor_name' => 'Acme']);
        $this->vendorUser = User::factory()->vendor($this->vendor)->create();
        $item = Item::create(['item_code' => 'RF-ITEM', 'part_number' => 'RF-PN', 'item_name' => 'Refill item', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 30]);
        $this->relation = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $this->vendor->id, 'location_id' => Location::firstOrFail()->id]);
    }

    public function test_suggestion_subtracts_stock_and_open_refill_outstanding(): void
    {
        StockBalance::create(['item_id' => $this->relation->item_id, 'vendor_item_id' => $this->relation->id, 'location_id' => $this->relation->location_id, 'bucket_key' => "CONSIGNMENT:{$this->relation->id}:{$this->relation->location_id}", 'quantity' => 10]);
        $this->assertSame('20.0000', app(RefillSuggestion::class)->for($this->relation));
        app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => '12']]);
        $this->assertSame('8.0000', app(RefillSuggestion::class)->for($this->relation));
    }

    public function test_admin_creates_request_only_for_active_consignment_vendor_items(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => '12.5']], 'Monthly refill');
        $this->assertStringStartsWith('VRV-ACME-', $refill->refill_no);
        $this->assertSame('REQUESTED', $refill->status);
        $this->assertSame('12.5000', $refill->lines->sole()->requested_quantity);

        $stock = Item::create(['item_code' => 'STOCK-X', 'part_number' => 'STOCK-X', 'item_name' => 'Stock', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 1]);
        $this->expectException(ValidationException::class);
        app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $stock->id, 'quantity' => 1]]);
    }

    public function test_vendor_revision_preserves_requested_quantity_and_requires_admin_review(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 1000]]);
        $revision = app(ProposeRefillRevision::class)->handle($refill->lines->sole(), $this->vendorUser, '700', 'Stok Vendor terbatas');
        $this->assertSame('1000.0000', $revision->line->requested_quantity);
        $this->assertSame('REVISION_PENDING', $refill->fresh()->status);
        $this->assertSame('PENDING', $revision->status);
        app(ReviewRefillRevision::class)->handle($revision, $this->admin, true, 'Disetujui untuk pengiriman awal');
        $this->assertSame('APPROVED', $revision->fresh()->status);
        $this->assertSame('APPROVED', $refill->fresh()->status);
    }

    public function test_other_vendor_cannot_propose_revision_and_close_requires_reason(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 10]]);
        $other = Vendor::create(['vendor_code' => 'OTHER', 'vendor_name' => 'Other']);
        $otherUser = User::factory()->vendor($other)->create();
        try {
            app(ProposeRefillRevision::class)->handle($refill->lines->sole(), $otherUser, '5', 'Wrong vendor');
            $this->fail('Cross-vendor revision must fail.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ValidationException::class);
        app(CloseVendorRefill::class)->handle($refill, $this->admin, '');
    }

    public function test_admin_can_close_remaining_refill_with_auditable_reason(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 10]]);
        $closed = app(CloseVendorRefill::class)->handle($refill, $this->admin, 'Vendor menghentikan produksi');
        $this->assertSame('CLOSED_PARTIAL', $closed->status);
        $this->assertSame($this->admin->id, $closed->closed_by);
        $this->assertSame('Vendor menghentikan produksi', $closed->close_reason);
    }

    public function test_pending_revision_cannot_reopen_a_closed_refill(): void
    {
        $refill = app(CreateVendorRefill::class)->handle($this->vendor, $this->admin, [['item_id' => $this->relation->item_id, 'quantity' => 10]]);
        $revision = app(ProposeRefillRevision::class)->handle($refill->lines->sole(), $this->vendorUser, '5', 'Terbatas');
        app(CloseVendorRefill::class)->handle($refill, $this->admin, 'Tidak dilanjutkan');
        $this->expectException(ValidationException::class);
        app(ReviewRefillRevision::class)->handle($revision, $this->admin, true, 'Terlambat');
    }
}
