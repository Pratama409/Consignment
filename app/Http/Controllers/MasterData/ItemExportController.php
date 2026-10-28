<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ItemIndexRequest;
use App\Models\Item;
use App\Queries\ItemListQuery;
use App\Services\MasterData\WorkbookExporter;
use Illuminate\Support\Facades\Gate;

class ItemExportController extends Controller
{
    public function index(ItemIndexRequest $request, ItemListQuery $query, WorkbookExporter $exporter)
    {
        return $exporter->download($query->make($request->validated()));
    }

    public function template(WorkbookExporter $exporter)
    {
        Gate::authorize('create', Item::class);

        return $exporter->download(template: true);
    }
}
