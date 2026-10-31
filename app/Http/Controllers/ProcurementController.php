<?php

namespace App\Http\Controllers;

use App\Actions\Procurement\ApplyProcurementImport;
use App\Actions\Procurement\StoreProcurementDocument;
use App\Actions\Procurement\ValidateProcurementRequirement;
use App\Models\ProcurementDocument;
use App\Models\ProcurementRequirement;
use App\Services\Procurement\WorkbookExporter;
use App\Services\Procurement\WorkbookReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->scoped($request)->with(['vendorItem.vendor', 'issueLine.requestLine.item', 'documentLines.reference', 'validations', 'documents'])->latest();

        return Inertia::render('Procurement/Index', ['requirements' => $query->paginate(20)->through(fn ($row) => ['id' => $row->id, 'row_key' => $row->row_key, 'vendor' => $row->vendorItem->vendor->vendor_name, 'item' => $row->issueLine->requestLine->item->item_code.' · '.$row->issueLine->requestLine->item->item_name, 'quantity' => $row->requiredQuantity(), 'status' => $row->status, 'references' => $row->documentLines->where('is_active', true)->values(), 'documents' => $row->documents->when($request->user()->isVendor(), fn ($documents) => $documents->where('is_vendor_visible', true))->values()->map(fn ($document) => ['id' => $document->id, 'name' => $document->original_name, 'type' => $document->type, 'version' => $document->version, 'download_url' => route('procurement.documents.download', $document)])]), 'role' => $request->user()->role->value, 'urls' => ['export' => route('procurement.export'), 'import' => route('procurement.import')]]);
    }

    public function export(Request $request, WorkbookExporter $exporter): StreamedResponse
    {
        $sheet = $exporter->export($this->scoped($request)->with(['vendorItem.vendor', 'issueLine.requestLine.item'])->get());

        return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), 'procurement-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function import(Request $request, WorkbookReader $reader, ApplyProcurementImport $apply)
    {
        abort_if($request->user()->isVendor(), 403);
        $data = $request->validate(['stage' => ['required', 'in:RESERVATION,PR,PO,GRGI'], 'file' => ['required', 'file', 'mimes:xlsx', 'max:5120']]);
        $apply->handle($data['stage'], $reader->read($data['file']));

        return back()->with('success', 'Referensi Procurement berhasil diimpor.');
    }

    public function validateRequirement(Request $request, ProcurementRequirement $requirement, ValidateProcurementRequirement $action)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['decision' => ['required', 'in:VALID,REVISE,REJECT'], 'note' => ['nullable', 'string', 'max:2000']]);
        $action->handle($requirement, $data['decision'], $data['note'] ?? null, $request->user());

        return back()->with('success', 'Validasi Procurement tersimpan.');
    }

    public function storeDocument(Request $request, ProcurementRequirement $requirement, StoreProcurementDocument $action)
    {
        abort_if($request->user()->isVendor(), 403);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240'], 'type' => ['required', 'string', 'max:50'], 'vendor_visible' => ['sometimes', 'boolean']]);
        $action->handle($requirement, $data['file'], $data['type'], $request->boolean('vendor_visible'), $request->user());

        return back()->with('success', 'Dokumen Procurement tersimpan.');
    }

    public function download(Request $request, ProcurementDocument $document)
    {
        $document->load('requirement.vendorItem');
        if ($request->user()->isVendor()) {
            abort_unless($document->is_vendor_visible && $document->requirement->vendorItem->vendor_id === $request->user()->vendor_id, 404);
        }
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    private function scoped(Request $request)
    {
        return ProcurementRequirement::query()->when($request->user()->isVendor(), fn ($query) => $query->whereHas('vendorItem', fn ($vendorItem) => $vendorItem->where('vendor_id', $request->user()->vendor_id)));
    }
}
