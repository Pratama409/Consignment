<?php

namespace Tests\Feature;

use App\Actions\MaterialRequests\ApproveMaterialRequest;
use App\Actions\MaterialRequests\CreateMaterialRequest;
use App\Actions\MaterialRequests\ReviseMaterialRequest;
use App\Actions\MaterialRequests\VerifyPublicMaterialRequest;
use App\Models\Department;
use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaterialRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_request_creates_lines_and_pending_approval_with_whole_units(): void
    {
        [$department, $item] = $this->masters();
        $request = app(CreateMaterialRequest::class)->handle(['applicant_name' => 'Budi', 'applicant_nrp' => 'NRP-1', 'applicant_phone' => '08123', 'department_id' => $department->id, 'needed_at' => now()->addDay(), 'purpose' => 'Maintenance'], [['item_id' => $item->id, 'quantity' => 3]]);

        $this->assertSame('SUBMITTED', $request->status);
        $this->assertSame(3, (int) $request->lines->first()->requested_quantity);
        $this->assertSame('PENDING', $request->approvals->first()->status);
    }

    public function test_public_request_rejects_fractional_units(): void
    {
        [$department, $item] = $this->masters();
        $this->expectException(ValidationException::class);
        app(CreateMaterialRequest::class)->handle(['applicant_name' => 'Budi', 'applicant_nrp' => 'NRP-1', 'applicant_phone' => '08123', 'department_id' => $department->id, 'needed_at' => now()->addDay(), 'purpose' => 'Maintenance'], [['item_id' => $item->id, 'quantity' => 1.5]]);
    }

    public function test_public_tracking_requires_matching_nrp_or_phone(): void
    {
        [$department, $item] = $this->masters();
        $request = app(CreateMaterialRequest::class)->handle(['applicant_name' => 'Budi', 'applicant_nrp' => 'NRP-1', 'applicant_phone' => '08123', 'department_id' => $department->id, 'needed_at' => now()->addDay(), 'purpose' => 'Maintenance'], [['item_id' => $item->id, 'quantity' => 2]]);
        $this->assertTrue(app(VerifyPublicMaterialRequest::class)->handle($request->request_no, 'NRP-1')->is($request));

        $this->expectException(ValidationException::class);
        app(VerifyPublicMaterialRequest::class)->handle($request->request_no, 'wrong');
    }

    public function test_revision_supersedes_approval_and_admin_can_approve_current_version(): void
    {
        [$department, $item] = $this->masters();
        $request = app(CreateMaterialRequest::class)->handle(['applicant_name' => 'Budi', 'applicant_nrp' => 'NRP-1', 'applicant_phone' => '08123', 'department_id' => $department->id, 'needed_at' => now()->addDay(), 'purpose' => 'Maintenance'], [['item_id' => $item->id, 'quantity' => 2]]);
        app(ReviseMaterialRequest::class)->handle($request, 'Tambah kebutuhan', [['line_id' => $request->lines->first()->id, 'quantity' => 4]]);
        $request->refresh();
        $this->assertSame('SUPERSEDED', $request->approvals()->where('revision_no', 0)->value('status'));
        $current = $request->approvals()->where('revision_no', 1)->firstOrFail();

        app(ApproveMaterialRequest::class)->handle($request, User::factory()->admin()->create(), true, 'Disetujui', 1);
        $this->assertSame('APPROVED', $current->fresh()->status);
    }

    private function masters(): array
    {
        $department = Department::create(['department_code' => 'OPS', 'department_name' => 'Operations']);
        $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces']);
        $item = Item::create(['item_code' => 'MR-ITEM', 'part_number' => 'MR-PN', 'item_name' => 'MR Item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 1]);

        return [$department, $item];
    }
}
