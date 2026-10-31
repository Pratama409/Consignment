<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_warehouse_can_open_procurement_queue(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('procurement.index'))->assertOk();
        auth()->logout();
        $this->actingAs(User::factory()->warehouse()->create())->get(route('procurement.index'))->assertOk();
    }

    public function test_vendor_can_open_only_vendor_scoped_procurement_queue(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'PROC', 'vendor_name' => 'Procurement Vendor']);
        $this->actingAs(User::factory()->vendor($vendor)->create())->get(route('procurement.index'))->assertOk();
    }
}
