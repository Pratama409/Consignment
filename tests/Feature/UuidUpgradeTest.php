<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Models\Item;
use App\Models\MasterDataImport;
use App\Support\UuidTableUpgrade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class UuidUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => ! str_contains($file, '001000_convert')));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]), Artisan::output());
        DB::table('users')->insert(['id' => 11, 'name' => 'Existing admin', 'email' => 'existing@example.test', 'password' => Hash::make('Preserved-password-123'), 'role' => 'admin']);
        DB::table('units')->insert(['id' => 7, 'code' => 'PC', 'name' => 'Piece']);
        DB::table('locations')->insert(['id' => 2, 'location_code' => 'UNASSIGNED']);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_populated_upgrade_preserves_master_relationships_admin_sessions_imports_and_codes(): void
    {
        DB::table('vendors')->insert([['id' => 5, 'vendor_code' => 'V1', 'vendor_name' => 'First'], ['id' => 6, 'vendor_code' => 'V2', 'vendor_name' => 'Second']]);
        DB::table('items')->insert([
            ['id' => 100, 'item_code' => 'ITM-000150', 'part_number' => '000PN.', 'item_name' => 'Stock', 'ownership_type' => 'STOCK', 'unit_id' => 7, 'minimum_stock' => '3.1250'],
            ['id' => 101, 'item_code' => 'ITM-000151', 'part_number' => '000PN.', 'item_name' => 'Consignment', 'ownership_type' => 'CONSIGNMENT', 'unit_id' => 7, 'minimum_stock' => '9.5000'],
        ]);
        DB::table('vendor_items')->insert([['id' => 31, 'item_id' => 101, 'vendor_id' => 5, 'location_id' => 2], ['id' => 32, 'item_id' => 101, 'vendor_id' => 6, 'location_id' => 2]]);
        DB::table('stock_item_locations')->insert(['id' => 41, 'item_id' => 100, 'location_id' => 2]);
        foreach (['preview', 'completed'] as $status) {
            DB::table('master_data_imports')->insert(['user_id' => 11, 'file_name' => 'retained.xlsx', 'file_hash' => str_repeat('a', 64), 'status' => $status,
                'data' => json_encode(['rows' => []]), 'options' => json_encode(['minimum_stock' => '0', 'unit_map' => ['ODD' => 7]]),
                'result' => $status === 'completed' ? json_encode(['items' => [['id' => 100, 'item_code' => 'ITM-000150']]]) : null, 'expires_at' => now()->addDay()]);
        }
        DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => 11, 'payload' => base64_encode(serialize(['login_web_test' => 11, '_token' => 'retained-csrf'])), 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => str_repeat('j', 40), 'user_id' => 11, 'payload' => base64_encode(json_encode(['login_web_test' => 11, '_token' => 'json-csrf'])), 'last_activity' => time()]);
        DB::table('cache')->insert(['key' => 'retained-key', 'value' => serialize('retained-value'), 'expiration' => time() + 60]);
        DB::table('password_reset_tokens')->insert(['email' => 'existing@example.test', 'token' => 'retained-token']);
        $before = [];
        foreach (UuidTableUpgrade::TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        app(UuidTableUpgrade::class)->run();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
            foreach (DB::table($table)->pluck('id') as $id) {
                $this->assertTrue(Str::isUuid($id), $table);
            }
        }
        $unitId = DB::table('units')->where('code', 'PC')->value('id');
        $adminId = DB::table('users')->where('email', 'existing@example.test')->value('id');
        $this->assertTrue(Hash::check('Preserved-password-123', DB::table('users')->value('password')));
        $stock = Item::where('item_code', 'ITM-000150')->firstOrFail();
        $this->assertSame('000PN.', $stock->part_number);
        $this->assertSame('3.1250', $stock->minimum_stock);
        $this->assertSame($unitId, $stock->unit_id);
        $this->assertCount(1, $stock->stockItemLocations);
        $consignment = Item::where('item_code', 'ITM-000151')->firstOrFail();
        $this->assertCount(2, $consignment->vendorItems);
        $this->assertSame('V1', $consignment->vendorItems->first()->vendor->vendor_code);
        foreach (MasterDataImport::all() as $import) {
            $this->assertSame($adminId, $import->user_id);
            $this->assertSame($unitId, $import->options['unit_map']['ODD']);
            if ($import->status === 'completed') {
                $this->assertSame($stock->id, $import->result['items'][0]['id']);
            }
        }
        $payload = unserialize(base64_decode(DB::table('sessions')->where('session_key', str_repeat('s', 40))->value('payload')));
        $this->assertSame($adminId, $payload['login_web_test']);
        $this->assertSame('retained-csrf', $payload['_token']);
        $json = json_decode(base64_decode(DB::table('sessions')->where('session_key', str_repeat('j', 40))->value('payload')), true);
        $this->assertSame($adminId, $json['login_web_test']);
        $this->assertSame('json-csrf', $json['_token']);
        $this->assertSame('retained-value', unserialize(DB::table('cache')->value('value')));
        $new = app(SaveItem::class)->handle(['part_number' => 'NEW', 'item_name' => 'Next item', 'ownership_type' => 'STOCK', 'unit_id' => $unitId, 'minimum_stock' => '0']);
        $this->assertSame('ITM-000152', $new->item_code);
    }

    public function test_copy_failure_leaves_original_tables_and_ids_intact(): void
    {
        DB::table('sessions')->insert(['id' => str_repeat('x', 40), 'user_id' => 9999, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        try {
            app(UuidTableUpgrade::class)->run();
            $this->fail('An unmapped legacy reference must stop conversion.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Unmapped foreign key', $error->getMessage());
        }
        $this->assertSame(11, (int) DB::table('users')->value('id'));
        $this->assertSame(7, (int) DB::table('units')->value('id'));
        $this->assertFalse(Schema::hasTable('uuid_stage_users'));
        $this->assertFalse(Schema::hasTable('number_sequences'));
    }

    public function test_encrypted_json_session_is_remapped_without_losing_encryption(): void
    {
        config(['session.encrypt' => true]);
        $encrypted = Crypt::encrypt(json_encode(['login_web_test' => 11, '_token' => 'encrypted-csrf']));
        DB::table('sessions')->insert(['id' => str_repeat('e', 40), 'user_id' => 11, 'payload' => base64_encode($encrypted), 'last_activity' => time()]);
        app(UuidTableUpgrade::class)->run();
        $row = DB::table('sessions')->sole();
        $payload = json_decode(Crypt::decrypt(base64_decode($row->payload)), true);
        $this->assertSame(DB::table('users')->value('id'), $payload['login_web_test']);
        $this->assertSame('encrypted-csrf', $payload['_token']);
        $this->assertTrue(Str::isUuid($row->id));
    }

    public function test_guard_installation_failure_restores_original_tables_after_swap(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('CREATE TRIGGER uuid_items_ownership_immutable BEFORE UPDATE ON users FOR EACH ROW SET NEW.name = NEW.name');
        } else {
            DB::unprepared('CREATE TRIGGER uuid_items_ownership_immutable BEFORE UPDATE ON users BEGIN SELECT 1; END');
        }
        try {
            app(UuidTableUpgrade::class)->run();
            $this->fail('A conflicting trigger must prevent finalizing the upgrade.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('uuid_items_ownership_immutable', $error->getMessage());
        }
        $this->assertSame(11, (int) DB::table('users')->value('id'));
        $this->assertSame(7, (int) DB::table('units')->value('id'));
        $this->assertFalse(Schema::hasTable('uuid_stage_users'));
        $this->assertFalse(Schema::hasTable('uuid_legacy_users'));
        $this->assertFalse(Schema::hasTable('number_sequences'));
    }
}
