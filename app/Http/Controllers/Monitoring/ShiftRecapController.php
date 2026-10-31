<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\CloseOperationalShift;
use App\Actions\Monitoring\GenerateShiftRecap;
use App\Http\Controllers\Controller;
use App\Models\OperationalShift;
use App\Models\ShiftRecapSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftRecapController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->isVendor(), 403);

        return Inertia::render('Monitoring/Shifts/Index', ['shifts' => OperationalShift::latest('starts_at')->paginate(25)]);
    }

    public function show(Request $request, OperationalShift $shift, GenerateShiftRecap $generate)
    {
        abort_if($request->user()->isVendor(), 403);

        return Inertia::render('Monitoring/Shifts/Show', ['shift' => $shift, 'summary' => $generate->handle($shift), 'snapshots' => $shift->hasMany(ShiftRecapSnapshot::class)->latest('version')->get()]);
    }

    public function close(Request $request, OperationalShift $shift, CloseOperationalShift $close)
    {
        $close->handle($shift, $request->user());

        return back()->with('success', 'Rekap shift berhasil ditutup dan dibekukan.');
    }
}
