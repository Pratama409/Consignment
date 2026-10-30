<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonitoringSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_and_reconciliation_tables_use_uuid_identities(): void
    {
        foreach (['shift_recap_snapshots', 'vendor_reconciliations', 'vendor_reconciliation_lines', 'vendor_reconciliation_responses', 'vendor_reconciliation_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
            $this->assertTrue(Schema::hasColumn($table, 'id'), "Missing UUID id on {$table}");
        }
    }
}
