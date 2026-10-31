<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockIn;
use App\Actions\OpeningBalance\SaveOpeningBalanceBatch;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\StockLot;
use App\Models\StockTransaction;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlStockConcurrencyTest extends TestCase
{
    public function test_concurrent_final_quantity_out_commits_once_and_rejects_once(): void
    {
        if (DB::getDriverName() !== 'mysql' || getenv('STOCK_POSTING_MYSQL_ISOLATED') !== '1') {
            $this->markTestSkipped('Requires explicitly provisioned isolated consignment_testing MySQL DB and STOCK_POSTING_MYSQL_ISOLATED=1; no database was touched.');
        }
        $this->assertSame('consignment_testing', DB::connection()->getDatabaseName());
        // This opt-in acknowledges that the dedicated, disposable test schema is rebuilt.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed(MasterDataSeeder::class);
        $actor = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $item = Item::create(['item_code' => 'CONCURRENCY', 'part_number' => 'CONCURRENCY', 'item_name' => 'Concurrency', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $item->id, 'location_id' => $location->id]);
        InventoryPeriod::create(['cutoff_at' => CarbonImmutable::now('UTC')->subDay(), 'status' => 'ACTIVE']);
        app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'concurrency_receipt', (string) Str::uuid(), CarbonImmutable::now('UTC'), $actor->id, (string) Str::uuid(), null, [new StockPostingLineData($item->id, null, $location->id, StockDirection::In, '1.0000')]));

        $gate = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-posting-'.Str::uuid();
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'consignment_testing', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_SOCKET' => $connection['unix_socket'] ?? '', 'DB_URL' => '', 'STOCK_POSTING_MYSQL_ISOLATED' => '1'];
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/stock-posting-worker.php')], base_path(), $env);
                $worker->setInput(json_encode(['gate' => $gate, 'actor' => $actor->id, 'item' => $item->id, 'location' => $location->id], JSON_THROW_ON_ERROR));
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! str_contains($workers[0]->getOutput(), 'READY') || ! str_contains($workers[1]->getOutput(), 'READY')) {
                if (microtime(true) > $deadline || ! $workers[0]->isRunning() || ! $workers[1]->isRunning()) {
                    $this->fail('Workers failed to reach barrier: '.$workers[0]->getErrorOutput().$workers[1]->getErrorOutput());
                }
                usleep(10000);
            }
            file_put_contents($gate, 'go');
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['result'];
            }
            sort($results);
            $this->assertSame(['insufficient', 'posted'], $results);
            $this->assertSame('0.0000', StockBalance::sole()->quantity);
            $this->assertSame('0.0000', StockLot::sole()->remaining_quantity);
            $this->assertSame(2, StockTransaction::count());
            $this->assertDatabaseCount('stock_lot_allocations', 1);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_file($gate)) {
                unlink($gate);
            }
        }
    }

    public function test_concurrent_first_postings_create_one_bucket(): void
    {
        if (DB::getDriverName() !== 'mysql' || getenv('STOCK_POSTING_MYSQL_ISOLATED') !== '1') {
            $this->markTestSkipped('Requires explicitly provisioned isolated consignment_testing MySQL DB and STOCK_POSTING_MYSQL_ISOLATED=1; no database was touched.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed(MasterDataSeeder::class);
        $actor = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $item = Item::create(['item_code' => 'FIRST-BUCKET', 'part_number' => 'FIRST-BUCKET', 'item_name' => 'First bucket', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $item->id, 'location_id' => $location->id]);
        InventoryPeriod::create(['cutoff_at' => CarbonImmutable::now('UTC')->subDay(), 'status' => 'ACTIVE']);

        $gate = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-first-bucket-'.Str::uuid();
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'consignment_testing', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_SOCKET' => $connection['unix_socket'] ?? '', 'DB_URL' => '', 'STOCK_POSTING_MYSQL_ISOLATED' => '1'];
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/stock-posting-worker.php')], base_path(), $env);
                $worker->setInput(json_encode(['gate' => $gate, 'actor' => $actor->id, 'item' => $item->id, 'location' => $location->id, 'direction' => 'in'], JSON_THROW_ON_ERROR));
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! str_contains($workers[0]->getOutput(), 'READY') || ! str_contains($workers[1]->getOutput(), 'READY')) {
                if (microtime(true) > $deadline || ! $workers[0]->isRunning() || ! $workers[1]->isRunning()) {
                    $this->fail('Workers failed to reach barrier: '.$workers[0]->getErrorOutput().$workers[1]->getErrorOutput());
                }
                usleep(10000);
            }
            file_put_contents($gate, 'go');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            $this->assertSame(1, StockBucket::count());
            $this->assertSame('2.0000', StockBalance::sole()->quantity);
            $this->assertSame(2, StockTransaction::count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_file($gate)) {
                unlink($gate);
            }
        }
    }

    public function test_concurrent_opening_activations_allow_only_one_active_period(): void
    {
        if (DB::getDriverName() !== 'mysql' || getenv('STOCK_POSTING_MYSQL_ISOLATED') !== '1') {
            $this->markTestSkipped('Requires explicitly provisioned isolated consignment_testing MySQL DB and STOCK_POSTING_MYSQL_ISOLATED=1; no database was touched.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->admin()->create();
        $location = Location::firstOrFail();
        $batches = [];
        foreach ([1, 2] as $number) {
            $item = Item::create(['item_code' => 'OPEN-CONCURRENT-'.$number, 'part_number' => 'OPEN-'.$number, 'item_name' => 'Opening '.$number, 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
            StockItemLocation::create(['item_id' => $item->id, 'location_id' => $location->id]);
            $period = InventoryPeriod::create(['cutoff_at' => CarbonImmutable::now('UTC')->subDays(3 - $number)]);
            $batches[] = app(SaveOpeningBalanceBatch::class)->handle([['row_number' => 2, 'item_code' => $item->item_code, 'location_code' => $location->location_code, 'vendor_code' => null, 'quantity' => '1.0000']], $period, $admin, 'concurrent-'.$number.'.xlsx');
        }

        $gate = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opening-activation-'.Str::uuid();
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'consignment_testing', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_SOCKET' => $connection['unix_socket'] ?? '', 'DB_URL' => '', 'STOCK_POSTING_MYSQL_ISOLATED' => '1'];
        $workers = [];
        try {
            foreach ($batches as $batch) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/opening-activation-worker.php')], base_path(), $env);
                $worker->setInput(json_encode(['gate' => $gate, 'admin' => $admin->id, 'batch' => $batch->id], JSON_THROW_ON_ERROR));
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! str_contains($workers[0]->getOutput(), 'READY') || ! str_contains($workers[1]->getOutput(), 'READY')) {
                if (microtime(true) > $deadline || ! $workers[0]->isRunning() || ! $workers[1]->isRunning()) {
                    $this->fail('Workers failed to reach barrier.');
                }
                usleep(10000);
            }
            file_put_contents($gate, 'go');
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['result'];
            }
            sort($results);
            $this->assertSame(['activated', 'rejected'], $results);
            $this->assertSame(1, InventoryPeriod::where('status', 'ACTIVE')->count());
            $this->assertSame(1, StockTransaction::where('type', 'OPENING')->count());
            $this->assertNotNull(DB::table('inventory_period_controls')->where('control_key', 'GLOBAL')->value('active_period_id'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_file($gate)) {
                unlink($gate);
            }
        }
    }
}
