<?php

namespace App\Http\Controllers\MasterData;

use App\Actions\MasterData\ConfirmImport;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use App\Models\MasterDataImport;
use App\Models\Unit;
use App\Services\MasterData\ImportPlanner;
use App\Services\MasterData\WorkbookExporter;
use App\Services\MasterData\WorkbookReader;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', Item::class);

        return Inertia::render('MasterData/Import/Index', ['recent' => MasterDataImport::where('user_id', $request->user()->id)->latest()->take(8)->get()->map(fn ($import) => [
            'id' => $import->id, 'file_name' => $import->file_name, 'status' => $import->status, 'source_rows' => count($import->data['rows']),
            'created_at' => $import->created_at->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'show_url' => route('imports.show', $import),
        ]), 'upload_url' => route('imports.store'), 'template_url' => route('imports.template')]);
    }

    public function store(Request $request, WorkbookReader $reader)
    {
        Gate::authorize('create', Item::class);
        $input = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120'], 'create_missing' => ['required', 'boolean'], 'minimum_stock' => ['required', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999.9999']], [
            'file.required' => 'Pilih file Master PN terlebih dahulu.', 'file.mimes' => 'Gunakan file Excel berformat XLSX.', 'file.max' => 'Ukuran file maksimal 5 MB.', 'minimum_stock.min' => 'Target refill tidak boleh negatif.',
        ]);
        $data = $reader->read($request->file('file'));
        $import = MasterDataImport::create(['user_id' => $request->user()->id, 'file_name' => basename(str_replace('\\', '/', $request->file('file')->getClientOriginalName())),
            'file_hash' => hash_file('sha256', $request->file('file')->getRealPath()), 'data' => $data,
            'options' => ['create_missing' => $request->boolean('create_missing'), 'minimum_stock' => (string) $input['minimum_stock'], 'unit_map' => []], 'expires_at' => now()->addDay()]);

        return to_route('imports.show', $import);
    }

    public function show(Request $request, MasterDataImport $import, ImportPlanner $planner)
    {
        $this->authorizeImport($request, $import);
        if ($import->status === 'completed') {
            return Inertia::render('MasterData/Import/Result', [
                'batch' => $this->batch($import), 'result' => [...$import->result, 'items' => collect($import->result['items'])->take(50)->map(fn ($item) => [...$item, 'show_url' => route('items.show', $item['id'])])->all()],
                'export_url' => route('imports.export', $import),
            ]);
        }
        if ($import->expires_at->isPast()) {
            return Inertia::render('MasterData/Import/Expired');
        }
        $input = $request->validate(['state' => ['sometimes', 'in:all,valid,review,invalid,skipped'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $plan = $planner->build($import);
        $filters = ['state' => $input['state'] ?? 'all', 'q' => trim($input['q'] ?? '')];
        $groups = collect($plan['groups'])->filter(fn ($group) => ($filters['state'] === 'all' || $group['state'] === $filters['state'])
            && ($filters['q'] === '' || str_contains(mb_strtoupper($group['data']['part_number'].' '.$group['data']['item_name'].' '.$group['target_code'].' '.implode(' ', array_column($group['rows'], 'number'))), mb_strtoupper($filters['q']))))->values();
        $page = min(max(1, (int) ($input['page'] ?? 1)), max(1, (int) ceil($groups->count() / 25)));
        $paginated = new LengthAwarePaginator($groups->forPage($page, 25)->values(), $groups->count(), 25, $page, ['path' => route('imports.show', $import), 'query' => $request->query()]);
        $paginated->through(function ($group) use ($import) {
            unset($group['unique_rows']);
            $group['decision_url'] = route('imports.decision', [$import, $group['id']]);
            foreach ($group['rows'] as &$row) {
                $row['correction_url'] = route('imports.correction', [$import, $row['number']]);
            }

            return $group;
        });
        $sourceUnits = collect($import->data['rows'])->map(fn ($row) => mb_strtoupper(trim($import->corrections[$row['number']]['UOM'] ?? $row['values']['UOM'] ?? '')))->unique()->sort()->values();

        return Inertia::render('MasterData/Import/Preview', [
            'batch' => $this->batch($import), 'groups' => $paginated, 'filters' => $filters, 'summary' => $plan['summary'],
            'references' => ['vendors' => array_slice($plan['new_vendors'], 0, 30), 'locations' => array_slice($plan['new_locations'], 0, 30)],
            'options' => $import->options, 'source_units' => $sourceUnits, 'units' => Unit::active()->orderBy('code')->get(['id', 'code', 'name']), 'categories' => Category::active()->orderBy('code')->get(['code', 'name']),
            'options_url' => route('imports.options', $import), 'confirm_url' => route('imports.confirm', $import),
        ]);
    }

    public function options(Request $request, MasterDataImport $import)
    {
        $this->authorizeImport($request, $import);
        $input = $request->validate(['create_missing' => ['required', 'boolean'], 'minimum_stock' => ['required', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999.9999'], 'unit_map' => ['sometimes', 'array'], 'unit_map.*' => ['nullable', 'uuid', 'exists:units,id']]);
        $input['unit_map'] = array_filter($input['unit_map'] ?? [], fn ($id) => $id !== null && $id !== '');

        return $this->change($request, $import, fn ($record) => $record->update(['options' => $input]));
    }

    public function decision(Request $request, MasterDataImport $import, string $group, ImportPlanner $planner)
    {
        $this->authorizeImport($request, $import);
        abort_unless(collect($planner->build($import)['groups'])->contains('id', $group), 404);
        $input = $request->validate(['action' => ['required', 'in:create,split,match,skip'], 'target_code' => ['nullable', 'required_if:action,match', 'string', 'max:50'], 'minimum_stock' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999.9999']]);
        $input['target_code'] = $input['action'] === 'match' ? mb_strtoupper(trim($input['target_code'])) : '';

        return $this->change($request, $import, function ($record) use ($group, $input) {
            $decisions = $record->decisions ?? [];
            $decisions[$group] = $input;
            $record->update(['decisions' => $decisions]);
        });
    }

    public function correction(Request $request, MasterDataImport $import, int $row)
    {
        $this->authorizeImport($request, $import);
        abort_unless(collect($import->data['rows'])->contains('number', $row), 404);
        $rules = ['skip' => ['required', 'boolean']];
        foreach (WorkbookReader::COLUMNS as $column) {
            $rules[$column] = ['sometimes', 'nullable', 'string', 'max:200'];
        }
        $input = $request->validate($rules);
        foreach ($input as $key => $value) {
            if ($key !== 'skip') {
                $input[$key] = trim((string) $value);
            }
        }

        return $this->change($request, $import, function ($record) use ($row, $input) {
            $corrections = $record->corrections ?? [];
            $corrections[$row] = $input;
            $record->update(['corrections' => $corrections]);
        });
    }

    public function confirm(Request $request, MasterDataImport $import, ConfirmImport $confirm)
    {
        $this->authorizeImport($request, $import);
        $request->validate(['acknowledge_skipped' => ['sometimes', 'boolean'], 'acknowledge_ignored_columns' => ['sometimes', 'boolean']]);
        try {
            $confirm->handle($import, $request->boolean('acknowledge_skipped'), $request->boolean('acknowledge_ignored_columns'));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['confirmation' => implode(' ', collect($exception->errors())->flatten()->all())]);
        }

        return to_route('imports.show', $import)->with('success', 'Import Master PN selesai.');
    }

    public function export(Request $request, MasterDataImport $import, WorkbookExporter $exporter)
    {
        $this->authorizeImport($request, $import);
        abort_unless($import->status === 'completed', 404);

        return $exporter->download(Item::whereIn('id', array_column($import->result['items'], 'id'))->orderBy('id'));
    }

    private function change(Request $request, MasterDataImport $import, callable $callback)
    {
        DB::transaction(function () use ($import, $callback) {
            $record = MasterDataImport::query()->lockForUpdate()->findOrFail($import->id);
            $this->ensureOpen($record);
            $callback($record);
        });

        return to_route('imports.show', [$import, ...$request->only(['state', 'q', 'page'])])->with('success', 'Tinjauan preview berhasil diperbarui.');
    }

    private function authorizeImport(Request $request, MasterDataImport $import): void
    {
        Gate::authorize('create', Item::class);
        abort_unless($import->user_id === $request->user()->id, 403);
    }

    private function ensureOpen(MasterDataImport $import): void
    {
        if ($import->status !== 'preview' || $import->expires_at->isPast()) {
            throw ValidationException::withMessages(['confirmation' => 'Preview tidak dapat diubah atau sudah kedaluwarsa. Unggah file baru untuk memulai kembali.']);
        }
    }

    private function batch(MasterDataImport $import): array
    {
        return ['id' => $import->id, 'file_name' => $import->file_name, 'status' => $import->status, 'show_url' => route('imports.show', $import), 'expires_at' => $import->expires_at->toIso8601String(), 'confirmed_at' => $import->confirmed_at?->toIso8601String(), 'ignored_columns' => $import->data['ignored_columns'] ?? []];
    }
}
