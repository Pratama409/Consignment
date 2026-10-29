<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UuidTableUpgrade
{
    public const TABLES = ['users', 'units', 'vendors', 'locations', 'items', 'vendor_items', 'stock_item_locations', 'master_data_imports', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];

    private array $maps = [];

    private array $snapshot = [];

    public function run(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('UUID upgrade supports MySQL and SQLite only.');
        }
        $extra = array_diff(Schema::getTableListing(schema: DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null, schemaQualified: false), self::TABLES);
        $extra = array_filter($extra, fn ($name) => ! str_starts_with($name, 'sqlite_'));
        if ($extra || DB::table('jobs')->exists()) {
            throw new RuntimeException('UUID upgrade requires the known application schema and an empty jobs queue. Unexpected tables: '.implode(', ', $extra).'. No source data was changed.');
        }
        foreach (self::TABLES as $table) {
            $this->snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            foreach ($this->snapshot[$table] as $row) {
                $key = $row['id'] ?? $row['key'] ?? $row['email'];
                $this->maps[$table][(string) $key] = isset($row['id']) && Str::isUuid((string) $row['id']) ? $row['id'] : (string) Str::uuid7();
            }
        }
        if (! app()->environment('testing')) {
            $schema = [];
            $triggers = [];
            if (DB::getDriverName() === 'mysql') {
                foreach (self::TABLES as $table) {
                    $schema[$table] = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
                }
                foreach (DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [DB::getDatabaseName()]) as $trigger) {
                    $name = str_replace('`', '``', $trigger->TRIGGER_NAME);
                    $triggers[] = (array) DB::selectOne("SHOW CREATE TRIGGER `{$name}`");
                }
            }
            $path = 'database-backups/pre-uuid-'.now()->format('Ymd-His').'-'.Str::random(8).'.enc';
            if (! Storage::disk('local')->put($path, Crypt::encryptString(serialize(['schema' => $schema, 'triggers' => $triggers, 'rows' => $this->snapshot, 'id_map' => $this->maps])))) {
                throw new RuntimeException('Cannot create the private encrypted backup. UUID upgrade cancelled.');
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::transaction(fn () => $this->convert());
        } else {
            $this->convert();
        }
    }

    private function convert(): void
    {
        $swapped = false;
        $complete = false;
        try {
            foreach (self::TABLES as $table) {
                $this->createTable($table);
                foreach ($this->snapshot[$table] as $source) {
                    $row = $this->convertRow($table, $source);
                    DB::table('uuid_stage_'.$table)->insert($row);
                    $stored = (array) DB::table('uuid_stage_'.$table)->where('id', $row['id'])->first();
                    foreach ($row as $column => $value) {
                        $actual = $stored[$column] ?? null;
                        $json = $table === 'master_data_imports' && in_array($column, ['data', 'options', 'decisions', 'corrections', 'result'], true);
                        if (($json && json_decode($actual ?? 'null', true) != json_decode($value ?? 'null', true))
                            || (! $json && (($actual === null) !== ($value === null) || (string) $actual !== (string) $value))) {
                            throw new RuntimeException("UUID copy verification failed: {$table}.{$column}");
                        }
                    }
                }
                if (DB::table('uuid_stage_'.$table)->count() !== count($this->snapshot[$table])) {
                    throw new RuntimeException('UUID row count verification failed: '.$table);
                }
            }
            $this->createSequence();
            $this->swap(false);
            $swapped = true;
            $this->installGuards();
            // Source tables remain available until the new tables and guards are verified.
            $complete = true;
            foreach (array_reverse(self::TABLES) as $table) {
                Schema::drop('uuid_legacy_'.$table);
            }
        } catch (Throwable $error) {
            if (! $complete && $swapped) {
                $this->swap(true);
                Schema::dropIfExists('number_sequences');
            }
            if (! $complete) {
                foreach (array_reverse(self::TABLES) as $table) {
                    Schema::dropIfExists('uuid_stage_'.$table);
                }
                Schema::dropIfExists('uuid_stage_number_sequences');
            }
            throw $error;
        }
    }

    private function id(Blueprint $table): void
    {
        $default = DB::getDriverName() === 'mysql' ? '(UUID())' : "(lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)),2) || '-' || substr('89ab',(random() % 4 + 4) % 4 + 1,1) || substr(hex(randomblob(2)),2) || '-' || hex(randomblob(6))))";
        $table->uuid('id')->primary()->default(DB::raw($default));
    }

    private function fk(Blueprint $table, string $column, string $parent, bool $nullable = false): void
    {
        $table->foreignUuid($column)->nullable($nullable)->constrained('uuid_stage_'.$parent)->restrictOnDelete();
    }

    private function createTable(string $name): void
    {
        Schema::create('uuid_stage_'.$name, function (Blueprint $table) use ($name) {
            $this->id($table);
            switch ($name) {
                case 'users':
                    $table->string('name');
                    $table->string('email')->unique();
                    $table->timestamp('email_verified_at')->nullable();
                    $table->string('password');
                    $table->rememberToken();
                    $table->string('role', 20)->default('user');
                    $table->timestamps();
                    break;
                case 'units':
                    $table->string('code', 30)->unique();
                    $table->string('name', 100);
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    break;
                case 'vendors':
                    $table->string('vendor_code', 50)->unique();
                    $table->string('vendor_name', 150);
                    $table->string('pic_name', 150)->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    break;
                case 'locations':
                    $table->string('location_code', 50)->unique();
                    $table->string('location_name', 150)->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    break;
                case 'items':
                    $table->string('item_code', 50)->unique();
                    $table->string('part_number', 100)->index();
                    $table->string('item_name', 200);
                    $table->enum('ownership_type', ['STOCK', 'CONSIGNMENT']);
                    $table->enum('tracking_type', ['QUANTITY', 'SERIALIZED'])->default('QUANTITY');
                    $this->fk($table, 'unit_id', 'units');
                    $table->decimal('minimum_stock', 18, 4)->default(0);
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    $table->index(['ownership_type', 'is_active']);
                    break;
                case 'vendor_items':
                    $this->fk($table, 'item_id', 'items');
                    $this->fk($table, 'vendor_id', 'vendors');
                    $this->fk($table, 'location_id', 'locations');
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    $table->unique(['item_id', 'vendor_id']);
                    $table->index(['vendor_id', 'is_active']);
                    break;
                case 'stock_item_locations':
                    $this->fk($table, 'item_id', 'items');
                    $this->fk($table, 'location_id', 'locations');
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                    $table->unique(['item_id', 'location_id']);
                    break;
                case 'master_data_imports':
                    $this->fk($table, 'user_id', 'users');
                    $table->string('file_name');
                    $table->char('file_hash', 64)->index();
                    $table->string('status', 20)->default('preview');
                    $table->json('data');
                    $table->json('options');
                    $table->json('decisions')->nullable();
                    $table->json('corrections')->nullable();
                    $table->json('result')->nullable();
                    $table->timestamp('expires_at');
                    $table->timestamp('confirmed_at')->nullable();
                    $table->timestamps();
                    break;
                case 'password_reset_tokens':
                    $table->string('email')->unique();
                    $table->string('token');
                    $table->timestamp('created_at')->nullable();
                    break;
                case 'sessions':
                    $table->string('session_key')->unique();
                    $this->fk($table, 'user_id', 'users', true);
                    $table->string('ip_address', 45)->nullable();
                    $table->text('user_agent')->nullable();
                    $table->longText('payload');
                    $table->integer('last_activity')->index();
                    break;
                case 'cache':
                    $table->string('key')->unique();
                    $table->mediumText('value');
                    $table->bigInteger('expiration')->index();
                    break;
                case 'cache_locks':
                    $table->string('key')->unique();
                    $table->string('owner');
                    $table->bigInteger('expiration')->index();
                    break;
                case 'jobs':
                    $table->string('queue')->index();
                    $table->longText('payload');
                    $table->unsignedTinyInteger('attempts');
                    $table->unsignedInteger('reserved_at')->nullable();
                    $table->unsignedInteger('available_at');
                    $table->unsignedInteger('created_at');
                    break;
                case 'job_batches':
                    $table->string('name');
                    $table->integer('total_jobs');
                    $table->integer('pending_jobs');
                    $table->integer('failed_jobs');
                    $table->longText('failed_job_ids');
                    $table->mediumText('options')->nullable();
                    $table->integer('cancelled_at')->nullable();
                    $table->integer('created_at');
                    $table->integer('finished_at')->nullable();
                    break;
                case 'failed_jobs':
                    $table->uuid('uuid')->unique();
                    $table->text('connection');
                    $table->text('queue');
                    $table->longText('payload');
                    $table->longText('exception');
                    $table->timestamp('failed_at')->useCurrent();
                    break;
                case 'migrations':
                    $table->string('migration');
                    $table->integer('batch');
                    break;
            }
        });
    }

    private function convertRow(string $table, array $row): array
    {
        $oldId = $row['id'] ?? $row['key'] ?? $row['email'];
        $row['id'] = $this->maps[$table][(string) $oldId];
        $foreign = match ($table) {
            'items' => ['unit_id' => 'units'],
            'vendor_items' => ['item_id' => 'items', 'vendor_id' => 'vendors', 'location_id' => 'locations'],
            'stock_item_locations' => ['item_id' => 'items', 'location_id' => 'locations'],
            'master_data_imports', 'sessions' => ['user_id' => 'users'], default => [],
        };
        foreach ($foreign as $column => $parent) {
            $row[$column] = $row[$column] === null ? null : ($this->maps[$parent][(string) $row[$column]] ?? throw new RuntimeException('Unmapped foreign key: '.$table.'.'.$column));
        }
        if ($table === 'sessions') {
            $row['session_key'] = (string) $oldId;
            $serialized = base64_decode($row['payload'], true);
            if ($serialized === false) {
                throw new RuntimeException('Invalid session payload encoding.');
            }
            if (config('session.encrypt')) {
                $serialized = Crypt::decrypt($serialized);
            }
            $json = str_starts_with(ltrim($serialized), '{') || str_starts_with(ltrim($serialized), '[');
            $payload = $json ? json_decode($serialized, true, flags: JSON_THROW_ON_ERROR) : unserialize($serialized, ['allowed_classes' => false]);
            if (is_array($payload)) {
                foreach ($payload as $key => $value) {
                    if (str_starts_with((string) $key, 'login_') && is_scalar($value) && isset($this->maps['users'][(string) $value])) {
                        $payload[$key] = $this->maps['users'][(string) $value];
                    }
                }
                $serialized = $json ? json_encode($payload, JSON_THROW_ON_ERROR) : serialize($payload);
                if (config('session.encrypt')) {
                    $serialized = Crypt::encrypt($serialized);
                }
                $row['payload'] = base64_encode($serialized);
            }
        }
        if ($table === 'master_data_imports') {
            $options = json_decode($row['options'], true, flags: JSON_THROW_ON_ERROR);
            foreach ($options['unit_map'] ?? [] as $code => $unitId) {
                if ($unitId !== null && $unitId !== '') {
                    $options['unit_map'][$code] = $this->maps['units'][(string) $unitId] ?? throw new RuntimeException('Unknown unit mapping in preview.');
                }
            }
            $row['options'] = json_encode($options, JSON_THROW_ON_ERROR);
            if ($row['result'] !== null) {
                $result = json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR);
                $result['items'] ??= [];
                foreach ($result['items'] as &$item) {
                    $item['id'] = $this->maps['items'][(string) $item['id']] ?? throw new RuntimeException('Unknown result item in preview.');
                }
                unset($item);
                $row['result'] = json_encode($result, JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function createSequence(): void
    {
        Schema::create('uuid_stage_number_sequences', function (Blueprint $table) {
            $this->id($table);
            $table->string('name')->unique();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });
        $last = 0;
        foreach ($this->snapshot['items'] as $item) {
            if (preg_match('/^ITM-(\d+)$/', $item['item_code'], $match)) {
                $last = max($last, (int) $match[1]);
            }
        }
        DB::table('uuid_stage_number_sequences')->insert(['id' => (string) Str::uuid7(), 'name' => 'item_code', 'last_value' => $last, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function swap(bool $reverse): void
    {
        $pairs = [];
        foreach (self::TABLES as $table) {
            $pairs[] = [$table, ($reverse ? 'uuid_stage_' : 'uuid_legacy_').$table];
            $pairs[] = [($reverse ? 'uuid_legacy_' : 'uuid_stage_').$table, $table];
        }
        if (! $reverse) {
            $pairs[] = ['uuid_stage_number_sequences', 'number_sequences'];
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('RENAME TABLE '.implode(', ', array_map(fn ($pair) => '`'.$pair[0].'` TO `'.$pair[1].'`', $pairs)));
        } else {
            foreach ($pairs as [$from, $to]) {
                Schema::rename($from, $to);
            }
        }
    }

    private function installGuards(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE items ADD CONSTRAINT uuid_items_minimum_stock_nonnegative CHECK (minimum_stock >= 0)');
            DB::unprepared("CREATE TRIGGER uuid_items_ownership_immutable BEFORE UPDATE ON items FOR EACH ROW BEGIN IF NEW.ownership_type <> OLD.ownership_type THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item ownership cannot be changed'; END IF; END");
            foreach (['INSERT', 'UPDATE'] as $event) {
                foreach (['vendor_items' => 'CONSIGNMENT', 'stock_item_locations' => 'STOCK'] as $table => $ownership) {
                    DB::unprepared("CREATE TRIGGER uuid_{$table}_owner_".strtolower($event)." BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = '{$ownership}') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid item ownership for relation'; END IF; END");
                }
            }
        } else {
            DB::unprepared("CREATE TRIGGER uuid_items_ownership_immutable BEFORE UPDATE OF ownership_type ON items WHEN NEW.ownership_type <> OLD.ownership_type BEGIN SELECT RAISE(ABORT, 'Item ownership cannot be changed'); END");
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER uuid_items_minimum_stock_'.strtolower($event)." BEFORE {$event} ON items WHEN NEW.minimum_stock < 0 BEGIN SELECT RAISE(ABORT, 'Minimum stock cannot be negative'); END");
                foreach (['vendor_items' => 'CONSIGNMENT', 'stock_item_locations' => 'STOCK'] as $table => $ownership) {
                    DB::unprepared('CREATE TRIGGER uuid_'.$table.'_owner_'.strtolower($event)." BEFORE {$event} ON {$table} WHEN NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = '{$ownership}') BEGIN SELECT RAISE(ABORT, 'Invalid item ownership for relation'); END");
                }
            }
        }
    }
}
