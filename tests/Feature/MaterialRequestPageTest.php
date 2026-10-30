<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MaterialRequestPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_open_and_submit_material_request(): void
    {
        [$department, $item] = $this->masters();
        $this->get(route('public.material-requests.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('MaterialRequests/PublicCreate'));
        $response = $this->post(route('public.material-requests.store'), ['applicant_name' => 'Budi', 'applicant_nrp' => '1001', 'applicant_phone' => '08123', 'department_id' => $department->id, 'needed_at' => now()->addDay()->format('Y-m-d H:i'), 'purpose' => 'Maintenance', 'lines' => [['item_id' => $item->id, 'quantity' => 2]]]);
        $response->assertRedirect();
        $this->assertDatabaseHas('material_requests', ['applicant_nrp' => '1001']);
    }

    public function test_admin_and_warehouse_can_open_queue_but_vendor_cannot(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('material-requests.index'))->assertOk();
        auth()->logout();
        $this->actingAs(User::factory()->warehouse()->create())->get(route('material-requests.index'))->assertOk();
        auth()->logout();
        $vendor = Vendor::create(['vendor_code' => 'PAGE', 'vendor_name' => 'Page Vendor']);
        $this->actingAs(User::factory()->vendor($vendor)->create())->get(route('material-requests.index'))->assertForbidden();
    }

    private function masters(): array
    {
        $department = Department::create(['department_code' => 'OPS', 'department_name' => 'Operations']);
        $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces']);
        $item = Item::create(['item_code' => 'MR-PAGE', 'part_number' => 'MR-PAGE-PN', 'item_name' => 'Page Item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 1]);

        return [$department, $item];
    }
}
