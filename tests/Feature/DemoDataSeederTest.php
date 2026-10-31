<?php

namespace Tests\Feature;

use App\Models\MaterialIssue;
use App\Models\MaterialIssueLine;
use App\Models\ProcurementRequirement;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Models\VendorRefillReceipt;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_consistent_demo_data(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(0, StockBalance::count());
        foreach (StockBalance::all() as $balance) {
            $ledger = StockLedgerEntry::where('stock_bucket_id', $balance->stock_bucket_id)->sum('quantity');
            $this->assertSame(number_format((float) $balance->quantity, 4, '.', ''), number_format((float) $ledger, 4, '.', ''));
        }
        $this->assertSame(1, VendorRefillReceipt::count());
        $this->assertSame(1, ProcurementRequirement::count());
        $this->assertSame('WO-DEMO-001', MaterialIssue::sole()->work_order_no);
        $this->assertSame(1, MaterialIssueLine::where('is_backcharge', true)->count());
        foreach (['admin', 'warehouse', 'vendor'] as $role) {
            $this->assertTrue(User::where('role', $role)->where('is_active', true)->exists(), "Missing demo {$role} user.");
        }
        $this->assertNotNull(User::where('role', 'vendor')->sole()->vendor_id);
    }

    public function test_seeder_is_safe_to_rerun(): void
    {
        $this->seed(DemoDataSeeder::class);
        $balances = StockBalance::count();
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertSame($balances, StockBalance::count());
        $this->assertSame(1, MaterialIssue::count());
    }

    public function test_seeder_refuses_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->app->make(DemoDataSeeder::class)->run();
    }
}
