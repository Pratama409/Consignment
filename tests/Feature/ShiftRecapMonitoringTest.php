<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockIn;
use App\Actions\Monitoring\CloseOperationalShift;
use App\Actions\Monitoring\GenerateShiftRecap;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftRecapMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_recap_freezes_versioned_checksum_and_totals(): void
    {
        $this->seed(MasterDataSeeder::class);
        InventoryPeriod::create(['cutoff_at' => now()->subDay(), 'status' => 'ACTIVE']);
        $admin = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $item = Item::create(['item_code' => 'SHIFT-MON', 'part_number' => 'SHIFT-MON', 'item_name' => 'Shift', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 1]);
        $vendor = Vendor::create(['vendor_code' => 'SM', 'vendor_name' => 'Shift Monitor']);
        $relation = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);
        $transaction = app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'shift-monitor', (string) Str::uuid(), CarbonImmutable::now(), $admin->id, (string) Str::uuid(), null, [new StockPostingLineData($item->id, $relation->id, $location->id, StockDirection::In, '6')]));
        $summary = app(GenerateShiftRecap::class)->handle($transaction->operationalShift);
        $snapshot = app(CloseOperationalShift::class)->handle($transaction->operationalShift, $admin);

        $this->assertSame('6.0000', $summary['in']);
        $this->assertSame($summary, $snapshot->summary);
        $this->assertSame(1, $snapshot->version);
        $this->assertSame(hash('sha256', json_encode($summary, JSON_UNESCAPED_SLASHES)), $snapshot->checksum);
        $this->assertSame('closed', $transaction->operationalShift->fresh()->status->value);
    }
}
