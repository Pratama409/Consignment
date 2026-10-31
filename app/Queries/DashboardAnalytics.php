<?php

namespace App\Queries;

use App\Enums\StockOpnameStatus;
use App\Models\Item;
use App\Models\ProcurementRequirement;
use App\Models\StockOpnameSession;
use App\Models\Vendor;
use App\Models\VendorRefill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Real, ledger-backed dashboard figures. Quantities only: the system holds no prices, so no value or ITO is shown. */
class DashboardAnalytics
{
    private const TIMEZONE = 'Asia/Makassar';

    public function __construct(private ?string $vendorId = null) {}

    public static function for(?string $vendorId): self
    {
        return new self($vendorId);
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        $levels = $this->levels();
        $refills = VendorRefill::query()->whereNotIn('status', ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'])->when($this->vendorId, fn ($query) => $query->where('vendor_id', $this->vendorId));
        $procurement = ProcurementRequirement::query()->whereNotIn('status', ['COMPLETE', 'VOID'])
            ->when($this->vendorId, fn ($query) => $query->whereHas('vendorItem', fn ($pair) => $pair->where('vendor_id', $this->vendorId)));

        return [
            'total_items' => $this->vendorId ? Item::active()->whereHas('vendorItems', fn ($pair) => $pair->active()->where('vendor_id', $this->vendorId))->count() : Item::active()->count(),
            'total_stock' => number_format((float) $this->balances()->sum('sb.quantity'), 4, '.', ''),
            'low_stock' => $levels['low']->count(),
            'critical_stock' => $levels['critical']->count(),
            'active_vendors' => $this->vendorId ? null : Vendor::active()->count(),
            'open_refills' => $refills->count(),
            'procurement_outstanding' => $procurement->count(),
            'open_stock_opname' => $this->vendorId ? null : StockOpnameSession::whereIn('status', collect(StockOpnameStatus::cases())->filter->isOpen()->map->value->all())->count(),
        ];
    }

    /** @return array{low: Collection, critical: Collection} items against their minimum stock, worst first */
    public function levels(): array
    {
        $totals = $this->balances()
            ->selectRaw('COALESCE(b.item_id, vi.item_id) as item_id, SUM(sb.quantity) as quantity')
            ->groupBy(DB::raw('COALESCE(b.item_id, vi.item_id)'))->pluck('quantity', 'item_id');

        $rows = Item::active()->where('minimum_stock', '>', 0)
            ->when($this->vendorId, fn ($query) => $query->whereHas('vendorItems', fn ($pair) => $pair->active()->where('vendor_id', $this->vendorId)))
            ->orderBy('item_code')->get(['id', 'item_code', 'item_name', 'minimum_stock'])
            ->map(function (Item $item) use ($totals) {
                $quantity = number_format((float) ($totals[$item->id] ?? 0), 4, '.', '');
                $minimum = number_format((float) $item->minimum_stock, 4, '.', '');

                return ['code' => $item->item_code, 'name' => $item->item_name, 'quantity' => $quantity, 'minimum' => $minimum,
                    'percent' => (int) min(100, max(0, floor((float) $quantity / (float) $minimum * 100))), 'short' => bccomp($quantity, $minimum, 4) < 0];
            })->filter(fn (array $row) => $row['short'])->sortBy('percent')->values();

        return [
            'low' => $rows->filter(fn (array $row) => bccomp($row['quantity'], '0', 4) > 0)->values(),
            'critical' => $rows->filter(fn (array $row) => bccomp($row['quantity'], '0', 4) <= 0)->values(),
        ];
    }

    /** @return array<string, Collection> */
    public function lists(): array
    {
        $levels = $this->levels();
        $strip = fn (Collection $rows) => $rows->take(6)->map(fn (array $row) => collect($row)->except('short')->all())->values();

        return [
            'low_stock' => $strip($levels['low']),
            'critical_stock' => $strip($levels['critical']),
            'procurement_by_vendor' => $this->vendorId ? collect() : $this->procurementByVendor(),
            'refill_queue' => $this->refillQueue(),
        ];
    }

    /** @return Collection<int, array{date: string, in: int|float, out: int|float}> the last 30 local days, oldest first */
    public function movement(): Collection
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $days = collect(range(29, 0))->map(fn (int $ago) => $today->subDays($ago)->format('Y-m-d'));
        $totals = $days->mapWithKeys(fn (string $day) => [$day => ['in' => 0.0, 'out' => 0.0]])->all();

        DB::table('stock_ledger_entries as e')
            ->join('stock_transactions as t', 't.id', '=', 'e.stock_transaction_id')
            ->join('stock_buckets as b', 'b.id', '=', 'e.stock_bucket_id')
            ->leftJoin('vendor_items as vi', 'vi.id', '=', 'b.vendor_item_id')
            ->where('t.status', 'POSTED')->where('t.type', '!=', 'OPENING')
            ->where('t.posted_at', '>=', $today->subDays(29)->utc())
            ->when($this->vendorId, fn ($query) => $query->where('vi.vendor_id', $this->vendorId))
            ->select('t.posted_at', 'e.quantity')->orderBy('t.posted_at')
            ->get()->each(function ($row) use (&$totals) {
                $day = CarbonImmutable::parse($row->posted_at, 'UTC')->setTimezone(self::TIMEZONE)->format('Y-m-d');
                if (! isset($totals[$day])) {
                    return;
                }
                $quantity = (float) $row->quantity;
                $totals[$day][$quantity >= 0 ? 'in' : 'out'] += abs($quantity);
            });

        return $days->map(fn (string $day) => ['date' => $day, 'in' => $this->number($totals[$day]['in']), 'out' => $this->number($totals[$day]['out'])]);
    }

    /** @return Collection<int, array{label: string, value: int|float}> stock by Vendor (internal) or by location (Vendor), top five plus the rest */
    public function composition(): Collection
    {
        $label = $this->vendorId ? 'l.location_name' : "COALESCE(v.vendor_name, 'Stok internal')";
        $rows = $this->balances()
            ->join('locations as l', 'l.id', '=', 'b.location_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'vi.vendor_id')
            ->where('sb.quantity', '>', 0)
            ->selectRaw("{$label} as label, SUM(sb.quantity) as value")->groupBy(DB::raw($label))->orderByDesc('value')
            ->get()->map(fn ($row) => ['label' => $row->label, 'value' => $this->number((float) $row->value)]);

        $top = $rows->take(5);
        $rest = $rows->slice(5)->sum('value');

        return $rest > 0 ? $top->push(['label' => 'Lainnya', 'value' => $this->number((float) $rest)])->values() : $top->values();
    }

    private function balances()
    {
        return DB::table('stock_balances as sb')
            ->join('stock_buckets as b', 'b.id', '=', 'sb.stock_bucket_id')
            ->leftJoin('vendor_items as vi', 'vi.id', '=', 'b.vendor_item_id')
            ->when($this->vendorId, fn ($query) => $query->where('vi.vendor_id', $this->vendorId));
    }

    private function procurementByVendor(): Collection
    {
        return ProcurementRequirement::query()->whereNotIn('status', ['COMPLETE', 'VOID'])->with('vendorItem.vendor')->get()
            ->groupBy(fn (ProcurementRequirement $requirement) => $requirement->vendorItem?->vendor?->vendor_name ?? 'Tanpa Vendor')
            ->map(fn (Collection $group, string $vendor) => ['vendor' => $vendor, 'total' => $group->count()])
            ->sortByDesc('total')->values()->take(6);
    }

    private function refillQueue(): Collection
    {
        $labels = ['REQUESTED' => 'Menunggu Vendor', 'REVISION_PENDING' => 'Revisi diajukan', 'APPROVED' => 'Disetujui', 'SHIPPING' => 'Dalam pengiriman', 'PARTIAL' => 'Diterima sebagian'];
        $counts = VendorRefill::query()->whereIn('status', array_keys($labels))->when($this->vendorId, fn ($query) => $query->where('vendor_id', $this->vendorId))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $max = max(1, (int) $counts->max());

        return collect($labels)->map(fn (string $label, string $status) => ['label' => $label, 'total' => (int) ($counts[$status] ?? 0), 'percent' => (int) round(($counts[$status] ?? 0) / $max * 100)])->values();
    }

    private function number(float $value): int|float
    {
        return floor($value) === round($value, 4) ? (int) $value : round($value, 4);
    }
}
