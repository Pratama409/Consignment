<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\PostStockOut;
use App\Actions\Reconciliation\FinalizeVendorReconciliation;
use App\Actions\Reconciliation\GenerateVendorReconciliation;
use App\Actions\Reconciliation\SubmitVendorResponse;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Enums\VendorReconciliationStatus;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Services\Reconciliation\ReconciliationExcelExporter;
use App\Services\Reconciliation\ReconciliationPdfExporter;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_ba_uses_vendor_ledger_and_can_be_agreed_and_finalized(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 08:00:00', 'Asia/Makassar'));
        $this->seed(MasterDataSeeder::class);
        InventoryPeriod::create(['cutoff_at' => '2026-09-30 00:00:00', 'status' => 'ACTIVE']);
        $admin = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $item = Item::create(['item_code' => 'BA', 'part_number' => 'BA-PN', 'item_name' => 'BA Item', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 1]);
        $vendor = Vendor::create(['vendor_code' => 'BAV', 'vendor_name' => 'BA Vendor']);
        $relation = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);
        $line = fn (string $qty, StockDirection $direction) => new StockPostingLineData($item->id, $relation->id, $location->id, $direction, $qty);
        app(PostStockIn::class)->handle($this->posting($admin, [$line('10', StockDirection::In)], StockTransactionType::ManualReceipt));
        app(PostStockOut::class)->handle($this->posting($admin, [$line('4', StockDirection::Out)], StockTransactionType::MaterialIssue));

        $ba = app(GenerateVendorReconciliation::class)->handle($vendor, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $admin);
        $this->assertSame('10.0000', $ba->lines->sole()->in_quantity);
        $this->assertSame('4.0000', $ba->lines->sole()->out_quantity);
        $this->assertSame('6.0000', $ba->lines->sole()->system_closing_quantity);
        $ba->update(['status' => 'VENDOR_REVIEW']);
        $vendorUser = User::factory()->vendor($vendor)->create();
        app(SubmitVendorResponse::class)->handle($ba, true, '', $vendorUser);
        $final = app(FinalizeVendorReconciliation::class)->handle($ba->fresh(), $admin);
        $this->assertSame(VendorReconciliationStatus::Final, $final->status);
        $this->assertNotNull($final->checksum);
        $xlsx = app(ReconciliationExcelExporter::class)->export($final, $admin);
        $this->assertSame($xlsx->id, app(ReconciliationExcelExporter::class)->export($final, $admin)->id);
        $pdf = app(ReconciliationPdfExporter::class)->export($final, $admin);
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('local')->get($pdf->path));
    }

    private function posting(User $actor, array $lines, StockTransactionType $type): StockPostingData
    {
        return new StockPostingData($type, 'ba-test', (string) Str::uuid(), CarbonImmutable::now(), $actor->id, (string) Str::uuid(), null, $lines);
    }
}
