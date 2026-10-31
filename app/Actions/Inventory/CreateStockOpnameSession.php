<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockOpnameScope;
use App\Enums\StockOpnameStatus;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStockOpnameSession
{
    public function __construct(private WriteAuditLog $audit) {}

    /** @param array{scope_type?: string, cycle_month?: ?string, bucket_ids?: array<int, string>, note?: ?string} $data */
    public function handle(array $data, User $admin): StockOpnameSession
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat membuat sesi Stock Opname.']);
        }
        $scope = StockOpnameScope::tryFrom((string) ($data['scope_type'] ?? ''));
        if ($scope === null) {
            throw ValidationException::withMessages(['scope_type' => 'Jenis Stock Opname tidak valid.']);
        }
        $cycleMonth = $scope === StockOpnameScope::Cycle ? trim((string) ($data['cycle_month'] ?? '')) : null;
        if ($scope === StockOpnameScope::Cycle && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $cycleMonth) !== 1) {
            throw ValidationException::withMessages(['cycle_month' => 'Bulan cycle count wajib diisi dengan format YYYY-MM.']);
        }

        $bucketIds = $scope === StockOpnameScope::Full
            ? StockBalance::where('quantity', '>', 0)->pluck('stock_bucket_id')->unique()->values()->all()
            : array_values(array_unique(array_map('strval', $data['bucket_ids'] ?? [])));
        if ($bucketIds === [] || StockBucket::whereIn('id', $bucketIds)->count() !== count($bucketIds)) {
            throw ValidationException::withMessages(['bucket_ids' => 'Pilih minimal satu posisi stok yang valid untuk dihitung.']);
        }

        return DB::transaction(function () use ($admin, $scope, $cycleMonth, $bucketIds, $data): StockOpnameSession {
            // Creators sharing a bucket serialize here, so two sessions can never both claim it.
            StockBucket::whereIn('id', $bucketIds)->orderBy('id')->lockForUpdate()->get();
            $taken = DB::table('stock_opname_lines as l')
                ->join('stock_opname_sessions as s', 's.id', '=', 'l.stock_opname_session_id')
                ->whereIn('l.stock_bucket_id', $bucketIds)
                ->whereIn('s.status', collect(StockOpnameStatus::cases())->filter->isOpen()->map->value->all())
                ->lockForUpdate()->distinct()->pluck('s.session_no');
            if ($taken->isNotEmpty()) {
                throw ValidationException::withMessages(['bucket_ids' => 'Posisi stok sudah ada di sesi Stock Opname yang masih terbuka: '.$taken->implode(', ').'.']);
            }
            $prefix = 'SO-'.now()->format('Ymd').'-';
            $sequence = StockOpnameSession::where('session_no', 'like', $prefix.'%')->lockForUpdate()->count() + 1;
            $note = trim((string) ($data['note'] ?? ''));
            $session = StockOpnameSession::create([
                'session_no' => $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'scope_type' => $scope, 'cycle_month' => $cycleMonth, 'status' => StockOpnameStatus::Counting,
                'created_by' => $admin->id, 'note' => $note === '' ? null : $note,
            ]);
            $session->lines()->createMany(array_map(fn (string $id) => ['stock_bucket_id' => $id], $bucketIds));
            $this->audit->handle($admin, 'stock_opname.created', $session, [], $session->toArray(), $session->note);

            return $session;
        });
    }
}
