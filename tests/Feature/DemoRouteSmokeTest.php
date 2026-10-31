<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\OpeningBalanceBatch;
use App\Models\OperationalShift;
use App\Models\StockBalance;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Models\VendorRefill;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every readable page and export renders for each role against the seeded demo data, never with a server error. */
class DemoRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return ['admin' => ['admin'], 'warehouse' => ['warehouse'], 'vendor' => ['vendor']];
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        return [
            'home' => route('home'),
            'items.index' => route('items.index'),
            'items.export' => route('items.export'),
            'items.show' => route('items.show', Item::where('item_code', 'DEMO-GLOVE')->firstOrFail()),
            'items.edit' => route('items.edit', Item::where('item_code', 'DEMO-GLOVE')->firstOrFail()),
            'departments.index' => route('departments.index'),
            'categories.index' => route('categories.index'),
            'locations.index' => route('locations.index'),
            'units.index' => route('units.index'),
            'vendors.index' => route('vendors.index'),
            'refills.index' => route('refills.index'),
            'refills.show' => route('refills.show', VendorRefill::firstOrFail()),
            'incoming-refills.index' => route('incoming-refills.index'),
            'manual-data-in.index' => route('manual-data-in.index'),
            'material-requests.index' => route('material-requests.index'),
            'material-requests.show' => route('material-requests.show', MaterialRequest::firstOrFail()),
            'procurement.index' => route('procurement.index'),
            'procurement.export' => route('procurement.export'),
            'monitoring.transactions.index' => route('monitoring.transactions.index'),
            'monitoring.transactions.export' => route('monitoring.transactions.export'),
            'monitoring.system-stock.index' => route('monitoring.system-stock.index'),
            'monitoring.system-stock.show' => route('monitoring.system-stock.show', StockBalance::firstOrFail()),
            'monitoring.shifts.index' => route('monitoring.shifts.index'),
            'monitoring.shifts.show' => route('monitoring.shifts.show', OperationalShift::firstOrFail()),
            'monitoring.reconciliations.index' => route('monitoring.reconciliations.index'),
            'opening-balances.index' => route('opening-balances.index'),
            'opening-balances.show' => route('opening-balances.show', OpeningBalanceBatch::firstOrFail()),
            'stock-corrections.index' => route('stock-corrections.index'),
            'stock-corrections.create' => route('stock-corrections.create'),
            'stock-opname.index' => route('stock-opname.index'),
            'stock-opname.create' => route('stock-opname.create'),
            'stock-opname.show' => route('stock-opname.show', StockOpnameSession::firstOrFail()),
            'stock-opname.report' => route('stock-opname.report', StockOpnameSession::firstOrFail()),
            'shifts.index' => route('shifts.index'),
            'users.index' => route('users.index'),
        ];
    }

    #[DataProvider('roles')]
    public function test_pages_never_fail_with_a_server_error(string $role): void
    {
        $this->seed(DemoDataSeeder::class);
        $user = User::where('role', $role)->firstOrFail();
        $failures = [];
        foreach ($this->urls() as $name => $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            if ($status >= 500 || ($role === 'admin' && $status !== 200)) {
                $failures[] = "{$name} → {$status}";
            }
        }

        $this->assertSame([], $failures);
    }
}
