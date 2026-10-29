<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_cannot_read_or_change_units(): void
    {
        $unit = Unit::create(['code' => 'PC', 'name' => 'Piece']);
        $this->get(route('units.index'))->assertRedirect(route('login'));
        $this->post(route('units.store'), $this->payload())->assertRedirect(route('login'));
        $this->put(route('units.update', $unit), $this->payload())->assertRedirect(route('login'));
        $this->patch(route('units.status', $unit), ['is_active' => false])->assertRedirect(route('login'));
    }

    public function test_normal_users_have_read_only_access_and_cannot_bypass_it_with_direct_requests(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['code' => 'PC', 'name' => 'Piece']);
        $this->actingAs($user)->get(route('units.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('MasterData/Units/Index')->where('can.create', false)->where('units.data.0.can_update', false)
            ->where('auth.user.role', 'warehouse')->missing('auth.user.password'));
        $this->post(route('units.store'), $this->payload())->assertForbidden();
        $this->put(route('units.update', $unit), $this->payload())->assertForbidden();
        $this->patch(route('units.status', $unit), ['is_active' => false])->assertForbidden();
        $this->assertDatabaseCount('units', 1);
        $this->assertTrue($unit->fresh()->is_active);
    }

    public function test_admin_receives_real_global_counts_and_paginated_rows(): void
    {
        $this->admin();
        for ($index = 1; $index <= 13; $index++) {
            Unit::create(['code' => sprintf('U%02d', $index), 'name' => 'Unit '.$index, 'is_active' => $index <= 10]);
        }

        $this->get(route('units.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('MasterData/Units/Index')->has('units.data', 10)->where('units.total', 13)
            ->where('summary.total', 13)->where('summary.active', 10)->where('summary.inactive', 3)
            ->where('can.create', true)->where('auth.user.role_label', 'Admin'));
    }

    public function test_search_and_status_filter_are_combined_without_changing_global_summary(): void
    {
        $this->admin();
        Unit::create(['code' => 'PC', 'name' => 'Piece']);
        Unit::create(['code' => 'PACK', 'name' => 'Pack', 'is_active' => false]);
        Unit::create(['code' => 'KG', 'name' => 'Kilogram']);

        $this->get(route('units.index', ['q' => 'pa', 'status' => 'inactive']))->assertInertia(fn (Assert $page) => $page
            ->has('units.data', 1)->where('units.data.0.code', 'PACK')->where('units.total', 1)
            ->where('summary.total', 3)->where('summary.active', 2)->where('filters.q', 'pa')->where('filters.status', 'inactive'));
    }

    public function test_search_treats_wildcards_as_literal_characters(): void
    {
        $this->admin();
        Unit::create(['code' => 'ONE', 'name' => '100% unit']);
        Unit::create(['code' => 'TWO', 'name' => 'Other unit']);
        $this->get(route('units.index', ['q' => '%']))->assertInertia(fn (Assert $page) => $page
            ->has('units.data', 1)->where('units.data.0.code', 'ONE'));
    }

    public function test_sorting_and_pagination_preserve_the_selected_filters(): void
    {
        $this->admin();
        for ($index = 1; $index <= 13; $index++) {
            Unit::create(['code' => sprintf('U%02d', $index), 'name' => 'Matching unit '.$index]);
        }
        $this->get(route('units.index', ['q' => 'Matching', 'status' => 'active', 'sort' => 'code', 'direction' => 'desc', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('units.data', 3)->where('units.data.0.code', 'U03')
                ->where('units.current_page', 2)->where('filters.direction', 'desc')
                ->where('units.prev_page_url', fn ($url) => str_contains($url, 'q=Matching') && str_contains($url, 'status=active')));
    }

    public function test_out_of_range_page_redirects_to_the_last_page(): void
    {
        $this->admin();
        Unit::create(['code' => 'PC', 'name' => 'Piece']);
        $this->get(route('units.index', ['page' => 99]))->assertRedirectContains('page=1');
    }

    public function test_empty_data_and_empty_search_return_valid_distinct_page_props(): void
    {
        $this->admin();
        $this->get(route('units.index'))->assertInertia(fn (Assert $page) => $page
            ->has('units.data', 0)->where('summary.total', 0)->where('filters.q', ''));
        Unit::create(['code' => 'PC', 'name' => 'Piece']);
        $this->get(route('units.index', ['q' => 'Not found']))->assertInertia(fn (Assert $page) => $page
            ->has('units.data', 0)->where('summary.total', 1)->where('filters.q', 'Not found'));
    }

    public function test_admin_can_create_a_unit_and_codes_are_normalized(): void
    {
        $this->admin();
        $this->post(route('units.store'), ['code' => ' box ', 'name' => ' Box ', 'is_active' => true])
            ->assertRedirect(route('units.index'))->assertSessionHas('success');
        $this->assertDatabaseHas('units', ['code' => 'BOX', 'name' => 'Box', 'is_active' => true]);
    }

    public function test_duplicate_codes_are_rejected_including_inactive_codes(): void
    {
        $this->admin();
        Unit::create(['code' => 'BOX', 'name' => 'Old box', 'is_active' => false]);
        $this->post(route('units.store'), ['code' => 'box', 'name' => 'New box', 'is_active' => true])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('units', 1);
    }

    public function test_invalid_form_data_is_not_saved(): void
    {
        $this->admin();
        $this->post(route('units.store'), ['code' => ' ', 'name' => '', 'is_active' => 'wrong'])
            ->assertSessionHasErrors(['code', 'name', 'is_active']);
        $this->assertDatabaseCount('units', 0);
    }

    public function test_admin_can_edit_a_unit_without_changing_its_identity(): void
    {
        $this->admin();
        $unit = Unit::create(['code' => 'BOX', 'name' => 'Old box']);
        $this->put(route('units.update', $unit), ['code' => 'BOX', 'name' => 'Updated box', 'is_active' => true])
            ->assertRedirect(route('units.index'))->assertSessionHas('success');
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'code' => 'BOX', 'name' => 'Updated box']);
    }

    public function test_admin_can_deactivate_and_reactivate_without_deleting_the_record(): void
    {
        $this->admin();
        $unit = Unit::create(['code' => 'PC', 'name' => 'Piece']);
        $this->patch(route('units.status', $unit), ['is_active' => false])->assertSessionHas('success');
        $this->assertFalse($unit->fresh()->is_active);
        $this->patch(route('units.status', $unit), ['is_active' => true])->assertSessionHas('success');
        $this->assertTrue($unit->fresh()->is_active);
        $this->assertDatabaseCount('units', 1);
    }

    public function test_referenced_active_unit_cannot_be_deactivated_via_either_endpoint(): void
    {
        $this->admin();
        $unit = Unit::create(['code' => 'PC', 'name' => 'Piece']);
        app(SaveItem::class)->handle(['part_number' => 'PN', 'item_name' => 'Item', 'ownership_type' => 'STOCK', 'unit_id' => $unit->id, 'minimum_stock' => 0]);

        $this->get(route('units.index'))->assertInertia(fn (Assert $page) => $page->where('units.data.0.active_items_count', 1));
        $this->patch(route('units.status', $unit), ['is_active' => false])->assertSessionHasErrors('is_active');
        $this->put(route('units.update', $unit), ['code' => 'CHANGED', 'name' => 'Changed', 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'code' => 'PC', 'name' => 'Piece', 'is_active' => true]);
    }

    public function test_mutations_preserve_list_filters_in_the_redirect(): void
    {
        $this->admin();
        $this->post(route('units.store', ['q' => 'Box', 'status' => 'active', 'page' => 2]), $this->payload())
            ->assertRedirect(route('units.index', ['q' => 'Box', 'status' => 'active', 'page' => 2]));
    }

    public function test_there_is_no_delete_endpoint(): void
    {
        $this->admin();
        $unit = Unit::create(['code' => 'PC', 'name' => 'Piece']);
        $this->delete(route('units.update', $unit))->assertStatus(405);
        $this->assertDatabaseCount('units', 1);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($user);

        return $user;
    }

    private function payload(): array
    {
        return ['code' => 'BOX', 'name' => 'Box', 'is_active' => true];
    }
}
