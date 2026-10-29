<?php

namespace Tests\Feature;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Users\SaveUser;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function payload(array $overrides = []): array
    {
        return [...['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'Secure-password-123', 'role' => 'warehouse', 'vendor_id' => null, 'employee_no' => 'EMP-001', 'position' => 'Operator gudang', 'is_active' => true], ...$overrides];
    }

    public function test_admin_creates_updates_and_changes_status_without_deleting_used_accounts(): void
    {
        $actor = auth()->user();
        $this->post('/users', $this->payload())->assertRedirect('/users')->assertSessionHasNoErrors();
        $user = User::where('email', 'operator@example.test')->sole();
        $this->assertTrue(Hash::check('Secure-password-123', $user->password));
        $this->assertSame('EMP-001', $user->employee_no);
        $created = AuditLog::where('entity_id', $user->id)->sole();
        $this->assertSame('user.created', $created->action);
        $this->assertSame($actor->id, $created->actor_id);
        $this->assertSame([], $created->before);
        $this->assertSame('warehouse', $created->after['role']);
        $this->assertArrayNotHasKey('password', $created->after);
        app(WriteAuditLog::class)->handle($user, 'user.used', $user, [], []);
        $hash = $user->password;
        $vendor = Vendor::create(['vendor_code' => 'FIRST', 'vendor_name' => 'First']);
        $this->put('/users/'.$user->id, $this->payload(['role' => 'vendor', 'vendor_id' => $vendor->id, 'password' => '']))->assertSessionHasNoErrors();
        $this->assertSame($hash, $user->fresh()->password);
        $changed = AuditLog::where('entity_id', $user->id)->where('action', 'user.updated')->sole();
        $this->assertSame('warehouse', $changed->before['role']);
        $this->assertNull($changed->before['vendor_id']);
        $this->assertSame('vendor', $changed->after['role']);
        $this->assertSame($vendor->id, $changed->after['vendor_id']);
        $other = Vendor::create(['vendor_code' => 'SECOND', 'vendor_name' => 'Second']);
        $this->put('/users/'.$user->id, $this->payload(['role' => 'vendor', 'vendor_id' => $other->id, 'password' => 'Replacement-password-123']))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Replacement-password-123', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user.updated']);
        $this->patch('/users/'.$user->id.'/status', ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user.deactivated']);
        $this->patch('/users/'.$user->id.'/status', ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $user->id, 'action' => 'user.reactivated']);
        $this->delete('/users/'.$user->id)->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_role_vendor_email_and_password_validation_preserve_accounts(): void
    {
        foreach ([['role' => 'vendor'], ['role' => 'owner'], ['password' => 'short'], ['email' => 'invalid']] as $invalid) {
            $field = isset($invalid['role']) ? ($invalid['role'] === 'vendor' ? 'vendor_id' : 'role') : array_key_first($invalid);
            $this->post('/users', $this->payload($invalid))->assertSessionHasErrors($field);
        }
        $vendor = Vendor::create(['vendor_code' => 'V', 'vendor_name' => 'Vendor']);
        $this->post('/users', $this->payload(['vendor_id' => $vendor->id]))->assertSessionHasErrors('vendor_id');
        $this->post('/users', $this->payload(['role' => 'vendor', 'vendor_id' => $vendor->id]))->assertSessionHasNoErrors();
        $this->post('/users', $this->payload())->assertSessionHasErrors('email');
        $this->assertSame(2, User::count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_save_action_enforces_vendor_invariant_inside_its_boundary(): void
    {
        $this->expectException(ValidationException::class);
        app(SaveUser::class)->handle($this->payload(['role' => 'vendor']));
    }

    public function test_status_changes_preserve_role_vendor_and_identity_from_the_locked_record(): void
    {
        $first = Vendor::create(['vendor_code' => 'BEFORE', 'vendor_name' => 'Before supplier']);
        $second = Vendor::create(['vendor_code' => 'AFTER', 'vendor_name' => 'After supplier']);

        foreach ([['role' => 'vendor', 'vendor_id' => $second->id], ['role' => 'warehouse', 'vendor_id' => null]] as $index => $currentAccess) {
            $active = $index === 0;
            $user = User::factory()->vendor($first)->create(['is_active' => ! $active]);
            $staleUser = $user->fresh();
            // Simulate another Admin's committed edit after route binding, before the status action locks.
            $current = [...$currentAccess, 'name' => 'Reassigned operator', 'email' => 'reassigned-'.$index.'@example.test', 'employee_no' => 'NEW-'.$index, 'position' => 'Updated position'];
            $user->forceFill($current)->save();
            $password = $user->password;
            Route::bind('user', fn () => $staleUser);

            $this->patch('/users/'.$user->id.'/status', ['is_active' => $active, 'role' => 'admin', 'vendor_id' => $first->id])
                ->assertRedirect('/users')->assertSessionHasNoErrors();

            $fresh = $user->fresh();
            $this->assertSame($active, $fresh->is_active);
            $this->assertSame($currentAccess['role'], $fresh->role->value);
            $this->assertSame($currentAccess['vendor_id'], $fresh->vendor_id);
            $this->assertSame($password, $fresh->password);
            foreach (['name', 'email', 'employee_no', 'position'] as $field) {
                $this->assertSame($current[$field], $fresh->{$field});
            }
            $audit = AuditLog::where('entity_id', $user->id)->sole();
            $this->assertSame($active ? 'user.reactivated' : 'user.deactivated', $audit->action);
            $this->assertEquals([...$current, 'is_active' => ! $active], $audit->before);
            $this->assertEquals([...$current, 'is_active' => $active], $audit->after);
        }
    }

    public function test_deactivation_ends_existing_authenticated_access_on_next_request(): void
    {
        $user = User::factory()->warehouse()->create();
        $this->actingAs($user)->get('/master-data/barang')->assertOk();
        $user->forceFill(['is_active' => false])->save();
        $this->get('/master-data/barang')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/login')->assertOk();
    }

    public function test_active_authenticated_users_keep_access(): void
    {
        $user = User::factory()->warehouse()->create();
        $this->actingAs($user)->get('/master-data/barang')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_non_admins_cannot_access_any_user_management_route_or_mutate_masters(): void
    {
        $target = User::factory()->warehouse()->create();
        $vendor = Vendor::create(['vendor_code' => 'V', 'vendor_name' => 'Vendor']);
        foreach ([User::factory()->warehouse()->create(), User::factory()->vendor($vendor)->create()] as $actor) {
            $this->actingAs($actor);
            foreach (['/users', '/users/create', '/users/'.$target->id.'/edit'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/users', $this->payload())->assertForbidden();
            $this->put('/users/'.$target->id, $this->payload())->assertForbidden();
            $this->patch('/users/'.$target->id.'/status', ['is_active' => false])->assertForbidden();
            foreach (['/master-data/barang', '/master-data/satuan', '/master-data/vendor', '/master-data/lokasi', '/master-data/kategori'] as $url) {
                $this->post($url, [])->assertForbidden();
            }
        }
    }

    public function test_admin_list_filters_and_form_hide_credentials_and_share_abilities(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'V', 'vendor_name' => 'Linked vendor']);
        $target = User::factory()->vendor($vendor)->create(['name' => 'Needle operator']);
        User::factory()->warehouse()->create(['name' => 'Other operator']);
        $this->get('/users?q=Needle&role=vendor&status=active&vendor='.$vendor->id)->assertInertia(fn (Assert $page) => $page
            ->component('UserManagement/Index', false)->where('users.total', 1)->where('users.data.0.id', $target->id)
            ->missing('users.data.0.password')->where('auth.abilities.manage_users', true));
        $this->get('/users/create')->assertInertia(fn (Assert $page) => $page->component('UserManagement/Form', false)->where('user', null));
        $this->get('/users/'.$target->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('UserManagement/Form', false)->where('user.vendor_id', $vendor->id)->missing('user.password'));
        $this->actingAs($target)->get('/master-data/barang')->assertInertia(fn (Assert $page) => $page->where('auth.user.vendor_id', $vendor->id)->where('auth.user.vendor_name', 'Linked vendor')->where('auth.abilities.manage_users', false)->where('auth.abilities.view_reference_masters', false));
    }
}
