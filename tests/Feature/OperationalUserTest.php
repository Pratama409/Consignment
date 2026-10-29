<?php

namespace Tests\Feature;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_users_become_warehouse_and_new_rows_default_to_active_warehouse(): void
    {
        $path = database_path('migrations/2026_10_29_002000_expand_users_for_operational_roles.php');
        $this->assertFileExists($path);
        $original = DB::getDefaultConnection();
        config(['database.connections.operational_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('operational_migration');

        try {
            Schema::create('vendors', fn (Blueprint $table) => $table->uuid('id')->primary());
            Schema::create('users', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('role', 20)->default('user')->index();
            });
            $legacyId = (string) Str::uuid();
            $adminId = (string) Str::uuid();
            DB::table('users')->insert([['id' => $legacyId, 'role' => 'user'], ['id' => $adminId, 'role' => 'admin']]);
            (require $path)->up();

            $this->assertSame('warehouse', DB::table('users')->where('id', $legacyId)->value('role'));
            $this->assertSame('admin', DB::table('users')->where('id', $adminId)->value('role'));
            $newId = (string) Str::uuid();
            DB::table('users')->insert(['id' => $newId]);
            $new = DB::table('users')->where('id', $newId)->first();
            $this->assertSame('warehouse', $new->role);
            $this->assertSame(1, (int) $new->is_active);
            $this->assertNull($new->vendor_id);
            $this->assertNull($new->employee_no);
            $this->assertNull($new->position);
            $this->assertNull($new->last_login_at);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('operational_migration');
        }
    }

    public function test_factory_roles_drive_the_operational_helpers_and_vendor_relationship(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'VN01', 'vendor_name' => 'Supplier']);
        $admin = User::factory()->admin()->create();
        $warehouse = User::factory()->warehouse()->create();
        $vendorUser = User::factory()->vendor($vendor)->create();

        foreach ([[$admin, UserRole::Admin], [$warehouse, UserRole::Warehouse], [$vendorUser, UserRole::Vendor]] as [$user, $role]) {
            $user->refresh();
            $this->assertSame($role, $user->role);
            $this->assertSame($role === UserRole::Admin, $user->isAdmin());
            $this->assertSame($role === UserRole::Warehouse, $user->isWarehouse());
            $this->assertSame($role === UserRole::Vendor, $user->isVendor());
            $this->assertTrue($user->is_active);
            $this->assertTrue(Str::isUuid($user->id));
        }
        $this->assertNull($admin->vendor_id);
        $this->assertNull($warehouse->vendor_id);
        $this->assertTrue($vendorUser->vendor->is($vendor));
    }

    public function test_vendor_role_requires_a_vendor(): void
    {
        $user = User::factory()->make();
        $user->role = 'vendor';
        $this->expectException(ValidationException::class);
        $user->save();
    }

    public function test_admin_and_warehouse_cannot_be_assigned_a_vendor(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'VN01', 'vendor_name' => 'Supplier']);
        foreach (['admin', 'warehouse'] as $role) {
            $user = User::factory()->make();
            $user->forceFill(['role' => $role, 'vendor_id' => $vendor->id]);
            try {
                $user->save();
                $this->fail("{$role} must reject a Vendor assignment.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('vendor_id', $exception->errors());
            }
        }
    }

    public function test_mass_assignment_cannot_reassign_vendor_or_change_role_and_status(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'VN01', 'vendor_name' => 'Supplier']);
        $other = Vendor::create(['vendor_code' => 'VN02', 'vendor_name' => 'Other supplier']);
        $user = User::factory()->vendor($vendor)->create();
        $user->fill(['name' => 'Updated', 'role' => 'admin', 'vendor_id' => $other->id, 'is_active' => false])->save();

        $user->refresh();
        $this->assertSame('Updated', $user->name);
        $this->assertSame(UserRole::Vendor, $user->role);
        $this->assertSame($vendor->id, $user->vendor_id);
        $this->assertTrue($user->is_active);
    }

    public function test_audit_writer_persists_actor_entity_changes_and_optional_reason_with_uuid_identity(): void
    {
        $this->assertTrue(class_exists(WriteAuditLog::class), 'The audit writer must exist.');
        $actor = User::factory()->create();
        $vendor = Vendor::create(['vendor_code' => 'VN01', 'vendor_name' => 'Supplier']);
        $audit = app(WriteAuditLog::class)->handle($actor, 'vendor.updated', $vendor, ['vendor_name' => 'Old'], ['vendor_name' => 'Supplier'], 'Correct supplier name');
        $audit->refresh();

        $this->assertTrue(Str::isUuid($audit->id));
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('vendor.updated', $audit->action);
        $this->assertSame(Vendor::class, $audit->entity_type);
        $this->assertSame($vendor->id, $audit->entity_id);
        $this->assertSame(['vendor_name' => 'Old'], $audit->before);
        $this->assertSame(['vendor_name' => 'Supplier'], $audit->after);
        $this->assertSame('Correct supplier name', $audit->reason);
        $withoutReason = app(WriteAuditLog::class)->handle($actor, 'user.created', $actor, [], ['name' => $actor->name]);
        $this->assertNull($withoutReason->fresh()->reason);
    }
}
