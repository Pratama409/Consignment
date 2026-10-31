<?php

use App\Actions\Inventory\SubmitOpnameCount;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
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
        throw new RuntimeException('Submit barrier timed out.');
    }
    usleep(10000);
}
try {
    app(SubmitOpnameCount::class)->handle(StockOpnameSession::findOrFail($input['session']), User::findOrFail($input['warehouse']));
    echo json_encode(['result' => 'submitted'], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException) {
    echo json_encode(['result' => 'rejected'], JSON_THROW_ON_ERROR)."\n";
}
