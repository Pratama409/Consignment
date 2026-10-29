<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostStockIn;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
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
}
