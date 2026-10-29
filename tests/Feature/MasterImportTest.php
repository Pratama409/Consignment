<?php

namespace Tests\Feature;

use App\Actions\MasterData\SaveItem;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Item;
use App\Models\Location;
use App\Models\MasterDataImport;
use App\Models\Unit;
use App\Models\User;
use App\Services\MasterData\ImportPlanner;
use App\Services\MasterData\WorkbookReader;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MasterImportTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_real_xlsx_upload_only_creates_a_preview_and_normalizes_legacy_values(): void
    {
        $batch = $this->upload([$this->row(['MATERIAL_TYPE' => 'CONSIGMENT', 'VENDOR' => 'Vendor Baru', 'LOCATION' => 'CARI LOKASI'])], true);
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('vendors', 0);
        $this->get(route('imports.show', $batch))->assertInertia(fn (Assert $page) => $page->component('MasterData/Import/Preview')->where('summary.new_items', 1)->where('summary.new_vendors', 1)->where('groups.data.0.data.ownership_type', 'CONSIGNMENT')->where('groups.data.0.rows.0.location_code', 'UNASSIGNED'));
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $item = Item::sole();
        $this->assertSame('000123.', $item->part_number);
        $this->assertSame('4.2500', $item->minimum_stock);
        $this->assertSame('VENDOR BARU', $item->vendorItems()->sole()->vendor->vendor_code);
        $this->assertSame(Location::where('location_code', 'UNASSIGNED')->value('id'), $item->vendorItems()->sole()->location_id);
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 1);
        $this->get(route('imports.show', $batch))->assertInertia(fn (Assert $page) => $page->component('MasterData/Import/Result')->where('result.created', 1));
    }

    public function test_multiple_locations_require_an_explicit_group_or_split_decision(): void
    {
        $batch = $this->upload([$this->row(['LOCATION' => 'R01']), $this->row(['LOCATION' => 'R02'])], true);
        $group = $this->plan($batch)['groups'][0];
        $this->assertSame('review', $group['state']);
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->put(route('imports.decision', [$batch, $group['id']]), ['action' => 'create', 'minimum_stock' => '7.5'])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 1);
        $this->assertCount(2, Item::sole()->stockItemLocations);
        $this->assertSame('7.5000', Item::sole()->minimum_stock);
    }

    public function test_split_creates_distinct_items_and_duplicate_rows_require_acknowledgement(): void
    {
        $batch = $this->upload([$this->row(['LOCATION' => 'A']), $this->row(['LOCATION' => 'B']), $this->row(['LOCATION' => 'A'])], true);
        $group = $this->plan($batch)['groups'][0];
        $this->put(route('imports.decision', [$batch, $group['id']]), ['action' => 'split'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->plan($batch)['summary']['skipped_rows']);
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
        $this->post(route('imports.confirm', $batch), ['acknowledge_skipped' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 2);
        $this->assertSame(2, Item::distinct()->count('item_code'));
    }

    public function test_same_pn_does_not_automatically_update_an_existing_item(): void
    {
        $existing = $this->saveItem();
        $batch = $this->upload([$this->row()]);
        $group = $this->plan($batch)['groups'][0];
        $this->assertSame('review', $group['state']);
        $this->put(route('imports.decision', [$batch, $group['id']]), ['action' => 'create'])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 2);
        $this->assertSame('0.0000', $existing->fresh()->minimum_stock);
    }

    public function test_match_by_internal_code_preserves_omitted_relations_and_updates_in_place(): void
    {
        $old = Location::create(['location_code' => 'OLD']);
        $item = $this->saveItem(['stock_locations' => [['location_id' => $old->id, 'is_active' => true]]]);
        $relationId = $item->stockItemLocations()->sole()->id;
        $batch = $this->upload([$this->row(['ITEM_CODE' => $item->item_code, 'LOCATION' => 'NEW', 'MINIMUM_STOCK' => '12.25'])], true);
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 1);
        $this->assertSame('12.2500', $item->fresh()->minimum_stock);
        $this->assertCount(2, $item->fresh()->stockItemLocations);
        $this->assertDatabaseHas('stock_item_locations', ['id' => $relationId, 'is_active' => true]);
    }

    public function test_unknown_references_compound_location_and_unknown_unit_are_resolved_in_preview(): void
    {
        $batch = $this->upload([$this->row(['LOCATION' => 'A & B', 'UOM' => 'UOM001'])]);
        $this->assertSame(1, $this->plan($batch)['summary']['blocking_groups']);
        $this->put(route('imports.correction', [$batch, 2]), ['skip' => false, 'LOCATION' => 'R-NEW'])->assertSessionHasNoErrors();
        $this->put(route('imports.options', $batch), ['create_missing' => true, 'minimum_stock' => '8', 'unit_map' => ['UOM001' => Unit::where('code', 'PC')->value('id')]])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->plan($batch)['summary']['blocking_groups']);
        $this->assertDatabaseMissing('locations', ['location_code' => 'R-NEW']);
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('locations', ['location_code' => 'R-NEW']);
    }

    public function test_invalid_row_can_be_explicitly_skipped_and_rest_of_file_imported(): void
    {
        $batch = $this->upload([$this->row(), $this->row(['PART_NUMBER' => 'BROKEN', 'MINIMUM_STOCK' => '-2'])]);
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
        $this->put(route('imports.correction', [$batch, 3]), ['skip' => true])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $batch), ['acknowledge_skipped' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 1);
        $this->assertSame(1, $batch->fresh()->result['skipped_rows']);
    }

    public function test_confirmation_revalidates_references_and_rolls_back_the_whole_batch(): void
    {
        $batch = $this->upload([$this->row(['LOCATION' => 'NEW']), $this->row(['PART_NUMBER' => 'SECOND'])], true);
        $this->assertSame(2, $this->plan($batch)['summary']['ready_groups']);
        Unit::where('code', 'PC')->firstOrFail()->update(['is_active' => false]);
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseMissing('locations', ['location_code' => 'NEW']);
        $this->assertSame('preview', $batch->fresh()->status);
    }

    public function test_ownership_change_and_conflicting_vendor_locations_block_import(): void
    {
        $item = $this->saveItem();
        $batch = $this->upload([$this->row(['ITEM_CODE' => $item->item_code, 'MATERIAL_TYPE' => 'CONSIGNMENT', 'VENDOR' => 'V1'])], true);
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('vendors', 0);
        $other = $this->upload([$this->row(['MATERIAL_TYPE' => 'CONSIGNMENT', 'VENDOR' => 'V1', 'LOCATION' => 'A']), $this->row(['MATERIAL_TYPE' => 'CONSIGNMENT', 'VENDOR' => 'V1', 'LOCATION' => 'B'])], true);
        $group = $this->plan($other)['groups'][0];
        $this->put(route('imports.decision', [$other, $group['id']]), ['action' => 'create'])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $other))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 1);
    }

    public function test_failure_after_first_item_rolls_back_items_references_and_batch_result(): void
    {
        $batch = $this->upload([$this->row(['LOCATION' => 'ROLLBACK-NEW']), $this->row(['PART_NUMBER' => 'FAIL-SECOND'])], true);
        $calls = 0;
        $this->mock(SaveItem::class)->shouldReceive('handle')->twice()->andReturnUsing(function ($data, $target) use (&$calls) {
            if (++$calls === 2) {
                throw ValidationException::withMessages(['part_number' => 'Simulated second-row validation failure.']);
            }

            return (new SaveItem)->handle($data, $target);
        });
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('stock_item_locations', 0);
        $this->assertDatabaseMissing('locations', ['location_code' => 'ROLLBACK-NEW']);
        $this->assertSame('preview', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->result);
    }

    public function test_conflicting_status_for_the_same_placement_must_be_resolved(): void
    {
        $batch = $this->upload([$this->row(['RELATION_ACTIVE' => '1']), $this->row(['RELATION_ACTIVE' => '0'])]);
        $group = $this->plan($batch)['groups'][0];
        $this->put(route('imports.decision', [$batch, $group['id']]), ['action' => 'create'])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_preview_and_mutations_are_owner_scoped_and_expire_after_a_day(): void
    {
        $batch = $this->upload([$this->row()]);
        $owner = auth()->user();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('imports.show', $batch))->assertForbidden();
        $this->post(route('imports.confirm', $batch))->assertForbidden();
        $this->put(route('imports.options', $batch))->assertForbidden();
        $this->actingAs($owner);
        $this->travel(25)->hours();
        $this->get(route('imports.show', $batch))->assertOk()->assertInertia(fn (Assert $page) => $page->component('MasterData/Import/Expired'));
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_reader_does_not_evaluate_formulas_or_guess_numeric_locations(): void
    {
        $file = $this->workbook([$this->row(['PART_NUMBER' => '=1+1', 'LOCATION' => 5.01E+14])], ['PART_NUMBER', 'LOCATION']);
        $data = app(WorkbookReader::class)->read($file);
        $this->assertArrayHasKey('PART_NUMBER', $data['rows'][0]['issues']);
        $this->assertArrayHasKey('LOCATION', $data['rows'][0]['issues']);
        $this->assertSame('=1+1', $data['rows'][0]['values']['PART_NUMBER']);
        $this->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('bad.xlsx', 'not a workbook'), 'create_missing' => false, 'minimum_stock' => '0'])->assertSessionHasErrors('file');
    }

    public function test_export_is_filtered_preserves_strings_and_can_be_reimported_by_code(): void
    {
        $item = $this->saveItem(['part_number' => '=1+1', 'item_name' => 'Formula-like PN']);
        $this->saveItem(['part_number' => 'EXCLUDED']);
        $response = $this->get(route('items.export', ['q' => 'Formula-like']));
        $response->assertOk()->assertDownload();
        $path = $this->temporaryFile();
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame(2, $book->getActiveSheet()->getHighestDataRow());
        $this->assertSame(DataType::TYPE_STRING, $book->getActiveSheet()->getCell('C2')->getDataType());
        $this->assertSame('=1+1', $book->getActiveSheet()->getCell('C2')->getValue());
        $this->assertSame($item->item_code, $book->getActiveSheet()->getCell('A2')->getValue());
        $book->disconnectWorksheets();
        $this->post(route('imports.store'), ['file' => new UploadedFile($path, 'roundtrip.xlsx', null, null, true), 'create_missing' => false, 'minimum_stock' => '0'])->assertSessionHasNoErrors();
        $batch = MasterDataImport::latest('id')->firstOrFail();
        $this->assertSame('match', $this->plan($batch)['groups'][0]['action']);
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('items', 2);
        $this->assertDatabaseCount('stock_item_locations', 0);
    }

    public function test_category_import_export_and_blank_reimport_preserve_explicit_classification(): void
    {
        $category = Category::create(['code' => 'FILTER', 'name' => 'Filter']);
        $batch = $this->upload([$this->row(['CATEGORY_CODE' => ' filter '])]);
        $this->assertSame($category->id, $this->plan($batch)['groups'][0]['data']['category_id']);
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $item = Item::sole();
        $this->assertSame($category->id, $item->category_id);
        $export = $this->get(route('items.export', ['category' => $category->id]));
        $path = $this->temporaryFile();
        file_put_contents($path, $export->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame('CATEGORY_CODE', $book->getActiveSheet()->getCell('L1')->getValue());
        $this->assertSame('FILTER', $book->getActiveSheet()->getCell('L2')->getValue());
        $book->disconnectWorksheets();
        $update = $this->upload([$this->row(['ITEM_CODE' => $item->item_code, 'CATEGORY_CODE' => ''])]);
        $this->post(route('imports.confirm', $update))->assertSessionHasNoErrors();
        $this->assertSame($category->id, $item->fresh()->category_id);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_unknown_category_is_not_created_automatically_and_can_be_corrected(): void
    {
        $category = Category::create(['code' => 'GET', 'name' => 'GET']);
        $batch = $this->upload([$this->row(['CATEGORY_CODE' => 'Bushing description from legacy data'])], true);
        $this->assertSame('invalid', $this->plan($batch)['groups'][0]['state']);
        $this->post(route('imports.confirm', $batch))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseCount('categories', 1);
        $this->put(route('imports.correction', [$batch, 2]), ['skip' => false, 'CATEGORY_CODE' => 'GET'])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        $this->assertSame($category->id, Item::sole()->category_id);
    }

    public function test_inactive_category_is_rejected_and_conflicting_categories_are_not_deduplicated(): void
    {
        Category::create(['code' => 'OLD', 'name' => 'Old', 'is_active' => false]);
        $batch = $this->upload([$this->row(['CATEGORY_CODE' => 'OLD'])]);
        $this->assertArrayHasKey('CATEGORY_CODE', $this->plan($batch)['groups'][0]['rows'][0]['errors']);
        Category::create(['code' => 'A', 'name' => 'A']);
        Category::create(['code' => 'B', 'name' => 'B']);
        $other = $this->upload([$this->row(['CATEGORY_CODE' => 'A']), $this->row(['CATEGORY_CODE' => 'B'])]);
        $group = $this->plan($other)['groups'][0];
        $this->assertSame(0, $group['duplicate_count']);
        $this->put(route('imports.decision', [$other, $group['id']]), ['action' => 'create'])->assertSessionHasNoErrors();
        $this->post(route('imports.confirm', $other))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
    }

    public function test_category_alias_is_supported_but_unknown_columns_require_separate_confirmation(): void
    {
        $category = Category::create(['code' => 'BATTERY', 'name' => 'Battery']);
        $columns = [...array_map(fn ($column) => $column === 'CATEGORY_CODE' ? 'CATEGORY' : $column, WorkbookReader::COLUMNS), 'LEGACY_PRICE'];
        $file = $this->workbook([$this->row(['CATEGORY' => 'BATTERY', 'LEGACY_PRICE' => '15000'])], [], $columns);
        $this->post(route('imports.store'), ['file' => $file, 'create_missing' => false, 'minimum_stock' => '0'])->assertSessionHasNoErrors();
        $batch = MasterDataImport::latest('id')->firstOrFail();
        $this->assertSame(['LEGACY_PRICE'], $batch->data['ignored_columns']);
        $this->get(route('imports.show', $batch))->assertInertia(fn (Assert $page) => $page->where('batch.ignored_columns.0', 'LEGACY_PRICE')->where('groups.data.0.data.category_id', $category->id));
        $this->post(route('imports.confirm', $batch), ['acknowledge_skipped' => true])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('items', 0);
        $this->post(route('imports.confirm', $batch), ['acknowledge_ignored_columns' => true])->assertSessionHasNoErrors();
        $this->assertSame($category->id, Item::sole()->category_id);
        $this->assertSame(['LEGACY_PRICE'], $batch->fresh()->result['ignored_columns']);
    }

    private function row(array $changes = []): array
    {
        return [...['ITEM_CODE' => '', 'VENDOR' => 'WAREHOUSE', 'PART_NUMBER' => '000123.', 'DESCRIPTION' => 'Bearing', 'LOCATION' => 'UNASSIGNED', 'UOM' => 'PC', 'MATERIAL_TYPE' => 'STOCK', 'MINIMUM_STOCK' => '', 'IS_ACTIVE' => '', 'TRACKING_TYPE' => '', 'RELATION_ACTIVE' => ''], ...$changes];
    }

    private function upload(array $rows, bool $create = false): MasterDataImport
    {
        $this->post(route('imports.store'), ['file' => $this->workbook($rows), 'create_missing' => $create, 'minimum_stock' => '4.25'])->assertSessionHasNoErrors()->assertRedirect();

        return MasterDataImport::latest('id')->firstOrFail();
    }

    private function plan(MasterDataImport $batch): array
    {
        return app(ImportPlanner::class)->build($batch->fresh());
    }

    private function saveItem(array $changes = []): Item
    {
        return app(SaveItem::class)->handle([...['part_number' => '000123.', 'item_name' => 'Bearing', 'ownership_type' => 'STOCK', 'unit_id' => Unit::where('code', 'PC')->value('id'), 'minimum_stock' => '0', 'is_active' => true, 'tracking_type' => 'QUANTITY', 'stock_locations' => [], 'vendor_items' => []], ...$changes]);
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'consignment-test-');
        $this->files[] = $path;

        return $path;
    }

    private function workbook(array $rows, array $inferTypes = [], ?array $columns = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ($columns ?? WorkbookReader::COLUMNS as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, 1], $column, DataType::TYPE_STRING);
            foreach ($rows as $rowIndex => $row) {
                if (in_array($column, $inferTypes, true)) {
                    $sheet->setCellValue([$index + 1, $rowIndex + 2], $row[$column] ?? '');
                } else {
                    $sheet->setCellValueExplicit([$index + 1, $rowIndex + 2], (string) ($row[$column] ?? ''), DataType::TYPE_STRING);
                }
            }
        }
        $path = $this->temporaryFile();
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
