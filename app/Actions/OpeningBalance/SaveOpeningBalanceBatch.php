<?php

namespace App\Actions\OpeningBalance;

use App\Actions\Inventory\BuildStockBucket;
use App\Data\StockPostingLineData;
use App\Enums\InventoryPeriodStatus;
use App\Enums\OwnershipType;
use App\Enums\StockDirection;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\OpeningBalanceBatch;
use App\Models\StockItemLocation;
use App\Models\User;
use App\Models\VendorItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SaveOpeningBalanceBatch
{
    public function __construct(private BuildStockBucket $buckets) {}

    public function handle(array $rows, InventoryPeriod $period, User $admin, string $source): OpeningBalanceBatch
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat membuat saldo awal.']);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => 'Minimal satu baris saldo awal diperlukan.']);
        }

        return DB::transaction(function () use ($rows, $period, $admin, $source): OpeningBalanceBatch {
            $lockedPeriod = InventoryPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($lockedPeriod->status !== InventoryPeriodStatus::Draft || $lockedPeriod->transactions()->exists() || OpeningBalanceBatch::where('inventory_period_id', $lockedPeriod->id)->exists()) {
                throw ValidationException::withMessages(['period' => 'Periode harus DRAFT, kosong, dan belum mempunyai preview saldo awal.']);
            }

            $resolved = [];
            $errors = [];
            $buckets = [];
            foreach ($rows as $index => $row) {
                $number = (int) ($row['row_number'] ?? $index + 2);
                try {
                    $item = Item::active()->where('item_code', mb_strtoupper(trim((string) ($row['item_code'] ?? ''))))->first();
                    $location = Location::active()->where('location_code', mb_strtoupper(trim((string) ($row['location_code'] ?? ''))))->first();
                    if (! $item || ! $location) {
                        throw new InvalidArgumentException('Kode barang atau lokasi tidak ditemukan/aktif.');
                    }

                    $vendorItem = null;
                    if ($item->ownership_type === OwnershipType::Consignment) {
                        $vendorCode = mb_strtoupper(trim((string) ($row['vendor_code'] ?? '')));
                        $vendorItem = VendorItem::active()->where('item_id', $item->id)->where('location_id', $location->id)
                            ->whereHas('vendor', fn ($query) => $query->active()->where('vendor_code', $vendorCode))->first();
                        if (! $vendorItem) {
                            throw new InvalidArgumentException('Barang consignment memerlukan pasangan Vendor dan lokasi aktif.');
                        }
                    } elseif (trim((string) ($row['vendor_code'] ?? '')) !== '' || ! StockItemLocation::active()->where('item_id', $item->id)->where('location_id', $location->id)->exists()) {
                        throw new InvalidArgumentException('Barang internal harus memakai penempatan STOCK aktif tanpa Vendor.');
                    }

                    $line = new StockPostingLineData($item->id, $vendorItem?->id, $location->id, StockDirection::In, trim((string) ($row['quantity'] ?? '')));
                    $bucket = $this->buckets->handle($item, $vendorItem, $location);
                    if (isset($buckets[$bucket])) {
                        throw new InvalidArgumentException('Bucket stok duplikat dengan baris '.$buckets[$bucket].'.');
                    }
                    $buckets[$bucket] = $number;
                    $resolved[] = compact('number', 'item', 'location', 'vendorItem', 'line', 'bucket', 'row');
                } catch (InvalidArgumentException|ValidationException $exception) {
                    $errors['rows.'.$number] = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->all()
                        : [$exception->getMessage()];
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $batch = OpeningBalanceBatch::create([
                'inventory_period_id' => $lockedPeriod->id,
                'source' => mb_substr(trim($source), 0, 255) ?: 'manual',
                'created_by' => $admin->id,
            ]);
            foreach ($resolved as $entry) {
                $batch->lines()->create([
                    'row_number' => $entry['number'],
                    'item_id' => $entry['item']->id,
                    'vendor_item_id' => $entry['vendorItem']?->id,
                    'location_id' => $entry['location']->id,
                    'bucket_key' => $entry['bucket'],
                    'quantity' => $entry['line']->quantity,
                    'source_data' => $entry['row'],
                ]);
            }

            return $batch->load(['period', 'lines.item', 'lines.location', 'lines.vendorItem.vendor']);
        });
    }
}
