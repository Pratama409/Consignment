<?php

namespace Database\Seeders;

use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\MaterialRequests\ApproveMaterialRequest;
use App\Actions\MaterialRequests\CreateMaterialIssue;
use App\Actions\MaterialRequests\CreateMaterialRequest;
use App\Actions\MaterialRequests\SetMaterialRequestLineReadiness;
use App\Actions\OpeningBalance\ActivateOpeningBalance;
use App\Actions\OpeningBalance\SaveOpeningBalanceBatch;
use App\Actions\Refills\CreateRefillShipment;
use App\Actions\Refills\CreateVendorRefill;
use App\Actions\Refills\PostRefillReceipt;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Department;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockItemLocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Dummy data for demos and manual testing. Everything operational goes through the real actions,
 * so ledger invariants hold. Runs only in the local and testing environments.
 *
 * Demo accounts (password "Demo-password-123"): admin@demo.test, warehouse@demo.test, vendor@demo.test.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'Demo-password-123';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo data (known passwords) may only be seeded in the local or testing environment.');
        }

        $this->call(MasterDataSeeder::class);
        if (User::where('email', 'admin@demo.test')->exists()) {
            return;
        }

        $admin = $this->user('Admin Demo', 'admin@demo.test', UserRole::Admin, null, 'General Leader');
        $warehouse = $this->user('Warehouse Demo', 'warehouse@demo.test', UserRole::Warehouse, null, 'Staf Warehouse');

        $unit = Unit::where('code', 'PC')->firstOrFail();
        $apd = Category::create(['code' => 'APD', 'name' => 'Alat Pelindung Diri']);
        $consumable = Category::create(['code' => 'CNS', 'name' => 'Consumable']);
        $rackA = Location::create(['location_code' => 'RAK-A1', 'location_name' => 'Rak A1']);
        $rackB = Location::create(['location_code' => 'RAK-B2', 'location_name' => 'Rak B2']);

        $swi = Vendor::create(['vendor_code' => 'SWI', 'vendor_name' => 'PT Sarana Wijaya', 'pic_name' => 'Budi Santoso']);
        $novena = Vendor::create(['vendor_code' => 'NVN', 'vendor_name' => 'Novena Supply', 'pic_name' => 'Sari Lestari']);
        $vendorUser = $this->user('Vendor SWI Demo', 'vendor@demo.test', UserRole::Vendor, $swi);

        $gloves = $this->item('DEMO-GLOVE', 'GLV-001', 'Sarung tangan kerja', 'CONSIGNMENT', $unit, $apd, '30');
        $trashbag = $this->item('DEMO-TRASH', 'TRB-001', 'Trashbag hitam', 'CONSIGNMENT', $unit, $consumable, '10');
        $glue = $this->item('DEMO-GLUE', 'LEM-001', 'Lem pipa PVC', 'STOCK', $unit, $consumable, '5');
        StockItemLocation::create(['item_id' => $glue->id, 'location_id' => $rackB->id]);

        $swiGloves = VendorItem::create(['item_id' => $gloves->id, 'vendor_id' => $swi->id, 'location_id' => $rackA->id]);
        VendorItem::create(['item_id' => $gloves->id, 'vendor_id' => $novena->id, 'location_id' => $rackA->id]);
        VendorItem::create(['item_id' => $trashbag->id, 'vendor_id' => $novena->id, 'location_id' => $rackB->id]);

        $period = InventoryPeriod::create(['cutoff_at' => CarbonImmutable::now('UTC')->subDays(14)->startOfDay()]);
        $batch = app(SaveOpeningBalanceBatch::class)->handle([
            ['row_number' => 2, 'item_code' => 'DEMO-GLOVE', 'location_code' => 'RAK-A1', 'vendor_code' => 'SWI', 'quantity' => '40'],
            ['row_number' => 3, 'item_code' => 'DEMO-GLOVE', 'location_code' => 'RAK-A1', 'vendor_code' => 'NVN', 'quantity' => '25'],
            ['row_number' => 4, 'item_code' => 'DEMO-TRASH', 'location_code' => 'RAK-B2', 'vendor_code' => 'NVN', 'quantity' => '60'],
            ['row_number' => 5, 'item_code' => 'DEMO-GLUE', 'location_code' => 'RAK-B2', 'vendor_code' => null, 'quantity' => '12'],
        ], $period, $admin, 'demo-opening.xlsx');
        app(ActivateOpeningBalance::class)->handle($batch, $admin, 'demo-opening-activation');

        $refill = app(CreateVendorRefill::class)->handle($swi, $admin, [['item_id' => $gloves->id, 'quantity' => 20]], 'Refill demo sarung tangan');
        $refillLine = $refill->lines->sole();
        $shipment = app(CreateRefillShipment::class)->handle($refill, $vendorUser, 'DO-SWI-001', CarbonImmutable::now(), [['refill_line_id' => $refillLine->id, 'quantity' => 20]], 'JNE');
        app(PostRefillReceipt::class)->handle($shipment, $warehouse, 'DO-SWI-001', CarbonImmutable::now(), [['refill_line_id' => $refillLine->id, 'location_id' => $rackA->id, 'quantity' => 12]], 'demo-refill-receipt');

        $department = Department::create(['department_code' => 'OPS', 'department_name' => 'Operasional']);
        $request = app(CreateMaterialRequest::class)->handle([
            'applicant_name' => 'Rahman', 'applicant_nrp' => 'NRP-1001', 'applicant_phone' => '081234567890',
            'department_id' => $department->id, 'needed_at' => CarbonImmutable::now()->addDay(), 'purpose' => 'Perawatan unit DT534',
        ], [['item_id' => $gloves->id, 'quantity' => 5], ['item_id' => $glue->id, 'quantity' => 2]]);
        app(ApproveMaterialRequest::class)->handle($request, $admin, true, 'Disetujui untuk demo', 0);
        $lines = $request->lines()->get();
        foreach ($lines as $line) {
            app(SetMaterialRequestLineReadiness::class)->handle($line, $warehouse, 'READY');
        }
        app(CreateMaterialIssue::class)->handle($request, $warehouse, 'Rahman', CarbonImmutable::now(), [
            ['line_id' => $lines->firstWhere('item_id', $gloves->id)->id, 'vendor_item_id' => $swiGloves->id, 'location_id' => $rackA->id, 'quantity' => 3, 'is_backcharge' => true],
            ['line_id' => $lines->firstWhere('item_id', $glue->id)->id, 'quantity' => 2],
        ], 'demo-material-issue', 'WO-DEMO-001');

        // An open session with nothing counted yet, so a demo can start counting as Warehouse.
        app(CreateStockOpnameSession::class)->handle(['scope_type' => 'FULL', 'note' => 'Sesi demo Stock Opname'], $admin);
    }

    private function user(string $name, string $email, UserRole $role, ?Vendor $vendor = null, ?string $position = null): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
        $user->forceFill(['role' => $role, 'vendor_id' => $vendor?->id, 'position' => $position])->save();

        return $user;
    }

    private function item(string $code, string $partNumber, string $name, string $ownership, Unit $unit, Category $category, string $minimum): Item
    {
        return Item::create(['item_code' => $code, 'part_number' => $partNumber, 'item_name' => $name, 'ownership_type' => $ownership, 'tracking_type' => 'QUANTITY', 'unit_id' => $unit->id, 'category_id' => $category->id, 'minimum_stock' => $minimum]);
    }
}
