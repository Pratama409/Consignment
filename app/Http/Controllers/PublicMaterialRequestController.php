<?php

namespace App\Http\Controllers;

use App\Actions\MaterialRequests\CreateMaterialRequest;
use App\Actions\MaterialRequests\VerifyPublicMaterialRequest;
use App\Models\Department;
use App\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicMaterialRequestController extends Controller
{
    public function create()
    {
        return Inertia::render('MaterialRequests/PublicCreate', ['departments' => Department::where('is_active', true)->orderBy('department_name')->get(['id', 'department_code', 'department_name']), 'items' => Item::active()->with('unit')->orderBy('item_code')->get()->map(fn ($item) => ['id' => $item->id, 'code' => $item->item_code, 'name' => $item->item_name, 'ownership' => $item->ownership_type->value, 'unit' => $item->unit->code]), 'store_url' => route('public.material-requests.store'), 'track_url' => route('public.material-requests.track')]);
    }

    public function store(Request $request, CreateMaterialRequest $action)
    {
        $data = $request->validate(['applicant_name' => ['required', 'string', 'max:150'], 'applicant_nrp' => ['required', 'string', 'max:50'], 'applicant_phone' => ['required', 'string', 'max:30'], 'department_id' => ['required', 'uuid', 'exists:departments,id'], 'needed_at' => ['required', 'date'], 'purpose' => ['required', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1']]);
        $data['needed_at'] = CarbonImmutable::parse($data['needed_at'], 'Asia/Makassar')->utc();
        $lines = $data['lines'];
        unset($data['lines']);
        $materialRequest = $action->handle($data, $lines);

        return to_route('public.material-requests.create')->with('success', "MR {$materialRequest->request_no} berhasil dikirim. Simpan nomor ini untuk pelacakan.");
    }

    public function track(Request $request, VerifyPublicMaterialRequest $action)
    {
        $data = $request->validate(['request_no' => ['required', 'string', 'max:60'], 'verifier' => ['required', 'string', 'max:50']]);
        $materialRequest = $action->handle($data['request_no'], $data['verifier']);

        return Inertia::render('MaterialRequests/PublicStatus', ['request' => $this->payload($materialRequest), 'create_url' => route('public.material-requests.create')]);
    }

    private function payload($request): array
    {
        return ['number' => $request->request_no, 'status' => $request->status, 'applicant' => $request->applicant_name, 'department' => $request->department->department_name, 'needed_at' => $request->needed_at->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'purpose' => $request->purpose, 'lines' => $request->lines->map(fn ($line) => ['item' => $line->item->item_code.' · '.$line->item->item_name, 'quantity' => (int) $line->requested_quantity, 'status' => $line->status])];
    }
}
