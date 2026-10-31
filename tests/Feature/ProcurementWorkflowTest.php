<?php

namespace Tests\Feature;

use App\Actions\Procurement\SaveProcurementReference;
use App\Actions\Procurement\ValidateProcurementRequirement;
use App\Models\Department;
use App\Models\Item;
use App\Models\Location;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueLine;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\ProcurementRequirement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_procurement_enforces_reservation_pr_validation_po_and_grgi_order(): void
    {
        [$requirement, $admin] = $this->requirement();
        $save = app(SaveProcurementReference::class);

        $this->expectException(ValidationException::class);
        $save->handle($requirement, 'PO', [['document_no' => 'PO-1', 'quantity' => 10]]);
    }

    public function test_complete_is_reached_only_after_full_grgi(): void
    {
        [$requirement, $admin] = $this->requirement();
        $save = app(SaveProcurementReference::class);
        $save->handle($requirement, 'RESERVATION', [['document_no' => 'RES-1', 'quantity' => 10]]);
        $save->handle($requirement->fresh(), 'PR', [['document_no' => 'PR-1', 'quantity' => 10]]);
        app(ValidateProcurementRequirement::class)->handle($requirement->fresh(), 'VALID', null, $admin);
        $save->handle($requirement->fresh(), 'PO', [['document_no' => 'PO-1', 'quantity' => 10]]);
        $this->assertSame('PO_PENDING', $requirement->fresh()->status);
        $save->handle($requirement->fresh(), 'GRGI', [['document_no' => 'GR-1', 'quantity' => 10]]);
        $this->assertSame('COMPLETE', $requirement->fresh()->status);
    }

    public function test_required_quantity_comes_from_issue_line(): void
    {
        [$requirement] = $this->requirement();

        $this->assertSame(10, $requirement->requiredQuantity());
        $this->assertSame($requirement->issueLine->vendor_item_id, $requirement->vendorItem->id);
    }

    public function test_reference_total_cannot_exceed_issue_line_quantity(): void
    {
        [$requirement] = $this->requirement();

        $this->expectException(ValidationException::class);
        app(SaveProcurementReference::class)->handle($requirement, 'RESERVATION', [['document_no' => 'RES-OVER', 'quantity' => 11]]);
    }

    public function test_vendor_cannot_open_other_vendor_requirement(): void
    {
        [$requirement] = $this->requirement();
        $other = Vendor::create(['vendor_code' => 'OTHER', 'vendor_name' => 'Other Vendor']);

        $this->actingAs(User::factory()->vendor($other)->create())->get('/procurement')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('requirements.data', 0));
        $this->actingAs(User::factory()->vendor($requirement->vendorItem->vendor)->create())->get('/procurement')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('requirements.data', 1));
    }

    private function requirement(): array
    {
        $admin = User::factory()->admin()->create();
        $department = Department::create(['department_code' => 'PROC', 'department_name' => 'Procurement']);
        $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces']);
        $location = Location::create(['location_code' => 'PROC', 'location_name' => 'Procurement']);
        $vendor = Vendor::create(['vendor_code' => 'PROC', 'vendor_name' => 'Procurement Vendor']);
        $item = Item::create(['item_code' => 'PROC', 'part_number' => 'PROC-PN', 'item_name' => 'Procurement Item', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 1]);
        $vendorItem = VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);
        $request = MaterialRequest::create(['request_no' => 'MR-PROC', 'public_token_hash' => hash('sha256', 'proc'), 'applicant_name' => 'Tester', 'applicant_nrp' => '1', 'applicant_phone' => '1', 'department_id' => $department->id, 'needed_at' => now(), 'purpose' => 'Test', 'submitted_at' => now()]);
        $requestLine = MaterialRequestLine::create(['material_request_id' => $request->id, 'item_id' => $item->id, 'requested_quantity' => 10]);
        $issue = MaterialIssue::create(['issue_no' => 'ISS-PROC', 'material_request_id' => $request->id, 'status' => 'POSTED', 'issued_at' => now(), 'issued_by' => $admin->id, 'receiver_name' => 'Tester', 'idempotency_key' => (string) Str::uuid()]);
        $issueLine = MaterialIssueLine::create(['material_issue_id' => $issue->id, 'material_request_line_id' => $requestLine->id, 'vendor_item_id' => $vendorItem->id, 'location_id' => $location->id, 'issued_quantity' => 10]);

        return [ProcurementRequirement::create(['row_key' => (string) Str::uuid(), 'material_issue_line_id' => $issueLine->id]), $admin];
    }
}
