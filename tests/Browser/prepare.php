<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';

$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || basename($database) !== 'browser.sqlite'
    || ! str_starts_with(basename(dirname($database)), 'consignment-ui-')
    || realpath(dirname(dirname($database))) !== realpath(sys_get_temp_dir())
    || file_exists($database)) {
    throw new RuntimeException('Browser fixtures require a new temporary SQLite database.');
}

new PDO('sqlite:'.$database);
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Refusing to prepare fixtures on the application database.');
}
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true, '--no-interaction' => true]);

foreach (['admin' => UserRole::Admin, 'viewer' => UserRole::User] as $name => $role) {
    $user = new User(['name' => $name === 'admin' ? 'Admin Pengujian' : 'Pengguna Pengujian', 'email' => $name.'@browser.test', 'password' => 'Browser-testing-password-123']);
    $user->forceFill(['role' => $role])->save();
}

echo 'Browser fixtures prepared on a temporary SQLite database.', PHP_EOL;
