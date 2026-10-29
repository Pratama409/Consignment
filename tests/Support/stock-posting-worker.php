<?php

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
    app(PostStockOut::class)->handle(new StockPostingData(StockTransactionType::MaterialIssue, 'concurrency_issue', (string) Str::uuid(), CarbonImmutable::now('UTC'), $input['actor'], (string) Str::uuid(), null, [new StockPostingLineData($input['item'], null, $input['location'], StockDirection::Out, '1.0000')]));
    echo json_encode(['result' => 'posted'], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $exception) {
    if (! array_key_exists('quantity', $exception->errors())) {
        throw $exception;
    }
    echo json_encode(['result' => 'insufficient'], JSON_THROW_ON_ERROR)."\n";
}
