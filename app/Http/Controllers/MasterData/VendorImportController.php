<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\MasterDataImport;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

class VendorImportController extends Controller
{
    public function create(Request $request, ?MasterDataImport $import = null)
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($import) {
            abort_unless($import->user_id === $request->user()->id && str_starts_with($import->status, 'vendor_'), 404);
        }

        return Inertia::render('MasterData/Vendors/Import', ['batch' => $import, 'urls' => ['store' => route('vendors.import.store'), 'index' => route('vendors.index')]]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']]);
        $sheet = IOFactory::load($data['file']->getRealPath())->getActiveSheet();
        $header = array_map(fn ($value) => mb_strtoupper(trim((string) $value)), $sheet->rangeToArray('A1:B1')[0]);
        if ($header !== ['NAMA VENDOR', 'PIC']) {
            throw ValidationException::withMessages(['file' => 'Header wajib NAMA VENDOR dan PIC.']);
        }
        $rows = [];
        $seen = [];
        for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
            $nameCell = $sheet->getCell("A{$line}");
            $picCell = $sheet->getCell("B{$line}");
            if ($nameCell->getDataType() === DataType::TYPE_FORMULA || $picCell->getDataType() === DataType::TYPE_FORMULA) {
                throw ValidationException::withMessages(['file' => "Formula tidak diizinkan pada baris {$line}."]);
            }
            $name = trim((string) $nameCell->getValue());
            $pic = trim((string) $picCell->getValue());
            if ($name === '' && $pic === '') {
                continue;
            }
            $key = mb_strtoupper(preg_replace('/\s+/', ' ', $name));
            if ($name === '' || isset($seen[$key]) || $key === 'WAREHOUSE') {
                throw ValidationException::withMessages(['file' => "Nama Vendor kosong, duplikat, atau terlarang pada baris {$line}."]);
            }
            $seen[$key] = true;
            $vendor = Vendor::whereRaw('UPPER(vendor_name) = ?', [$key])->first();
            $rows[] = ['row' => $line, 'vendor_name' => $name, 'pic_name' => $pic === '' || $pic === '-' ? null : $pic, 'action' => $vendor ? 'UPDATE' : 'CREATE', 'vendor_id' => $vendor?->id, 'proposed_code' => $vendor?->vendor_code ?? $this->uniqueCode($name)];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi data Vendor.']);
        }
        $import = MasterDataImport::create(['user_id' => $request->user()->id, 'file_name' => $data['file']->getClientOriginalName(), 'file_hash' => hash_file('sha256', $data['file']->getRealPath()), 'status' => 'vendor_preview', 'data' => ['rows' => $rows], 'options' => [], 'expires_at' => now()->addDay()]);

        return redirect()->route('vendors.import.show', $import);
    }

    public function confirm(Request $request, MasterDataImport $import)
    {
        abort_unless($request->user()->isAdmin() && $import->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($import) {
            $batch = MasterDataImport::lockForUpdate()->findOrFail($import->id);
            if ($batch->status !== 'vendor_preview' || $batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['file' => 'Preview sudah diproses atau kedaluwarsa.']);
            }
            foreach ($batch->data['rows'] as $row) {
                if ($row['action'] === 'UPDATE') {
                    Vendor::findOrFail($row['vendor_id'])->update(['pic_name' => $row['pic_name']]);
                } else {
                    Vendor::create(['vendor_code' => $row['proposed_code'], 'vendor_name' => $row['vendor_name'], 'pic_name' => $row['pic_name'], 'is_active' => true]);
                }
            }
            $batch->update(['status' => 'vendor_applied', 'confirmed_at' => now(), 'result' => ['rows' => count($batch->data['rows'])]]);
        });

        return to_route('vendors.index')->with('success', 'Vendor dan PIC berhasil diimpor.');
    }

    private function uniqueCode(string $name): string
    {
        $base = substr(Str::upper(Str::slug($name)), 0, 50) ?: 'VENDOR';
        $code = $base;
        for ($suffix = 2; Vendor::where('vendor_code', $code)->exists(); $suffix++) {
            $code = substr($base, 0, 46).'-'.$suffix;
        }

        return $code;
    }
}
