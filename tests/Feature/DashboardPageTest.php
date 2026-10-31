<?php

namespace Tests\Feature;

use App\Models\User;
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
}
