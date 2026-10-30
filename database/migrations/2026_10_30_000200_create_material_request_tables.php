<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('department_code', 30)->unique();
                $table->string('department_name', 150);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('material_requests')) {
            Schema::create('material_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('request_no', 60)->unique();
                $table->char('public_token_hash', 64)->unique();
                $table->string('applicant_name', 150);
                $table->string('applicant_nrp', 50);
                $table->string('applicant_phone', 30);
                $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
                $table->timestamp('needed_at');
                $table->text('purpose');
                $table->enum('status', ['SUBMITTED', 'PREPARING', 'PARTIALLY_READY', 'READY', 'PARTIALLY_ISSUED', 'COMPLETED', 'CANCELLED'])->default('SUBMITTED')->index();
                $table->timestamp('submitted_at');
                $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->index(['applicant_nrp', 'applicant_phone']);
            });
        }
        if (! Schema::hasTable('material_request_lines')) {
            Schema::create('material_request_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('material_request_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
                $table->decimal('requested_quantity', 18, 4);
                $table->enum('status', ['PENDING', 'PREPARING', 'READY', 'PARTIALLY_ISSUED', 'ISSUED', 'CANCELLED'])->default('PENDING')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['material_request_id', 'item_id'], 'mr_line_item_unique');
            });
        }
        if (! Schema::hasTable('material_request_revisions')) {
            Schema::create('material_request_revisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('material_request_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision_no');
                $table->text('reason');
                $table->foreignUuid('revised_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('revised_at');
                $table->timestamps();
                $table->unique(['material_request_id', 'revision_no'], 'mr_revision_no_unique');
            });
        }
        if (! Schema::hasTable('material_request_revision_lines')) {
            Schema::create('material_request_revision_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('material_request_revision_id');
                $table->foreign('material_request_revision_id', 'mr_revision_line_revision_fk')->references('id')->on('material_request_revisions')->cascadeOnDelete();
                $table->uuid('material_request_line_id');
                $table->foreign('material_request_line_id', 'mr_revision_line_request_line_fk')->references('id')->on('material_request_lines')->restrictOnDelete();
                $table->decimal('revised_quantity', 18, 4);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['material_request_revision_id', 'material_request_line_id'], 'mr_revision_line_unique');
            });
        }
        if (! Schema::hasTable('material_request_approvals')) {
            Schema::create('material_request_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('material_request_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision_no')->default(0);
                $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'SUPERSEDED'])->default('PENDING')->index();
                $table->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamps();
                $table->unique(['material_request_id', 'revision_no'], 'mr_approval_version_unique');
            });
        }
        if (! Schema::hasTable('material_issues')) {
            Schema::create('material_issues', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('issue_no', 60)->unique();
                $table->foreignUuid('material_request_id')->constrained()->restrictOnDelete();
                $table->enum('status', ['DRAFT', 'POSTED'])->default('DRAFT')->index();
                $table->timestamp('issued_at');
                $table->foreignUuid('issued_by')->constrained('users')->restrictOnDelete();
                $table->string('receiver_name', 150);
                $table->string('idempotency_key', 100)->unique();
                $table->foreignUuid('stock_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('material_issue_lines')) {
            Schema::create('material_issue_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('material_issue_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('material_request_line_id')->constrained()->restrictOnDelete();
                $table->foreignUuid('vendor_item_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('issued_quantity', 18, 4);
                $table->timestamps();
                $table->unique(['material_issue_id', 'material_request_line_id', 'vendor_item_id', 'location_id'], 'mr_issue_line_bucket_unique');
            });
        }

        foreach (['material_request_lines' => 'requested_quantity', 'material_request_revision_lines' => 'revised_quantity', 'material_issue_lines' => 'issued_quantity'] as $table => $column) {
            $this->positiveWhole($table, $column);
        }
        DB::table('number_sequences')->insertOrIgnore(['id' => (string) Str::uuid7(), 'name' => 'material_request', 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('number_sequences')->insertOrIgnore(['id' => (string) Str::uuid7(), 'name' => 'material_issue', 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function positiveWhole(string $table, string $column): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared("CREATE TRIGGER {$table}_{$column}_whole_".strtolower($event)." BEFORE {$event} ON {$table} WHEN NEW.{$column} <= 0 OR NEW.{$column} != CAST(NEW.{$column} AS INTEGER) BEGIN SELECT RAISE(ABORT, '{$column} must be a positive whole number'); END");
            }
        } else {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_whole CHECK ({$column} > 0 AND {$column} = FLOOR({$column}))");
        }
    }

    public function down(): void
    {
        DB::table('number_sequences')->whereIn('name', ['material_request', 'material_issue'])->delete();
        foreach (['material_issue_lines', 'material_issues', 'material_request_approvals', 'material_request_revision_lines', 'material_request_revisions', 'material_request_lines', 'material_requests', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
