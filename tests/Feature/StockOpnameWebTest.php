<?php

namespace Tests\Feature;

use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\Inventory\PostStockIn;
use App\Actions\Inventory\RecordOpnameCount;
use App\Actions\Inventory\SubmitOpnameCount;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockOpnameStatus;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\StockOpnameSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithStockBuckets;
use Tests\TestCase;

class StockOpnameWebTest extends TestCase
{
    use InteractsWithStockBuckets, RefreshDatabase;

    private const SYSTEM = '317.4321';

    private User $admin;

    private User $warehouse;

    private User $vendor;

    private Location $location;

    private Item $item;

    private StockBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
        $this->admin = User::factory()->admin()->create();
        $this->warehouse = User::factory()->warehouse()->create();
        $this->vendor = User::factory()->vendor(Vendor::create(['vendor_code' => 'WEB-V', 'vendor_name' => 'Web Vendor']))->create();
        $this->location = Location::firstOrFail();
        InventoryPeriod::create(['cutoff_at' => '2026-10-28 00:00:00', 'status' => 'ACTIVE']);
        $this->item = Item::create(['item_code' => 'WEB-ITEM', 'part_number' => 'PN-WEB', 'item_name' => 'Web item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $this->item->id, 'location_id' => $this->location->id]);
        app(PostStockIn::class)->handle(new StockPostingData(StockTransactionType::ManualReceipt, 'test-source', (string) Str::uuid(), now()->toImmutable(), $this->admin->id, (string) Str::uuid(), 'test', [
            new StockPostingLineData($this->item->id, null, $this->location->id, StockDirection::In, self::SYSTEM),
        ]));
        $this->bucket = $this->bucketFor($this->item, null, $this->location);
    }

    public function test_vendor_is_forbidden_everywhere(): void
    {
        $session = $this->newSession();
        $line = $session->lines()->firstOrFail();

        $this->actingAs($this->vendor)->get(route('stock-opname.index'))->assertForbidden();
        $this->actingAs($this->vendor)->get(route('stock-opname.create'))->assertForbidden();
        $this->actingAs($this->vendor)->post(route('stock-opname.store'), ['scope_type' => 'FULL'])->assertForbidden();
        $this->actingAs($this->vendor)->get(route('stock-opname.show', $session))->assertForbidden();
        $this->actingAs($this->vendor)->put(route('stock-opname.count', [$session, $line]), ['quantity' => '1'])->assertForbidden();
        $this->actingAs($this->vendor)->post(route('stock-opname.submit', $session))->assertForbidden();
    }

    public function test_warehouse_never_receives_system_quantity_or_difference(): void
    {
        $session = $this->newSession();
        $response = $this->actingAs($this->warehouse)->get(route('stock-opname.show', $session));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Opname/Show')
            ->has('lines', 1)
            ->missing('lines.0.system_quantity')
            ->missing('lines.0.difference')
            ->missing('lines.0.state')
            ->missing('lines.0.movements')
            ->where('can.count', true)
            ->where('can.approve', false));
        $this->assertStringNotContainsString(self::SYSTEM, $response->getContent());

        app(RecordOpnameCount::class)->handle($session->lines()->firstOrFail(), '300', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
        $submitted = $this->actingAs($this->warehouse)->get(route('stock-opname.show', $session));
        $this->assertStringNotContainsString(self::SYSTEM, $submitted->getContent());
    }

    public function test_admin_sees_system_quantity_difference_and_state(): void
    {
        $session = $this->countedSession('300');

        $this->actingAs($this->admin)->get(route('stock-opname.show', $session))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('lines.0.system_quantity', self::SYSTEM)
            ->where('lines.0.difference', '-17.4321')
            ->where('lines.0.state', 'SHORT')
            ->where('can.approve', true)
            ->where('can.count', false));
    }

    public function test_roles_are_enforced_on_every_action(): void
    {
        $session = $this->countedSession('300');
        $line = $session->lines()->firstOrFail();

        foreach ([
            ['post', route('stock-opname.store'), ['scope_type' => 'FULL']],
            ['post', route('stock-opname.approve', $session), []],
            ['post', route('stock-opname.close', $session), []],
            ['post', route('stock-opname.cancel', $session), ['reason' => 'x']],
            ['post', route('stock-opname.recount', [$session, $line]), []],
        ] as [$method, $url, $data]) {
            $this->actingAs($this->warehouse)->{$method}($url, $data)->assertForbidden();
        }
        $this->actingAs($this->warehouse)->get(route('stock-opname.create'))->assertForbidden();
        $this->actingAs($this->admin)->put(route('stock-opname.count', [$session, $line]), ['quantity' => '1'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('stock-opname.submit', $session))->assertForbidden();
    }

    public function test_admin_creates_a_session_and_lands_on_it(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stock-opname.store'), ['scope_type' => 'FULL', 'note' => 'Akhir bulan']);

        $session = StockOpnameSession::firstOrFail();
        $response->assertRedirect(route('stock-opname.show', $session));
        $this->assertSame(StockOpnameStatus::Counting, $session->status);
        $this->actingAs($this->admin)->post(route('stock-opname.store'), ['scope_type' => 'WEIRD'])->assertSessionHasErrors('scope_type');
        $this->actingAs($this->admin)->get(route('stock-opname.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Opname/Create')->has('buckets', 1));
    }

    public function test_warehouse_counts_and_submits_through_the_routes(): void
    {
        $session = $this->newSession();
        $line = $session->lines()->firstOrFail();

        $this->actingAs($this->warehouse)->put(route('stock-opname.count', [$session, $line]), ['quantity' => '-1'])->assertSessionHasErrors('quantity');
        $this->actingAs($this->warehouse)->post(route('stock-opname.submit', $session))->assertSessionHasErrors('session');
        $this->actingAs($this->warehouse)->put(route('stock-opname.count', [$session, $line]), ['quantity' => '300'])->assertSessionHasNoErrors();
        $this->actingAs($this->warehouse)->post(route('stock-opname.submit', $session))->assertSessionHasNoErrors();

        $this->assertSame(StockOpnameStatus::Counted, $session->fresh()->status);
        $this->assertSame(self::SYSTEM, $line->fresh()->system_quantity);
        $this->actingAs($this->warehouse)->put(route('stock-opname.count', [$session, $line]), ['quantity' => '1'])->assertSessionHasErrors('session');
    }

    public function test_a_line_from_another_session_is_not_found(): void
    {
        $session = $this->newSession();
        $other = $this->newSession($this->anotherBucket());

        $this->actingAs($this->warehouse)->put(route('stock-opname.count', [$session, $other->lines()->firstOrFail()]), ['quantity' => '1'])->assertNotFound();
    }

    public function test_admin_approves_closes_and_cancels_through_the_routes(): void
    {
        $session = $this->countedSession('300');
        $this->actingAs($this->admin)->post(route('stock-opname.close', $session))->assertSessionHasErrors('session');
        $this->actingAs($this->admin)->post(route('stock-opname.approve', $session))->assertSessionHasNoErrors();
        $this->assertSame(StockOpnameStatus::Resolving, $session->fresh()->status);

        $secondBucket = $this->anotherBucket();
        $second = $this->newSession($secondBucket);
        app(RecordOpnameCount::class)->handle($second->lines()->firstOrFail(), '1', $this->warehouse);
        app(SubmitOpnameCount::class)->handle($second, $this->warehouse);
        $this->actingAs($this->admin)->post(route('stock-opname.cancel', $second), ['reason' => ''])->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('stock-opname.cancel', $second), ['reason' => 'Salah cakupan'])->assertSessionHasNoErrors();
        $this->assertSame(StockOpnameStatus::Cancelled, $second->fresh()->status);
    }

    public function test_index_lists_sessions_for_internal_roles(): void
    {
        $this->newSession();

        foreach ([$this->admin, $this->warehouse] as $user) {
            $this->actingAs($user)->get(route('stock-opname.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Opname/Index')->has('sessions.data', 1)->where('can.create', $user->isAdmin()));
        }
    }

    private function newSession(?StockBucket $bucket = null): StockOpnameSession
    {
        return app(CreateStockOpnameSession::class)->handle(['scope_type' => 'PARTIAL', 'bucket_ids' => [($bucket ?? $this->bucket)->id]], $this->admin);
    }

    /** A second stocked position, because a bucket may be in only one open session at a time. */
    private function anotherBucket(): StockBucket
    {
        $item = Item::create(['item_code' => 'WEB-'.Str::random(6), 'part_number' => 'PN-WEB2', 'item_name' => 'Second web item', 'ownership_type' => 'STOCK', 'tracking_type' => 'QUANTITY', 'unit_id' => Unit::firstOrFail()->id, 'minimum_stock' => '0.0000']);
        StockItemLocation::create(['item_id' => $item->id, 'location_id' => $this->location->id]);

        return $this->bucketFor($item, null, $this->location);
    }

    private function countedSession(string $counted): StockOpnameSession
    {
        $session = $this->newSession();
        app(RecordOpnameCount::class)->handle($session->lines()->firstOrFail(), $counted, $this->warehouse);

        return app(SubmitOpnameCount::class)->handle($session, $this->warehouse);
    }
}
