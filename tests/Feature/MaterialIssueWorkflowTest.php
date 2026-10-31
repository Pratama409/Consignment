<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockIn;
use App\Actions\MaterialRequests\ApproveMaterialRequest;
use App\Actions\MaterialRequests\CreateMaterialIssue;
use App\Actions\MaterialRequests\CreateMaterialRequest;
use App\Actions\MaterialRequests\ReviseMaterialRequest;
use App\Actions\MaterialRequests\SetMaterialRequestLineReadiness;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\Department;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\StockBalance;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MaterialIssueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_can_prepare_before_approval_but_cannot_issue(): void
    {
        [$request, $warehouse] = $this->scenario();
        $line = $request->lines->first();
        app(SetMaterialRequestLineReadiness::class)->handle($line, $warehouse, 'READY');
        $this->assertSame('READY', $line->fresh()->status);

        $this->expectException(ValidationException::class);
        app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), [['line_id' => $line->id, 'quantity' => 1]], (string) Str::uuid());
    }

    public function test_partial_mixed_issue_only_posts_consignment_stock_and_is_idempotent(): void
    {
        [$request, $warehouse, $admin, $consignment, $stock, $relation, $location] = $this->scenario();
        app(ApproveMaterialRequest::class)->handle($request, $admin, true, null, 0);
        foreach ($request->lines as $line) {
            app(SetMaterialRequestLineReadiness::class)->handle($line, $warehouse, 'READY');
        }
        $key = (string) Str::uuid();
        $inputs = [
            ['line_id' => $request->lines->firstWhere('item_id', $consignment->id)->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 2],
            ['line_id' => $request->lines->firstWhere('item_id', $stock->id)->id, 'quantity' => 1],
        ];
        $issue = app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), $inputs, $key);
        $retry = app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), $inputs, $key);

        $this->assertSame($issue->id, $retry->id);
        $this->assertSame('3.0000', StockBalance::whereHas('bucket', fn ($query) => $query->where('vendor_item_id', $relation->id))->sole()->quantity);
        $this->assertCount(2, $issue->lines);
        $this->assertDatabaseCount('procurement_requirements', 1);
        $this->assertSame('PARTIALLY_ISSUED', $request->fresh()->status);
    }

    public function test_latest_revision_number_controls_issue_limit_even_when_timestamps_tie(): void
    {
        [$request, $warehouse, $admin, $consignment, , $relation, $location] = $this->scenario();
        $line = $request->lines->firstWhere('item_id', $consignment->id);
        app(ReviseMaterialRequest::class)->handle($request, 'Naik', [['line_id' => $line->id, 'quantity' => 8]], $admin);
        app(ReviseMaterialRequest::class)->handle($request, 'Turun', [['line_id' => $line->id, 'quantity' => 2]], $admin);
        app(ApproveMaterialRequest::class)->handle($request, $admin, true, null, 2);
        app(SetMaterialRequestLineReadiness::class)->handle($line, $warehouse, 'READY');

        $this->expectException(ValidationException::class);
        app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), [['line_id' => $line->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 3]], (string) Str::uuid());
    }

    public function test_remaining_line_can_be_prepared_after_another_line_is_partially_issued(): void
    {
        [$request, $warehouse, $admin, $consignment, $stock, $relation, $location] = $this->scenario();
        app(ApproveMaterialRequest::class)->handle($request, $admin, true, null, 0);
        $first = $request->lines->firstWhere('item_id', $consignment->id);
        $second = $request->lines->firstWhere('item_id', $stock->id);
        app(SetMaterialRequestLineReadiness::class)->handle($first, $warehouse, 'READY');
        app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), [['line_id' => $first->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 1]], (string) Str::uuid());

        app(SetMaterialRequestLineReadiness::class)->handle($second, $warehouse, 'READY');
        $this->assertSame('READY', $second->fresh()->status);
        $this->assertSame('PARTIALLY_ISSUED', $request->fresh()->status);
    }

    public function test_idempotency_key_cannot_return_issue_from_another_request(): void
    {
        [$request, $warehouse, $admin, $consignment, , $relation, $location] = $this->scenario();
        app(ApproveMaterialRequest::class)->handle($request, $admin, true, null, 0);
        $line = $request->lines->firstWhere('item_id', $consignment->id);
        app(SetMaterialRequestLineReadiness::class)->handle($line, $warehouse, 'READY');
        $key = (string) Str::uuid();
        app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), [['line_id' => $line->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 1]], $key);
        $other = app(CreateMaterialRequest::class)->handle(['applicant_name' => 'Other', 'applicant_nrp' => 'NRP-2', 'applicant_phone' => '08234', 'department_id' => $request->department_id, 'needed_at' => now()->addDay(), 'purpose' => 'Other'], [['item_id' => $consignment->id, 'quantity' => 1]]);

        $this->expectException(ValidationException::class);
        app(CreateMaterialIssue::class)->handle($other, $warehouse, 'Receiver', now()->toImmutable(), [['line_id' => $other->lines->first()->id, 'quantity' => 1]], $key);
    }

    /** @return array{0: MaterialRequest, 1: User, 2: User, 3: MaterialRequestLine, 4: VendorItem, 5: Location} */
    private function readyConsignmentLine(): array
    {
        [$request, $warehouse, $admin, $consignment, , $relation, $location] = $this->scenario();
        app(ApproveMaterialRequest::class)->handle($request, $admin, true, null, 0);
        $line = $request->lines->firstWhere('item_id', $consignment->id);
        app(SetMaterialRequestLineReadiness::class)->handle($line, $warehouse, 'READY');

        return [$request, $warehouse, $admin, $line, $relation, $location];
    }

    public function test_issue_stores_trimmed_work_order_and_backcharge_flags(): void
    {
        [$request, $warehouse, , $line, $relation, $location] = $this->readyConsignmentLine();

        $issue = app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), [
            ['line_id' => $line->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 1, 'is_backcharge' => true],
        ], (string) Str::uuid(), '  WO-1234  ');

        $this->assertSame('WO-1234', $issue->work_order_no);
        $this->assertTrue((bool) $issue->lines->sole()->is_backcharge);
    }

    public function test_blank_work_order_is_stored_as_null_and_backcharge_defaults_to_false(): void
    {
        [$request, $warehouse, , $line, $relation, $location] = $this->readyConsignmentLine();

        $issue = app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Receiver', now()->toImmutable(), [
            ['line_id' => $line->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 1],
        ], (string) Str::uuid(), '   ');

        $this->assertNull($issue->work_order_no);
        $this->assertFalse((bool) $issue->lines->sole()->is_backcharge);
    }

    public function test_over_length_work_order_is_rejected_with_field_error(): void
    {
        [$request, $warehouse, , $line, $relation, $location] = $this->readyConsignmentLine();

        $this->actingAs($warehouse)->post(route('material-requests.issues.store', $request), $this->issuePayload($line, $relation, $location, ['work_order_no' => str_repeat('A', 51)]))
            ->assertSessionHasErrors('work_order_no');
        $this->assertDatabaseCount('material_issues', 0);
    }

    public function test_non_boolean_backcharge_is_rejected(): void
    {
        [$request, $warehouse, , $line, $relation, $location] = $this->readyConsignmentLine();
        $payload = $this->issuePayload($line, $relation, $location);
        $payload['lines'][0]['is_backcharge'] = 'maybe';

        $this->actingAs($warehouse)->post(route('material-requests.issues.store', $request), $payload)->assertSessionHasErrors('lines.0.is_backcharge');
        $this->assertDatabaseCount('material_issues', 0);
    }

    public function test_work_order_and_backcharge_appear_in_issue_detail_and_recap_export(): void
    {
        [$request, $warehouse, $admin, $line, $relation, $location] = $this->readyConsignmentLine();
        $this->actingAs($warehouse)->post(route('material-requests.issues.store', $request), $this->issuePayload($line, $relation, $location, ['work_order_no' => 'WO-9']))->assertSessionHasNoErrors();
        $this->actingAs($warehouse)->get(route('material-requests.show', $request))->assertInertia(fn (Assert $page) => $page
            ->where('request.issues.0.work_order_no', 'WO-9')->where('request.issues.0.lines.0.is_backcharge', true));

        $this->actingAs($admin)->get(route('monitoring.transactions.index'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['type'] === 'MATERIAL_ISSUE' && $row['work_order_no'] === 'WO-9' && $row['backcharge'] === true)));

        $path = tempnam(sys_get_temp_dir(), 'recap-test-');
        file_put_contents($path, $this->actingAs($admin)->get(route('monitoring.transactions.export'))->assertOk()->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);
        $this->assertContains('WO', $sheet[0]);
        $this->assertContains('Backcharge', $sheet[0]);
        $issueRows = array_values(array_filter($sheet, fn ($row) => in_array('WO-9', $row, true)));
        $this->assertCount(1, $issueRows);
        $this->assertContains('YA', $issueRows[0]);

        $vendorUser = User::factory()->vendor($relation->vendor)->create();
        $this->actingAs($vendorUser)->get(route('monitoring.transactions.index'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['work_order_no'] === null && $row['backcharge'] === false)));
    }

    private function issuePayload($line, $relation, $location, array $extra = []): array
    {
        return [...['receiver_name' => 'Receiver', 'issued_at' => now()->toDateTimeString(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['line_id' => $line->id, 'vendor_item_id' => $relation->id, 'location_id' => $location->id, 'quantity' => 1, 'is_backcharge' => true]]], ...$extra];
    }

    private function scenario(): array
    {
        $this->seed(MasterDataSeeder::class);
        InventoryPeriod::create(['cutoff_at' => now()->subDay(), 'status' => 'ACTIVE']);
        $warehouse = User::factory()->warehouse()->create();
        $admin = User::factory()->admin()->create();
        $department = Department::create(['department_code' => 'OPS', 'department_name' => 'Operations']);
        $unit = Unit::firstOrFail();
        $location = Location::firstOrFail();
        $consignment = Item::create(['item_code' => 'MR-CONS', 'part_number' => 'MR-CONS-PN', 'item_name' => 'Consignment', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 1]);
        $stock = Item::create(['item_code' => 'MR-STOCK', 'part_number' => 'MR-STOCK-PN', 'item_name' => 'Stock', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'minimum_stock' => 1]);
        StockItemLocation::create(['item_id' => $stock->id, 'location_id' => $location->id]);
        $vendor = Vendor::create(['vendor_code' => 'MRV', 'vendor_name' => 'MR Vendor']);
        $relation = VendorItem::create(['item_id' => $consignment->id, 'vendor_id' => $vendor->id, 'location_id' => $location->id]);
        app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'test', (string) Str::uuid(), CarbonImmutable::now(), $admin->id, (string) Str::uuid(), 'Seed', [new StockPostingLineData($consignment->id, $relation->id, $location->id, StockDirection::In, '5')]));
        $request = app(CreateMaterialRequest::class)->handle(['applicant_name' => 'Budi', 'applicant_nrp' => 'NRP-1', 'applicant_phone' => '08123', 'department_id' => $department->id, 'needed_at' => now()->addDay(), 'purpose' => 'Maintenance'], [['item_id' => $consignment->id, 'quantity' => 4], ['item_id' => $stock->id, 'quantity' => 2]]);

        return [$request, $warehouse, $admin, $consignment, $stock, $relation, $location];
    }
}
