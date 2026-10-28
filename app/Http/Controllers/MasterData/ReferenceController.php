<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ReferenceRequest;
use App\Models\Location;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ReferenceController extends Controller
{
    public function index(ReferenceRequest $request)
    {
        $resource = $request->route('resource');
        $definition = $request->definition();
        $model = $definition['model'];
        $input = $request->validated();
        $filters = ['q' => trim($input['q'] ?? ''), 'status' => $input['status'] ?? 'all', 'sort' => $input['sort'] ?? 'code', 'direction' => $input['direction'] ?? 'asc', 'per_page' => (int) ($input['per_page'] ?? 10)];
        $query = $model::query()->withCount(['vendorItems as vendor_usage_count' => fn ($query) => $query->active()]);
        if ($resource === 'locations') {
            $query->withCount(['stockItemLocations as stock_usage_count' => fn ($query) => $query->active()]);
        }
        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($query) => $query->whereRaw("{$definition['code']} LIKE ? ESCAPE '!'", [$term])->orWhereRaw("{$definition['name']} LIKE ? ESCAPE '!'", [$term]));
        }
        if ($filters['status'] !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        $records = $query->orderBy($definition[$filters['sort']], $filters['direction'])->orderBy('id')->paginate($filters['per_page'])->withQueryString();
        if ($records->currentPage() > $records->lastPage()) {
            return to_route($resource.'.index', [...$filters, 'page' => $records->lastPage()]);
        }
        $records->through(fn ($record) => [
            'id' => $record->id, 'code' => $record->{$definition['code']}, 'name' => $record->{$definition['name']}, 'pic_name' => $record->pic_name,
            'is_active' => $record->is_active, 'is_system' => $resource === 'locations' && $record->location_code === Location::UNASSIGNED,
            'usage_count' => (int) $record->vendor_usage_count + (int) ($record->stock_usage_count ?? 0),
            'can_update' => $request->user()->can('update', $record), 'update_url' => route($resource.'.update', $record->id), 'status_url' => route($resource.'.status', $record->id),
        ]);
        $total = $model::count();
        $active = $model::active()->count();

        return Inertia::render('MasterData/References/Index', [
            'resource' => $resource, 'records' => $records, 'filters' => $filters,
            'summary' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active],
            'can' => ['create' => $request->user()->can('create', $model)], 'store_url' => route($resource.'.store'),
        ]);
    }

    public function store(ReferenceRequest $request)
    {
        return $this->save($request);
    }

    public function update(ReferenceRequest $request)
    {
        return $this->save($request);
    }

    public function status(ReferenceRequest $request)
    {
        return $this->save($request);
    }

    private function save(ReferenceRequest $request)
    {
        $definition = $request->definition();
        $data = $request->validated();
        $attributes = $request->isMethod('patch') ? $data : [
            $definition['code'] => $data['code'], $definition['name'] => $data['name'] ?? null, 'is_active' => $data['is_active'],
        ];
        if (! $request->isMethod('patch') && $request->route('resource') === 'vendors') {
            $attributes['pic_name'] = $data['pic_name'] ?? null;
        }
        try {
            DB::transaction(function () use ($request, $definition, $attributes) {
                $record = $request->route('record') ? $definition['model']::query()->lockForUpdate()->findOrFail($request->route('record')) : new ($definition['model']);
                $record->fill($attributes)->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode sudah digunakan.']);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$field === $definition['code'] ? ($request->isMethod('patch') ? 'is_active' : 'code') : ($field === $definition['name'] ? 'name' : $field)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        return to_route($request->route('resource').'.index', Arr::only($request->query(), ['q', 'status', 'per_page', 'sort', 'direction', 'page']))->with('success', $definition['label'].' berhasil disimpan.');
    }
}
