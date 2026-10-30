<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcurementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_procurement_uses_normalized_uuid_tables(): void
    {
        foreach (['procurement_requirements', 'procurement_document_lines', 'procurement_import_batches', 'procurement_import_rows', 'procurement_validations', 'procurement_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumn($table, 'id'));
        }
    }
}
