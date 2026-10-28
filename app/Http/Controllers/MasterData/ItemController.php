<?php

namespace App\Http\Controllers\MasterData;

use App\Actions\MasterData\SaveItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ItemIndexRequest;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\Vendor;
use App\Queries\ItemListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ItemController extends Controller
{
    public function index(ItemIndexRequest $request, ItemListQuery $query)
    {
        $filters = [...['q' => '', 'status' => 'all', 'ownership' => 'all', 'sort' => 'item_code', 'direction' => 'asc', 'per_page' => 10], ...$request->safe()->except('page')];
        $filters['q'] = $filters['q'] ?? '';
        $items = $query->make($filters)->with('unit')->withCount(['vendorItems as vendor_count' => fn ($query) => $query->active()])->paginate((int) $filters['per_page'])->withQueryString();
        if ($items->currentPage() > $items->lastPage()) {
            return to_route('items.index', [...$filters, 'page' => $items->lastPage()]);
        }
        $items->through(fn (Item $item) => [...$item->only('id', 'item_code', 'part_number', 'item_name', 'minimum_stock', 'is_active'),
            'ownership_type' => $item->ownership_type->value, 'unit_code' => $item->unit->code, 'vendor_count' => $item->vendor_count,
            'show_url' => route('items.show', $item), 'edit_url' => route('items.edit', $item), 'status_url' => route('items.status', $item), 'can_update' => $request->user()->can('update', $item)]);
        $total = Item::count();
        $active = Item::active()->count();

        return Inertia::render('MasterData/Items/Index', ['items' => $items, 'filters' => $filters, 'summary' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active],
            'can' => ['create' => $request->user()->can('create', Item::class)], 'create_url' => route('items.create'), 'export_url' => route('items.export', $request->validated())]);
    }

    public function create()
    {
        Gate::authorize('create', Item::class);

        return $this->form();
    }

    public function edit(Item $item)
    {
        Gate::authorize('update', $item);

        return $this->form($item);
    }

    public function show(Request $request, Item $item)
    {
        Gate::authorize('view', $item);

        return Inertia::render('MasterData/Items/Show', ['item' => $this->serialize($item), 'can_update' => $request->user()->can('update', $item), 'edit_url' => route('items.edit', $item)]);
    }

    public function store(Request $request, SaveItem $save)
    {
        Gate::authorize('create', Item::class);
        $item = $save->handle($request->all());

        return to_route('items.show', $item)->with('success', 'Barang berhasil ditambahkan.');
    }

    public function update(Request $request, Item $item, SaveItem $save)
    {
        Gate::authorize('update', $item);
        $save->handle($request->all(), $item);

        return to_route('items.show', $item)->with('success', 'Perubahan barang berhasil disimpan.');
    }

    public function status(Request $request, Item $item, SaveItem $save)
    {
        Gate::authorize('update', $item);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $save->handle([...$this->payload($item), 'is_active' => $data['is_active']], $item);

        return to_route('items.index', Arr::only($request->query(), ['q', 'status', 'ownership', 'sort', 'direction', 'per_page', 'page']))->with('success', 'Status barang berhasil diubah.');
    }

    public function payload(Item $item): array
    {
        return [...$item->only('part_number', 'item_name', 'unit_id', 'minimum_stock', 'is_active'), 'ownership_type' => $item->ownership_type->value, 'tracking_type' => $item->tracking_type->value,
            'vendor_items' => $item->vendorItems()->get()->map->only(['vendor_id', 'location_id', 'is_active'])->all(),
            'stock_locations' => $item->stockItemLocations()->get()->map->only(['location_id', 'is_active'])->all()];
    }

    private function form(?Item $item = null)
    {
        return Inertia::render('MasterData/Items/Form', ['item' => $item ? $this->serialize($item) : null, 'submit_url' => $item ? route('items.update', $item) : route('items.store'),
            'options' => [
                'units' => Unit::query()->where(fn ($query) => $query->active()->when($item, fn ($query) => $query->orWhere('id', $item->unit_id)))->orderBy('code')->get(['id', 'code', 'name', 'is_active']),
                'vendors' => Vendor::query()->where(fn ($query) => $query->active()->when($item, fn ($query) => $query->orWhereIn('id', $item->vendorItems()->pluck('vendor_id'))))->orderBy('vendor_code')->get(['id', 'vendor_code', 'vendor_name', 'is_active']),
                'locations' => Location::query()->where(fn ($query) => $query->active()->when($item, fn ($query) => $query->orWhereIn('id', $item->vendorItems()->pluck('location_id'))->orWhereIn('id', $item->stockItemLocations()->pluck('location_id'))))->orderBy('location_code')->get(['id', 'location_code', 'location_name', 'is_active']),
                'unassigned_id' => Location::where('location_code', Location::UNASSIGNED)->value('id'),
            ]]);
    }

    private function serialize(Item $item): array
    {
        $item->load(['unit', 'vendorItems.vendor', 'vendorItems.location', 'stockItemLocations.location']);

        return [...$item->only('id', 'item_code', 'part_number', 'item_name', 'unit_id', 'minimum_stock', 'is_active'), 'ownership_type' => $item->ownership_type->value, 'tracking_type' => $item->tracking_type->value,
            'unit' => $item->unit->only('id', 'code', 'name', 'is_active'),
            'vendor_items' => $item->vendorItems->map(fn ($relation) => [...$relation->only('id', 'vendor_id', 'location_id', 'is_active'), 'vendor' => $relation->vendor->only('vendor_code', 'vendor_name', 'pic_name', 'is_active'), 'location' => $relation->location->only('location_code', 'location_name', 'is_active')])->all(),
            'stock_locations' => $item->stockItemLocations->map(fn ($relation) => [...$relation->only('id', 'location_id', 'is_active'), 'location' => $relation->location->only('location_code', 'location_name', 'is_active')])->all()];
    }
}
