<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaterialRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_request_workflow_uses_normalized_uuid_tables(): void
    {
        foreach (['departments', 'material_requests', 'material_request_lines', 'material_request_revisions', 'material_request_revision_lines', 'material_request_approvals', 'material_issues', 'material_issue_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
            $this->assertTrue(Schema::hasColumn($table, 'id'), "Missing UUID id on {$table}");
        }
    }

    public function test_request_line_rejects_duplicate_item_and_fractional_quantity(): void
    {
        $request = $this->requestRecord();
        $item = Item::create(['item_code' => 'MR-ITEM', 'part_number' => 'MR-PN', 'item_name' => 'MR Item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::create(['code' => 'PCS', 'name' => 'Pieces'])->id, 'minimum_stock' => 1]);
        $base = ['material_request_id' => $request->id, 'item_id' => $item->id, 'requested_quantity' => 2, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()];
        DB::table('material_request_lines')->insert(['id' => (string) Str::uuid(), ...$base]);

        $this->expectException(QueryException::class);
        DB::table('material_request_lines')->insert(['id' => (string) Str::uuid(), ...$base]);
    }

    public function test_request_line_quantity_must_be_a_positive_whole_unit(): void
    {
        $request = $this->requestRecord();
        $item = Item::create(['item_code' => 'MR-FRACTION', 'part_number' => 'MR-FRACTION-PN', 'item_name' => 'Fraction Item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::create(['code' => 'EA', 'name' => 'Each'])->id, 'minimum_stock' => 1]);

        $this->expectException(QueryException::class);
        DB::table('material_request_lines')->insert(['id' => (string) Str::uuid(), 'material_request_id' => $request->id, 'item_id' => $item->id, 'requested_quantity' => 1.5, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function requestRecord(): MaterialRequest
    {
        $department = Department::create(['department_code' => 'OPS', 'department_name' => 'Operations']);

        return MaterialRequest::create(['request_no' => 'MR/20261030/0001', 'public_token_hash' => hash('sha256', 'token'), 'applicant_name' => 'Requester', 'applicant_nrp' => '10001', 'applicant_phone' => '08123456789', 'department_id' => $department->id, 'needed_at' => now()->addDay(), 'purpose' => 'Operational need', 'status' => 'SUBMITTED', 'submitted_at' => now()]);
    }
}
