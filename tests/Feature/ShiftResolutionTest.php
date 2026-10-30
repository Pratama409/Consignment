<?php

namespace Tests\Feature;

use App\Actions\Shifts\ResolveOperationalShift;
use App\Actions\Shifts\SaveShiftDefinition;
use App\Enums\OperationalShiftStatus;
use App\Models\OperationalShift;
use App\Models\ShiftDefinition;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Makassar'));
        $this->seed(MasterDataSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function resolve(string $time): OperationalShift
    {
        return app(ResolveOperationalShift::class)->handle(CarbonImmutable::parse($time, 'Asia/Makassar'));
    }

    private function payload(array $overrides = []): array
    {
        return [...['code' => 'DAY', 'name' => 'Siang baru', 'start_time' => '07:00', 'end_time' => '19:00', 'effective_from' => '2026-11-01'], ...$overrides];
    }

    public function test_defaults_resolve_day_and_overnight_instances_in_wita_with_uuid_keys(): void
    {
        $day = $this->resolve('2026-10-29 06:00');
        $night = $this->resolve('2026-10-29 18:00');
        $this->assertSame('DAY', $day->code);
        $this->assertSame('2026-10-29 06:00', $day->starts_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-29 18:00', $day->ends_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertSame('NIGHT', $night->code);
        $this->assertSame('2026-10-30 06:00', $night->ends_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        $this->assertSame(12.0, $day->starts_at->diffInHours($day->ends_at));
        $this->assertSame(12.0, $night->starts_at->diffInHours($night->ends_at));
        $this->assertSame('Asia/Makassar', $day->timezone);
        $this->assertSame(OperationalShiftStatus::Open, $day->status);
        $this->assertTrue(Str::isUuid($day->id));
        $this->assertTrue(Str::isUuid($day->shift_definition_id));
    }

    public function test_two_am_belongs_to_prior_date_night_and_boundaries_are_half_open(): void
    {
        $night = $this->resolve('2026-10-28 18:00');
        $this->assertSame($night->id, $this->resolve('2026-10-29 02:00')->id);
        $this->assertSame('2026-10-28', $night->operational_date->format('Y-m-d'));
        $this->assertSame($night->id, $this->resolve('2026-10-29 05:59:59')->id);
        $day = $this->resolve('2026-10-29 06:00');
        $this->assertNotSame($night->id, $day->id);
        $this->assertSame($day->id, $this->resolve('2026-10-29 17:59:59')->id);
        $this->assertNotSame($day->id, $this->resolve('2026-10-29 18:00')->id);
        $utc = app(ResolveOperationalShift::class)->handle(CarbonImmutable::parse('2026-10-28 22:00:00', 'UTC'));
        $this->assertSame($day->id, $utc->id);
    }

    public function test_future_versions_preserve_historical_definitions_and_instances_and_cover_the_transition(): void
    {
        $admin = User::factory()->admin()->create();
        $historical = $this->resolve('2026-10-29 10:00');
        $snapshot = $historical->fresh()->getAttributes();
        $definition = $historical->definition->getAttributes();
        $new = app(SaveShiftDefinition::class)->handle($this->payload(), $admin);
        $this->assertNotSame($historical->shift_definition_id, $new->id);
        $this->assertSame($definition, $historical->definition->fresh()->getAttributes());
        $this->assertSame($snapshot, $historical->fresh()->getAttributes());
        $this->assertSame($historical->id, $this->resolve('2026-10-29 10:00')->id);
        $this->assertSame('DAY', $this->resolve('2026-10-31 06:00')->code);
        $transition = $this->resolve('2026-11-01 06:59:59');
        $this->assertSame('NIGHT', $transition->code);
        $this->assertSame('2026-10-31', $transition->operational_date->format('Y-m-d'));
        $this->assertSame('2026-11-01 07:00', $transition->ends_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        $future = $this->resolve('2026-11-01 07:00');
        $this->assertSame($new->id, $future->shift_definition_id);
        $this->assertSame('Siang baru', $future->name);
        $this->assertSame('DAY', $this->resolve('2026-11-01 18:59:59')->code);
        $this->assertSame('NIGHT', $this->resolve('2026-11-01 19:00')->code);
        $this->assertSame('DAY', $this->resolve('2026-11-02 07:00')->code);
        $this->assertSame(4, ShiftDefinition::count());
    }

    public function test_night_edit_creates_complementary_future_day_definition(): void
    {
        app(SaveShiftDefinition::class)->handle($this->payload(['code' => 'NIGHT', 'name' => 'Malam baru', 'start_time' => '20:00', 'end_time' => '08:00']), User::factory()->admin()->create());
        $this->assertSame('DAY', $this->resolve('2026-11-01 08:00')->code);
        $this->assertSame('Malam baru', $this->resolve('2026-11-01 20:00')->name);
    }

    public function test_seed_is_idempotent_and_does_not_overwrite_future_user_versions(): void
    {
        $new = app(SaveShiftDefinition::class)->handle($this->payload(), User::factory()->admin()->create());
        $snapshot = $new->fresh()->getAttributes();
        $this->seed(MasterDataSeeder::class);
        $this->assertSame(4, ShiftDefinition::count());
        $this->assertSame($snapshot, $new->fresh()->getAttributes());
    }

    public function test_admin_settings_and_warehouse_current_labels_only_and_vendor_denied(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/settings/shifts')->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Shifts/Index', false)->where('can_manage', true)->has('definitions', 2)->has('versions', 2));
        $this->post('/settings/shifts', $this->payload())->assertRedirect('/settings/shifts')->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->warehouse()->create())->get('/settings/shifts')->assertInertia(fn (Assert $page) => $page
            ->where('can_manage', false)->has('definitions', 2)->missing('versions')->missing('submit_url'));
        $this->post('/settings/shifts', $this->payload(['effective_from' => '2026-11-02']))->assertForbidden();
        $vendor = Vendor::create(['vendor_code' => 'SHIFT-V', 'vendor_name' => 'Supplier']);
        $this->actingAs(User::factory()->vendor($vendor)->create())->get('/settings/shifts')->assertForbidden();
        $this->post('/settings/shifts', $this->payload())->assertForbidden();
        $this->assertSame(4, ShiftDefinition::count());
    }

    public function test_action_rejects_non_admin_even_outside_http(): void
    {
        $this->expectException(AuthorizationException::class);
        app(SaveShiftDefinition::class)->handle($this->payload(), User::factory()->warehouse()->create());
    }

    public function test_invalid_or_non_future_versions_cannot_be_saved(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach ([['effective_from' => '2026-10-29'], ['effective_from' => '2026-10-28'], ['code' => 'OTHER'], ['start_time' => '19:00'], ['end_time' => '07:00'], ['end_time' => '20:00'], ['start_time' => 'bad'], ['name' => '']] as $invalid) {
            $this->post('/settings/shifts', $this->payload($invalid))->assertSessionHasErrors(array_key_first($invalid));
        }
        $this->assertSame(2, ShiftDefinition::count());
    }

    public function test_duplicate_or_out_of_order_versions_and_changes_touching_materialized_instances_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        app(SaveShiftDefinition::class)->handle($this->payload(), $admin);
        foreach (['2026-11-01', '2026-10-31'] as $date) {
            try {
                app(SaveShiftDefinition::class)->handle($this->payload(['effective_from' => $date]), $admin);
                $this->fail('Expected version ordering validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('effective_from', $exception->errors());
            }
        }
        $night = $this->resolve('2026-11-02 02:00');
        $snapshot = $night->fresh()->getAttributes();
        $this->actingAs($admin)->post('/settings/shifts', $this->payload(['effective_from' => '2026-11-02']))->assertSessionHasErrors('effective_from');
        $this->assertSame($snapshot, $night->fresh()->getAttributes());
        $this->assertSame(4, ShiftDefinition::count());
    }

    public function test_instance_grouping_and_definition_records_cannot_be_rewritten_or_deleted(): void
    {
        $shift = $this->resolve('2026-10-29 10:00');
        foreach ([$shift, $shift->definition] as $model) {
            try {
                $model->update(['name' => 'Rewritten']);
                $this->fail('Expected immutable schedule validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('shift', $exception->errors());
            }
            $model->refresh();
            try {
                $model->delete();
                $this->fail('Expected immutable schedule validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('shift', $exception->errors());
            }
        }
    }
}
