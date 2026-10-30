<?php

namespace App\Actions\MaterialRequests;

use App\Actions\Inventory\PostStockOut;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\OwnershipType;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\User;
use App\Models\VendorItem;
use App\Support\OperationalNumberGenerator;
use App\Support\WholeQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMaterialIssue
{
    public function __construct(private PostStockOut $postStockOut, private OperationalNumberGenerator $numbers) {}

    /** @param list<array{line_id:string,quantity:string|int|float,vendor_item_id?:string|null,location_id?:string|null}> $lines */
    public function handle(MaterialRequest $request, User $warehouse, string $receiver, CarbonImmutable $issuedAt, array $lines, string $idempotencyKey): MaterialIssue
    {
        if (! $warehouse->isWarehouse() || trim($receiver) === '' || trim($idempotencyKey) === '' || $lines === []) {
            throw ValidationException::withMessages(['issue' => 'Data serah terima belum lengkap.']);
        }
        if ($existing = MaterialIssue::where('idempotency_key', $idempotencyKey)->first()) {
            if ($existing->material_request_id !== $request->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Kunci transaksi sudah digunakan untuk MR lain.']);
            }

            return $existing->load('lines');
        }

        return DB::transaction(function () use ($request, $warehouse, $receiver, $issuedAt, $lines, $idempotencyKey): MaterialIssue {
            if ($existing = MaterialIssue::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                if ($existing->material_request_id !== $request->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci transaksi sudah digunakan untuk MR lain.']);
                }

                return $existing->load('lines');
            }
            $locked = MaterialRequest::query()->lockForUpdate()->findOrFail($request->id);
            $approval = $locked->approvals()->lockForUpdate()->latest('revision_no')->first();
            if (! $approval || $approval->status !== 'APPROVED' || in_array($locked->status, ['COMPLETED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['issue' => 'MR aktif harus memperoleh approval Admin terbaru sebelum diserahkan.']);
            }

            $seen = [];
            $issueLines = [];
            $postingLines = [];
            foreach ($lines as $input) {
                $line = MaterialRequestLine::query()->where('material_request_id', $locked->id)->whereKey($input['line_id'] ?? '')->lockForUpdate()->first();
                if (! $line || isset($seen[$line->id]) || ! WholeQuantity::isPositive($input['quantity'] ?? null) || ! in_array($line->status, ['READY', 'PARTIALLY_ISSUED'], true)) {
                    throw ValidationException::withMessages(['issue' => 'Baris harus siap, unik, dan quantity berupa satuan bulat positif.']);
                }
                $seen[$line->id] = true;
                $effective = $line->effectiveQuantity();
                $issued = (int) $line->issueLines()->whereHas('issue', fn ($query) => $query->where('status', 'POSTED'))->sum('issued_quantity');
                $quantity = (int) $input['quantity'];
                if ($issued + $quantity > $effective) {
                    throw ValidationException::withMessages(['issue' => 'Quantity serah terima melebihi sisa permintaan efektif.']);
                }

                $vendorItemId = null;
                $locationId = null;
                if ($line->item->ownership_type === OwnershipType::Consignment) {
                    $vendorItem = VendorItem::active()->whereKey($input['vendor_item_id'] ?? '')->where('item_id', $line->item_id)->first();
                    $locationId = (string) ($input['location_id'] ?? '');
                    if (! $vendorItem || $locationId === '') {
                        throw ValidationException::withMessages(['issue' => 'Barang Consignment memerlukan bucket Vendor dan lokasi.']);
                    }
                    $vendorItemId = $vendorItem->id;
                    $postingLines[] = new StockPostingLineData($line->item_id, $vendorItemId, $locationId, StockDirection::Out, (string) $quantity);
                }
                $issueLines[] = ['material_request_line_id' => $line->id, 'vendor_item_id' => $vendorItemId, 'location_id' => $locationId, 'issued_quantity' => $quantity];
            }

            $sequence = $this->numbers->next('material_issue');
            $issue = $locked->issues()->create(['issue_no' => sprintf('OUT/%s/%04d', now()->format('Ymd'), $sequence), 'status' => 'DRAFT', 'issued_at' => $issuedAt->utc(), 'issued_by' => $warehouse->id, 'receiver_name' => trim($receiver), 'idempotency_key' => $idempotencyKey]);
            foreach ($issueLines as $data) {
                $issue->lines()->create($data);
            }
            if ($postingLines !== []) {
                $transaction = $this->postStockOut->handle(new StockPostingData(StockTransactionType::MaterialIssue, MaterialIssue::class, $issue->id, $issuedAt, $warehouse->id, $idempotencyKey, "Data Out {$locked->request_no}", $postingLines));
                $issue->stock_transaction_id = $transaction->id;
            }
            $issue->status = 'POSTED';
            $issue->save();

            foreach ($locked->lines()->lockForUpdate()->get() as $requestLine) {
                $effective = $requestLine->effectiveQuantity();
                $total = (int) $requestLine->issueLines()->whereHas('issue', fn ($query) => $query->where('status', 'POSTED'))->sum('issued_quantity');
                if ($total > 0) {
                    $requestLine->update(['status' => $total >= $effective ? 'ISSUED' : 'PARTIALLY_ISSUED']);
                }
            }
            $locked->update(['status' => $locked->lines()->get()->every(fn ($line) => $line->status === 'ISSUED') ? 'COMPLETED' : 'PARTIALLY_ISSUED']);

            return $issue->fresh(['lines', 'transaction']);
        });
    }
}
