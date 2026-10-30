<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Reconciliation\FinalizeVendorReconciliation;
use App\Actions\Reconciliation\GenerateVendorReconciliation;
use App\Actions\Reconciliation\ResolveVendorDispute;
use App\Actions\Reconciliation\SubmitVendorResponse;
use App\Actions\Reconciliation\UpdatePhysicalQuantity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reconciliation\VendorResponseRequest;
use App\Models\Vendor;
use App\Models\VendorReconciliation;
use App\Models\VendorReconciliationDocument;
use App\Models\VendorReconciliationLine;
use App\Services\Reconciliation\ReconciliationExcelExporter;
use App\Services\Reconciliation\ReconciliationPdfExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class VendorReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $query = VendorReconciliation::with('vendor')->latest();
        if ($request->user()->isVendor()) {
            $query->where('vendor_id', $request->user()->vendor_id);
        }

        return Inertia::render('Monitoring/Reconciliations/Index', ['reconciliations' => $query->paginate(25), 'vendors' => $request->user()->isAdmin() ? Vendor::active()->get(['id', 'vendor_code', 'vendor_name']) : [], 'can_generate' => $request->user()->isAdmin()]);
    }

    public function generate(Request $request, GenerateVendorReconciliation $generate)
    {
        $data = $request->validate(['vendor_id' => ['required', 'uuid', 'exists:vendors,id'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start']]);
        $ba = $generate->handle(Vendor::findOrFail($data['vendor_id']), CarbonImmutable::parse($data['period_start']), CarbonImmutable::parse($data['period_end']), $request->user());

        return redirect()->route('monitoring.reconciliations.show', $ba)->with('success', 'BA berhasil dibuat.');
    }

    public function show(Request $request, VendorReconciliation $reconciliation)
    {
        $this->authorizeView($request, $reconciliation);

        return Inertia::render('Monitoring/Reconciliations/Show', ['reconciliation' => $reconciliation->load(['vendor', 'lines.item', 'lines.location', 'responses.actor', 'documents'])]);
    }

    public function respond(VendorResponseRequest $request, VendorReconciliation $reconciliation, SubmitVendorResponse $respond)
    {
        $data = $request->validated();
        $respond->handle($reconciliation, $request->boolean('agree'), $data['note'] ?? '', $request->user());

        return back()->with('success', 'Respons BA tersimpan.');
    }

    public function physical(Request $request, VendorReconciliationLine $line, UpdatePhysicalQuantity $update)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0']]);
        $update->handle($line, (string) $data['quantity'], $request->user());

        return back()->with('success', 'Quantity fisik diperbarui.');
    }

    public function resolve(Request $request, VendorReconciliation $reconciliation, ResolveVendorDispute $resolve)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $resolve->handle($reconciliation, $data['note'], $request->user());

        return back()->with('success', 'Selisih BA diselesaikan.');
    }

    public function finalize(Request $request, VendorReconciliation $reconciliation, FinalizeVendorReconciliation $finalize)
    {
        $finalize->handle($reconciliation, $request->user());

        return back()->with('success', 'BA berhasil difinalisasi.');
    }

    public function export(Request $request, VendorReconciliation $reconciliation, string $type, ReconciliationExcelExporter $excel, ReconciliationPdfExporter $pdf)
    {
        $this->authorizeView($request, $reconciliation);
        abort_unless(in_array($type, ['xlsx', 'pdf'], true), 404);
        $document = $type === 'xlsx' ? $excel->export($reconciliation, $request->user()) : $pdf->export($reconciliation, $request->user());

        return Storage::disk($document->disk)->download($document->path, $document->original_name, ['Content-Type' => $document->mime_type]);
    }

    public function download(Request $request, VendorReconciliationDocument $document)
    {
        $this->authorizeView($request, $document->reconciliation);

        return Storage::disk($document->disk)->download($document->path, $document->original_name, ['Content-Type' => $document->mime_type]);
    }

    private function authorizeView(Request $request, VendorReconciliation $ba): void
    {
        abort_if($request->user()->isVendor() && $request->user()->vendor_id !== $ba->vendor_id, 404);
    }
}
