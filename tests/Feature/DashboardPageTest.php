<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_sees_operational_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('metrics')
                ->has('recent_transactions'));
    }

    public function test_vendor_sees_vendor_safe_dashboard(): void
    {
        $vendor = Vendor::create(['vendor_code' => 'DASH', 'vendor_name' => 'Dashboard Vendor']);

        $this->actingAs(User::factory()->vendor($vendor)->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('metrics.active_requests', null)
                ->where('recent_transactions', []));
    }
}
