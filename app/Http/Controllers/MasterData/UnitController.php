<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\SaveUnitRequest;
use App\Http\Requests\MasterData\UnitIndexRequest;
use App\Http\Requests\MasterData\UpdateUnitStatusRequest;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(UnitIndexRequest $request): Response|RedirectResponse
    {
        $input = $request->validated();
        $filters = [
            'q' => trim($input['q'] ?? ''),
            'status' => $input['status'] ?? 'all',
            'per_page' => (int) ($input['per_page'] ?? 10),
            'sort' => $input['sort'] ?? 'code',
            'direction' => $input['direction'] ?? 'asc',
        ];
        $query = Unit::query()->withCount(['items as active_items_count' => fn ($query) => $query->active()]);

        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($query) => $query
                ->whereRaw("code LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("name LIKE ? ESCAPE '!'", [$term]));
        }

        if ($filters['status'] !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $units = $query->orderBy($filters['sort'], $filters['direction'])->orderBy('id')
            ->paginate($filters['per_page'])->withQueryString();

        if ($units->currentPage() > $units->lastPage()) {
            return to_route('units.index', [...$filters, 'page' => $units->lastPage()]);
        }

        $units->through(fn (Unit $unit): array => [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'is_active' => $unit->is_active,
            'active_items_count' => $unit->active_items_count,
            'can_update' => $request->user()->can('update', $unit),
            'update_url' => route('units.update', $unit),
            'status_url' => route('units.status', $unit),
        ]);
        $summary = Unit::query()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active')->first();

        return Inertia::render('MasterData/Units/Index', [
            'units' => $units,
            'filters' => $filters,
            'summary' => ['total' => (int) $summary->total, 'active' => (int) $summary->active, 'inactive' => (int) $summary->total - (int) $summary->active],
            'can' => ['create' => $request->user()->can('create', Unit::class)],
            'store_url' => route('units.store'),
        ]);
    }

    public function store(SaveUnitRequest $request): RedirectResponse
    {
        try {
            Unit::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode satuan sudah digunakan.']);
        }

        return $this->redirectToList($request, 'Satuan berhasil ditambahkan.');
    }

    public function update(SaveUnitRequest $request, Unit $unit): RedirectResponse
    {
        try {
            DB::transaction(fn () => Unit::query()->lockForUpdate()->findOrFail($unit->id)->update($request->validated()));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode satuan sudah digunakan.']);
        }

        return $this->redirectToList($request, 'Perubahan satuan berhasil disimpan.');
    }

    public function status(UpdateUnitStatusRequest $request, Unit $unit): RedirectResponse
    {
        DB::transaction(fn () => Unit::query()->lockForUpdate()->findOrFail($unit->id)->update($request->validated()));

        return $this->redirectToList($request, $request->boolean('is_active') ? 'Satuan berhasil diaktifkan.' : 'Satuan berhasil dinonaktifkan.');
    }

    private function redirectToList(Request $request, string $message): RedirectResponse
    {
        return to_route('units.index', Arr::only($request->query(), ['q', 'status', 'per_page', 'sort', 'direction', 'page']))
            ->with('success', $message);
    }
}
