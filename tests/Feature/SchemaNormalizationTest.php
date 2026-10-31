<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string}> */
    public static function redundantColumns(): iterable
    {
        $removed = [
            'stock_ledger_entries' => ['item_id', 'vendor_item_id', 'vendor_id', 'location_id', 'bucket_key'],
            'stock_balances' => ['item_id', 'vendor_item_id', 'location_id', 'bucket_key'],
            'stock_lots' => ['item_id', 'vendor_item_id', 'location_id', 'bucket_key'],
            'opening_balance_lines' => ['item_id', 'vendor_item_id', 'location_id', 'bucket_key'],
            'procurement_requirements' => ['vendor_item_id', 'item_id', 'location_id', 'required_quantity'],
            'vendor_reconciliation_lines' => ['item_id'],
            'procurement_document_lines' => ['document_no'],
        ];
        foreach ($removed as $table => $columns) {
            foreach ($columns as $column) {
                yield "{$table}.{$column}" => [$table, $column];
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function requiredColumns(): iterable
    {
        foreach (['stock_ledger_entries', 'stock_balances', 'stock_lots', 'opening_balance_lines'] as $table) {
            yield "{$table}.stock_bucket_id" => [$table, 'stock_bucket_id'];
        }
        yield 'procurement_document_lines.procurement_reference_id' => ['procurement_document_lines', 'procurement_reference_id'];
        yield 'material_issues.work_order_no' => ['material_issues', 'work_order_no'];
        yield 'material_issue_lines.is_backcharge' => ['material_issue_lines', 'is_backcharge'];
    }

    #[DataProvider('redundantColumns')]
    public function test_redundant_column_is_absent(string $table, string $column): void
    {
        $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} duplicates a fact owned by another row.");
    }

    #[DataProvider('requiredColumns')]
    public function test_required_column_is_present(string $table, string $column): void
    {
        $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} is missing.");
    }

    public function test_normalized_tables_exist(): void
    {
        foreach (['stock_buckets', 'procurement_references'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing.");
        }
    }
}
