<?php

namespace Tests\Feature;

use App\Actions\OpeningBalance\ActivateOpeningBalance;
use App\Actions\OpeningBalance\SaveOpeningBalanceBatch;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->location = Location::firstOrFail();
        $this->item = Item::create([
            'item_code' => 'OPEN-'.Str::random(8),
            'part_number' => 'PN-OPEN',
            'item_name' => 'Opening item',
            'ownership_type' => 'STOCK',
            'tracking_type' => 'QUANTITY',
            'unit_id' => Unit::firstOrFail()->id,
            'minimum_stock' => '0.0000',
        ]);
        StockItemLocation::create(['item_id' => $this->item->id, 'location_id' => $this->location->id]);
    }

    public function test_preview_writes_no_ledger_and_admin_activation_posts_each_bucket_once(): void
    {
        $period = InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00']);
        $batch = app(SaveOpeningBalanceBatch::class)->handle([$this->row('12.3456')], $period, $this->admin, 'opening.xlsx');

        $this->assertDatabaseCount('stock_transactions', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertSame('PREVIEW', $batch->status->value);

        $activated = app(ActivateOpeningBalance::class)->handle($batch, $this->admin, 'opening:'.$batch->id);

        $this->assertSame('ACTIVE', $activated->status->value);
        $this->assertSame('12.3456', StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_transactions', 1);
        $this->assertDatabaseCount('stock_ledger_entries', 1);

        $this->expectException(ValidationException::class);
        app(ActivateOpeningBalance::class)->handle($batch->fresh(), $this->admin, 'opening:'.$batch->id);
    }

    public function test_preview_rejects_duplicate_bucket_inactive_master_and_invalid_quantity_with_row_errors(): void
    {
        $period = InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00']);

        foreach ([
            [$this->row('1.0000'), $this->row('2.0000')],
            [$this->row('-1.0000')],
            [$this->row('1.00001')],
        ] as $rows) {
            try {
                app(SaveOpeningBalanceBatch::class)->handle($rows, $period, $this->admin, 'manual');
                $this->fail('Expected row validation failure.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->item->update(['is_active' => false]);
        try {
            app(SaveOpeningBalanceBatch::class)->handle([$this->row('1')], $period, $this->admin, 'manual');
            $this->fail('Expected inactive item failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows.2', $exception->errors());
        }
        $this->assertDatabaseCount('opening_balance_batches', 0);
    }

    public function test_warehouse_can_view_but_cannot_upload_or_activate(): void
    {
        $warehouse = User::factory()->warehouse()->create();
        $period = InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00']);
        $batch = app(SaveOpeningBalanceBatch::class)->handle([$this->row('1')], $period, $this->admin, 'manual');

        $this->actingAs($warehouse)->get(route('opening-balances.index'))->assertOk();
        $this->actingAs($warehouse)->post(route('opening-balances.store'), [])->assertForbidden();
        $this->actingAs($warehouse)->post(route('opening-balances.activate', $batch), ['idempotency_key' => 'x'])->assertForbidden();
    }

    private function row(string $quantity): array
    {
        return [
            'row_number' => 2,
            'item_code' => $this->item->item_code,
            'location_code' => $this->location->location_code,
            'vendor_code' => null,
            'quantity' => $quantity,
        ];
    }
}
