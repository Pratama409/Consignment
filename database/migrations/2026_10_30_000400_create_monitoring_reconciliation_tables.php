<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_recap_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operational_shift_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('summary');
            $table->char('checksum', 64);
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['operational_shift_id', 'version'], 'shift_recap_version_unique');
        });

        Schema::create('vendor_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reconciliation_no', 80)->unique();
            $table->foreignUuid('vendor_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('version');
            $table->enum('status', ['DRAFT', 'VENDOR_REVIEW', 'AGREED', 'DISPUTED', 'RESOLVED', 'FINAL'])->default('DRAFT')->index();
            $table->foreignUuid('supersedes_id')->nullable()->constrained('vendor_reconciliations')->restrictOnDelete();
            $table->timestamp('source_cutoff_at');
            $table->char('checksum', 64)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['vendor_id', 'period_start', 'period_end', 'version'], 'vendor_ba_period_version_unique');
        });

        Schema::create('vendor_reconciliation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vendor_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_quantity', 18, 4);
            $table->decimal('in_quantity', 18, 4);
            $table->decimal('out_quantity', 18, 4);
            $table->decimal('adjustment_quantity', 18, 4);
            $table->decimal('system_closing_quantity', 18, 4);
            $table->decimal('physical_quantity', 18, 4)->nullable();
            $table->decimal('variance_quantity', 18, 4)->nullable();
            $table->json('references')->nullable();
            $table->timestamps();
            $table->unique(['vendor_reconciliation_id', 'vendor_item_id', 'location_id'], 'vendor_ba_bucket_unique');
        });

        Schema::create('vendor_reconciliation_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_reconciliation_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('decision', ['AGREE', 'DISPUTE', 'RESOLVED']);
            $table->text('note')->nullable();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('responded_at');
            $table->timestamps();
        });

        Schema::create('vendor_reconciliation_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_reconciliation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->enum('type', ['XLSX', 'PDF', 'ATTACHMENT']);
            $table->string('disk', 50)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vendor_reconciliation_id', 'type', 'version'], 'vendor_ba_document_version_unique');
        });
    }

    public function down(): void
    {
        foreach (['vendor_reconciliation_documents', 'vendor_reconciliation_responses', 'vendor_reconciliation_lines', 'vendor_reconciliations', 'shift_recap_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
