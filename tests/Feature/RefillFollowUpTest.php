<?php

namespace Tests\Feature;

use App\Actions\Refills\AcknowledgeRefillFollowUp;
use App\Actions\Refills\CreateRefillShipment;
use App\Actions\Refills\CreateVendorRefill;
use App\Actions\Refills\RequestRefillFollowUp;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\VendorRefill;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RefillFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $warehouse;

    private User $vendorUser;

    private User $otherVendorUser;

    private VendorRefill $refill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $vendor = Vendor::create(['vendor_code' => 'FUP', 'vendor_name' => 'Follow Vendor']);
        $this->vendorUser = User::factory()->vendor($vendor)->create();
        $this->otherVendorUser = User::factory()->vendor(Vendor::create(['vendor_code' => 'OTH', 'vendor_name' => 'Other Vendor']))->create();
        $item = Item::create(['item_code' => 'FUP-ITEM', 'part_number' => 'FUP-PN', 'item_name' => 'Follow item', 'ownership_type' => 'CONSIGNMENT', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => 30]);
        VendorItem::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'location_id' => Location::firstOrFail()->id]);
        $this->refill = app(CreateVendorRefill::class)->handle($vendor, $this->admin, [['item_id' => $item->id, 'quantity' => '10']]);
    }

    public function test_admin_and_warehouse_request_a_follow_up_on_a_partial_refill_once(): void
    {
        $this->refill->update(['status' => 'PARTIAL']);

        $first = app(RequestRefillFollowUp::class)->handle($this->refill, $this->warehouse, 'Masih kurang 3');
        $again = app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $this->refill->followUps()->count());
        $this->assertSame('Masih kurang 3', $first->note);
        $this->assertNull($first->acknowledged_at);
        $this->assertTrue(AuditLog::where('action', 'vendor_refill.follow_up_requested')->exists());
    }

    public function test_follow_up_needs_a_partial_refill_and_an_internal_user(): void
    {
        $this->expectValidation(fn () => app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null));
        $this->refill->update(['status' => 'PARTIAL']);
        $this->expectValidation(fn () => app(RequestRefillFollowUp::class)->handle($this->refill, $this->vendorUser, null));
        $this->refill->update(['status' => 'COMPLETED']);
        $this->expectValidation(fn () => app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null));
    }

    public function test_only_the_refills_vendor_acknowledges_a_follow_up(): void
    {
        $this->refill->update(['status' => 'PARTIAL']);
        $followUp = app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null);

        $this->expectValidation(fn () => app(AcknowledgeRefillFollowUp::class)->handle($this->refill, $this->otherVendorUser));
        $this->expectValidation(fn () => app(AcknowledgeRefillFollowUp::class)->handle($this->refill, $this->admin));
        app(AcknowledgeRefillFollowUp::class)->handle($this->refill, $this->vendorUser);

        $this->assertNotNull($followUp->fresh()->acknowledged_at);
        $this->assertSame($this->vendorUser->id, $followUp->fresh()->acknowledged_by);
        $this->assertSame(0, $this->refill->followUps()->whereNull('acknowledged_at')->count());
    }

    public function test_a_new_follow_up_can_be_requested_after_the_previous_one_is_acknowledged(): void
    {
        $this->refill->update(['status' => 'PARTIAL']);
        $first = app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null);
        app(AcknowledgeRefillFollowUp::class)->handle($this->refill, $this->vendorUser);

        $second = app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $this->refill->followUps()->count());
    }

    public function test_shipping_the_remainder_acknowledges_the_follow_up(): void
    {
        $line = $this->refill->lines()->firstOrFail();
        app(CreateRefillShipment::class)->handle($this->refill, $this->vendorUser, 'DO-1', CarbonImmutable::now(), [['refill_line_id' => $line->id, 'quantity' => 4]]);
        $this->refill->update(['status' => 'PARTIAL']);
        $followUp = app(RequestRefillFollowUp::class)->handle($this->refill, $this->warehouse, null);

        app(CreateRefillShipment::class)->handle($this->refill->fresh(), $this->vendorUser, 'DO-2', CarbonImmutable::now(), [['refill_line_id' => $line->id, 'quantity' => 3]]);

        $this->assertNotNull($followUp->fresh()->acknowledged_at);
    }

    public function test_routes_and_props_follow_the_roles(): void
    {
        $this->refill->update(['status' => 'PARTIAL']);

        $this->actingAs($this->vendorUser)->post(route('refills.follow-ups.store', $this->refill))->assertForbidden();
        $this->actingAs($this->warehouse)->post(route('refills.follow-ups.store', $this->refill), ['note' => 'Tolong kirim sisa'])->assertSessionHasNoErrors();
        $this->actingAs($this->warehouse)->get(route('refills.show', $this->refill))->assertInertia(fn (Assert $page) => $page
            ->where('refill.follow_up.can_request', false)->where('refill.follow_up.open', 1));

        $this->actingAs($this->otherVendorUser)->post(route('refills.follow-ups.acknowledge', $this->refill))->assertForbidden();
        $this->actingAs($this->admin)->post(route('refills.follow-ups.acknowledge', $this->refill))->assertForbidden();
        $this->actingAs($this->vendorUser)->get(route('refills.show', $this->refill))->assertInertia(fn (Assert $page) => $page
            ->where('refill.follow_up.can_acknowledge', true)->where('refill.follow_up.open', 1)->where('refill.follow_up.note', 'Tolong kirim sisa'));
        $this->actingAs($this->vendorUser)->get(route('refills.index'))->assertInertia(fn (Assert $page) => $page
            ->where('refills.data.0.follow_up_open', 1));

        $this->actingAs($this->vendorUser)->post(route('refills.follow-ups.acknowledge', $this->refill))->assertSessionHasNoErrors();
        $this->actingAs($this->vendorUser)->get(route('refills.show', $this->refill))->assertInertia(fn (Assert $page) => $page
            ->where('refill.follow_up.open', 0)->where('refill.follow_up.can_acknowledge', false));
    }

    public function test_vendor_navigation_badge_counts_only_its_own_open_follow_ups(): void
    {
        $this->refill->update(['status' => 'PARTIAL']);
        app(RequestRefillFollowUp::class)->handle($this->refill, $this->admin, null);

        $this->actingAs($this->vendorUser)->get(route('refills.index'))->assertInertia(fn (Assert $page) => $page->where('navigation.badges.refills', 1));
        $this->actingAs($this->otherVendorUser)->get(route('refills.index'))->assertInertia(fn (Assert $page) => $page->where('navigation.badges.refills', 0));
        $this->actingAs($this->admin)->get(route('refills.index'))->assertInertia(fn (Assert $page) => $page->where('navigation.badges.refills', 0));
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
