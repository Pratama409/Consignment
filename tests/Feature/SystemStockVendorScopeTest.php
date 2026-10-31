<?php

namespace Tests\Feature;

use App\Models\StockBalance;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemStockVendorScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_cannot_open_other_vendor_or_internal_bucket_detail(): void
    {
        $this->seed(DemoDataSeeder::class);
        $vendorUser = User::where('role', 'vendor')->firstOrFail();
        $own = StockBalance::whereHas('bucket.vendorItem', fn ($pair) => $pair->where('vendor_id', $vendorUser->vendor_id))->firstOrFail();
        $foreign = StockBalance::whereHas('bucket.vendorItem', fn ($pair) => $pair->where('vendor_id', '!=', $vendorUser->vendor_id))->firstOrFail();
        $internal = StockBalance::whereHas('bucket', fn ($bucket) => $bucket->whereNull('vendor_item_id'))->firstOrFail();

        $this->actingAs($vendorUser)->get(route('monitoring.system-stock.show', $own))->assertOk();
        $this->actingAs($vendorUser)->get(route('monitoring.system-stock.show', $foreign))->assertForbidden();
        $this->actingAs($vendorUser)->get(route('monitoring.system-stock.show', $internal))->assertForbidden();
    }
}
