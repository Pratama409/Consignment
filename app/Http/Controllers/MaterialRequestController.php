<?php

namespace App\Http\Controllers;

use App\Actions\MaterialRequests\ApproveMaterialRequest;
use App\Actions\MaterialRequests\CreateMaterialIssue;
use App\Actions\MaterialRequests\ReviseMaterialRequest;
use App\Actions\MaterialRequests\SetMaterialRequestLineReadiness;
use App\Models\Location;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MaterialRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRole($request);

        return Inertia::render('MaterialRequests/Index', ['requests' => MaterialRequest::with('department')->latest()->paginate(20)->through(fn ($mr) => ['id' => $mr->id, 'number' => $mr->request_no, 'applicant' => $mr->applicant_name, 'department' => $mr->department->department_name, 'status' => $mr->status, 'needed_at' => $mr->needed_at->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'show_url' => route('material-requests.show', $mr)])]);
    }

    public function show(Request $request, MaterialRequest $materialRequest)
    {
        $this->authorizeRole($request);
        $materialRequest->load(['department', 'lines.item.unit', 'lines.item.vendorItems.vendor', 'lines.item.vendorItems.location', 'lines.issueLines.issue', 'approvals.decidedBy', 'issues.lines']);

        $currentApproval = $materialRequest->approvals->sortByDesc('revision_no')->first();

        return Inertia::render('MaterialRequests/Show', ['role' => $request->user()->role->value, 'request' => ['id' => $materialRequest->id, 'number' => $materialRequest->request_no, 'status' => $materialRequest->status, 'applicant' => $materialRequest->applicant_name, 'nrp' => $materialRequest->applicant_nrp, 'phone' => $materialRequest->applicant_phone, 'department' => $materialRequest->department->department_name, 'needed_at' => $materialRequest->needed_at->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'purpose' => $materialRequest->purpose, 'approve_url' => route('material-requests.approve', $materialRequest), 'revision_url' => route('material-requests.revisions.store', $materialRequest), 'issue_url' => route('material-requests.issues.store', $materialRequest), 'idempotency_key' => (string) Str::uuid(), 'current_revision_no' => (int) ($currentApproval?->revision_no ?? 0), 'approvals' => $materialRequest->approvals, 'issues' => $materialRequest->issues, 'lines' => $materialRequest->lines->map(function ($line): array {
            $issued = (int) $line->issueLines->filter(fn ($row) => $row->issue->status === 'POSTED')->sum('issued_quantity');
            $effective = $line->effectiveQuantity();

            return ['id' => $line->id, 'item' => $line->item->item_code.' · '.$line->item->item_name, 'ownership' => $line->item->ownership_type->value, 'original_quantity' => (int) $line->requested_quantity, 'quantity' => $effective, 'issued' => $issued, 'remaining' => max(0, $effective - $issued), 'status' => $line->status, 'readiness_url' => route('material-request-lines.readiness', $line), 'buckets' => $line->item->vendorItems->where('is_active', true)->map(fn ($relation) => ['vendor_item_id' => $relation->id, 'location_id' => $relation->location_id, 'label' => $relation->vendor->vendor_name.' · '.$relation->location->location_name])->values()];
        })], 'locations' => Location::active()->get(['id', 'location_code', 'location_name'])]);
    }

    public function readiness(Request $request, MaterialRequestLine $line, SetMaterialRequestLineReadiness $action)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        $data = $request->validate(['status' => ['required', 'in:PREPARING,READY']]);
        $action->handle($line, $request->user(), $data['status']);

        return back()->with('success', 'Status kesiapan diperbarui.');
    }

    public function approve(Request $request, MaterialRequest $materialRequest, ApproveMaterialRequest $action)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000'], 'revision_no' => ['required', 'integer', 'min:0']]);
        $action->handle($materialRequest, $request->user(), $request->boolean('approve'), $data['note'] ?? null, $data['revision_no']);

        return back()->with('success', 'Keputusan approval tersimpan.');
    }

    public function revise(Request $request, MaterialRequest $materialRequest, ReviseMaterialRequest $action)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.line_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1']]);
        $action->handle($materialRequest, $data['reason'], $data['lines'], $request->user());

        return back()->with('success', 'Revisi MR tersimpan dan memerlukan approval ulang.');
    }

    public function issue(Request $request, MaterialRequest $materialRequest, CreateMaterialIssue $action)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        $data = $request->validate(['receiver_name' => ['required', 'string', 'max:150'], 'issued_at' => ['required', 'date'], 'idempotency_key' => ['required', 'string', 'max:100'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.line_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1'], 'lines.*.vendor_item_id' => ['nullable', 'uuid'], 'lines.*.location_id' => ['nullable', 'uuid']]);
        $action->handle($materialRequest, $request->user(), $data['receiver_name'], CarbonImmutable::parse($data['issued_at'], 'Asia/Makassar'), $data['lines'], $data['idempotency_key']);

        return back()->with('success', 'Data Out berhasil diposting.');
    }

    private function authorizeRole(Request $request): void
    {
        abort_unless($request->user()->isAdmin() || $request->user()->isWarehouse(), 403);
    }
}
