<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_page_is_available_without_exposing_registration(): void
    {
        $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('auth.user', null));
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['role' => 'admin'])->assertNotFound();
    }

    public function test_valid_credentials_start_a_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Testing-password-123')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Testing-password-123'])->assertRedirect(route('items.index'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invalid_credentials_do_not_start_a_session(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('email'));
    }

    public function test_role_cannot_be_promoted_through_mass_assignment(): void
    {
        $user = User::create(['name' => 'Normal user', 'email' => 'normal@example.test', 'password' => 'Testing-password-123', 'role' => 'admin']);
        $this->assertSame(UserRole::User, $user->fresh()->role);
    }

    public function test_admin_command_creates_an_admin_without_seeding_a_public_default_password(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Nama admin', 'Warehouse Admin')
            ->expectsQuestion('Email admin', 'admin@example.test')
            ->expectsQuestion('Kata sandi (minimal 12 karakter)', 'A-long-testing-password')
            ->expectsQuestion('Konfirmasi kata sandi', 'A-long-testing-password')
            ->assertSuccessful();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('A-long-testing-password', $user->password));
    }
}
