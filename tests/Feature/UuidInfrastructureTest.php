<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\UuidProbeJob;
use Tests\TestCase;

class UuidInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_including_framework_tables_uses_uuid_primary_key(): void
    {
        foreach (Schema::getTableListing(schema: DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null, schemaQualified: false) as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame(['id'], $primary['columns'], $table);
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'id');
            $this->assertContains($column['type_name'], ['char', 'varchar'], $table);
            foreach (DB::table($table)->pluck('id') as $id) {
                $this->assertTrue(Str::isUuid($id), $table);
            }
        }
    }

    public function test_database_cache_and_locks_work_with_uuid_surrogate_keys(): void
    {
        $cache = Cache::store('database');
        $cache->put('uuid-counter', 1, 60);
        $this->assertSame(1, $cache->get('uuid-counter'));
        $this->assertSame(3, $cache->increment('uuid-counter', 2));
        $this->assertFalse($cache->add('uuid-counter', 9, 60));
        $cache->put('uuid-counter', 7, 60);
        $this->assertSame(7, $cache->get('uuid-counter'));
        $lock = $cache->lock('uuid-lock', 60);
        $this->assertTrue($lock->get());
        $this->assertFalse($cache->lock('uuid-lock', 60)->get());
        foreach (['cache', 'cache_locks'] as $table) {
            foreach (DB::table($table)->pluck('id') as $id) {
                $this->assertTrue(Str::isUuid($id));
            }
        }
        $this->assertTrue($lock->release());
        $this->assertTrue($cache->forget('uuid-counter'));
    }

    public function test_sessions_keep_secure_session_keys_separate_from_uuid_primary_keys(): void
    {
        $session = app('session')->driver('database');
        $session->start();
        $session->put('uuid-session-probe', 'retained');
        $session->save();
        $key = $session->getId();
        $record = DB::table('sessions')->where('session_key', $key)->firstOrFail();
        $this->assertTrue(Str::isUuid($record->id));
        $this->assertSame(40, strlen($record->session_key));
        $this->assertStringContainsString('retained', $session->getHandler()->read($key));
        $session->migrate(true);
        $session->save();
        $this->assertDatabaseMissing('sessions', ['session_key' => $key]);
        $this->assertDatabaseHas('sessions', ['session_key' => $session->getId()]);
    }

    public function test_password_reset_provider_creates_valid_tokens_with_uuid_row_ids(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        $this->assertTrue(Str::isUuid(DB::table('password_reset_tokens')->where('email', $user->email)->value('id')));
        Password::broker()->deleteToken($user);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_database_queue_handles_push_pop_execution_release_bulk_and_failed_jobs(): void
    {
        $queue = Queue::connection('database');
        $id = $queue->push(new UuidProbeJob, '', 'uuid-test');
        $this->assertTrue(Str::isUuid($id));
        $job = $queue->pop('uuid-test');
        $this->assertSame($id, $job->getJobId());
        $job->release(0);
        $this->assertDatabaseMissing('jobs', ['id' => $id]);
        $released = $queue->pop('uuid-test');
        $this->assertTrue(Str::isUuid($released->getJobId()));
        $released->fire();
        $this->assertTrue(Cache::store('array')->get('uuid-job-executed'));
        $payload = $released->getRawBody();
        $released->delete();
        $queue->bulk([new UuidProbeJob, new UuidProbeJob], '', 'uuid-test');
        $this->assertSame(2, $queue->size('uuid-test'));
        foreach (DB::table('jobs')->pluck('id') as $queuedId) {
            $this->assertTrue(Str::isUuid($queuedId));
        }
        $failer = app('queue.failer');
        $failure = $failer->log('database', 'uuid-test', $payload, new \RuntimeException('Testing failed queue storage.'));
        $this->assertTrue(Str::isUuid(DB::table('failed_jobs')->sole()->id));
        $this->assertNotNull($failer->find($failure));
        $this->assertTrue($failer->forget($failure));
    }

    public function test_native_job_batches_keep_uuid_ids_and_dispatch_uuid_jobs(): void
    {
        $batch = Bus::batch([new UuidProbeJob])->onConnection('database')->onQueue('uuid-batch-test')->dispatch();
        $this->assertTrue(Str::isUuid($batch->id));
        $this->assertSame(1, Bus::findBatch($batch->id)->totalJobs);
        $this->assertTrue(Str::isUuid(DB::table('jobs')->where('queue', 'uuid-batch-test')->value('id')));
    }

    public function test_inventory_foundation_foreign_keys_are_uuid_compatible(): void
    {
        foreach (['inventory_periods', 'stock_transactions', 'stock_ledger_entries', 'stock_balances', 'stock_lots', 'stock_lot_allocations', 'opening_balance_batches', 'opening_balance_lines', 'stock_adjustments', 'audit_logs', 'shift_definitions', 'operational_shifts'] as $table) {
            $columns = collect(Schema::getColumns($table));
            foreach ($columns->filter(fn ($column) => str_ends_with($column['name'], '_id')) as $column) {
                $this->assertContains($column['type_name'], ['char', 'varchar'], $table.'.'.$column['name']);
            }
        }
    }

    public function test_master_seeding_is_idempotent_and_never_creates_operational_demo_data(): void
    {
        $this->seed(MasterDataSeeder::class);
        $counts = collect(['units', 'locations', 'shift_definitions'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->seed(MasterDataSeeder::class);
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('inventory_periods', 0);
        $this->assertDatabaseCount('opening_balance_batches', 0);
        $this->assertDatabaseCount('stock_transactions', 0);
    }
}
