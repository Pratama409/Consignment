<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Shifts\SaveShiftDefinition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveShiftDefinitionRequest;
use App\Models\ShiftDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftDefinitionController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->isVendor(), 403);
        $today = CarbonImmutable::now(ShiftDefinition::TIMEZONE)->toDateString();
        $serialize = fn (ShiftDefinition $definition) => [
            'id' => $definition->id, 'code' => $definition->code, 'name' => $definition->name,
            'start_time' => substr($definition->start_time, 0, 5), 'end_time' => substr($definition->end_time, 0, 5),
            'effective_from' => $definition->effective_from->toDateString(),
        ];
        $definitions = ShiftDefinition::orderBy('effective_from')->orderBy('code')->get();
        $current = $definitions->filter(fn (ShiftDefinition $definition) => $definition->effective_from->toDateString() <= $today)
            ->groupBy('code')->map(fn ($versions) => $serialize($versions->last()))->values();
        $manage = $request->user()->isAdmin();

        return Inertia::render('Settings/Shifts/Index', [
            'definitions' => $current,
            'timezone' => ShiftDefinition::TIMEZONE,
            'can_manage' => $manage,
            ...($manage ? [
                'versions' => $definitions->reverse()->values()->map($serialize),
                'next_date' => CarbonImmutable::parse(max($today, $definitions->max(fn (ShiftDefinition $definition) => $definition->effective_from->toDateString())), ShiftDefinition::TIMEZONE)->addDay()->toDateString(),
                'submit_url' => route('shifts.store'),
            ] : []),
        ]);
    }

    public function store(SaveShiftDefinitionRequest $request, SaveShiftDefinition $save)
    {
        $save->handle($request->only('code', 'name', 'start_time', 'end_time', 'effective_from'), $request->user());

        return to_route('shifts.index')->with('success', 'Versi shift mendatang berhasil disimpan.');
    }
}
