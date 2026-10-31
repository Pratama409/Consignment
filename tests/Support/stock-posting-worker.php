<?php

use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\PostStockOut;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'consignment_testing' || getenv('STOCK_POSTING_MYSQL_ISOLATED') !== '1') {
    throw new RuntimeException('Worker is restricted to explicitly isolated MySQL test database.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['outer'] ?? false) === true) {
    // Mirror the real callers: an outer transaction whose first plain read pins a REPEATABLE READ snapshot.
    DB::beginTransaction();
    DB::table('items')->count();
}
echo "READY\n";
flush();
$deadline = microtime(true) + 20;
while (! is_file($input['gate'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Posting barrier timed out.');
    }
    usleep(10000);
}
try {
    if (($input['direction'] ?? 'out') === 'in') {
        app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'concurrency_receipt', (string) Str::uuid(), CarbonImmutable::now('UTC'), $input['actor'], (string) Str::uuid(), null, [new StockPostingLineData($input['item'], null, $input['location'], StockDirection::In, '1.0000')]));
    } else {
        app(PostStockOut::class)->handle(new StockPostingData(StockTransactionType::MaterialIssue, 'concurrency_issue', (string) Str::uuid(), CarbonImmutable::now('UTC'), $input['actor'], (string) Str::uuid(), null, [new StockPostingLineData($input['item'], null, $input['location'], StockDirection::Out, '1.0000')]));
    }
    if (DB::transactionLevel() > 0) {
        DB::commit();
    }
    echo json_encode(['result' => 'posted'], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $exception) {
    if (array_key_exists('stock', $exception->errors())) {
        echo json_encode(['result' => 'frozen'], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (! array_key_exists('quantity', $exception->errors())) {
        throw $exception;
    }
    echo json_encode(['result' => 'insufficient'], JSON_THROW_ON_ERROR)."\n";
}
