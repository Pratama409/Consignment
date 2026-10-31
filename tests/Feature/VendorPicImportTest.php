<?php

namespace Tests\Feature;

use App\Models\MasterDataImport;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class VendorPicImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_previews_and_confirms_vendor_pic_import(): void
    {
        Vendor::create(['vendor_code' => 'SWI', 'vendor_name' => 'SWI']);
        $admin = User::factory()->admin()->create();
        $file = $this->workbook([['NAMA VENDOR', 'PIC'], ['SWI', 'BASUKI'], ['SEFAS', '-']]);

        $this->actingAs($admin)->post('/master-data/vendor/import', ['file' => $file])->assertRedirect();
        $batch = MasterDataImport::latest()->firstOrFail();
        $this->assertSame('vendor_preview', $batch->status);
        $this->assertSame(['UPDATE', 'CREATE'], array_column($batch->data['rows'], 'action'));

        $this->post("/master-data/vendor/import/{$batch->id}/confirm")->assertRedirect(route('vendors.index'));
        $this->assertSame('BASUKI', Vendor::where('vendor_name', 'SWI')->value('pic_name'));
        $this->assertNull(Vendor::where('vendor_name', 'SEFAS')->value('pic_name'));
        $this->assertSame('vendor_applied', $batch->fresh()->status);
    }

    public function test_vendor_import_rejects_duplicate_names_and_non_admin(): void
    {
        $file = $this->workbook([['NAMA VENDOR', 'PIC'], ['SWI', 'A'], [' swi ', 'B']]);
        $this->actingAs(User::factory()->warehouse()->create())->post('/master-data/vendor/import', ['file' => $file])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->post('/master-data/vendor/import', ['file' => $file])->assertSessionHasErrors('file');
    }

    private function workbook(array $rows): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'vendor-import').'.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'vendors.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
