<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requirements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('row_key')->unique();
            $t->foreignUuid('material_issue_line_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignUuid('vendor_item_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $t->decimal('required_quantity', 18, 4);
            $t->enum('status', ['RESERVATION_PENDING', 'PR_PENDING', 'VALIDATION_PENDING', 'PO_PENDING', 'COMPLETE', 'REQUIRES_REVIEW', 'VOID'])->default('RESERVATION_PENDING')->index();
            $t->timestamps();
        });
        Schema::create('procurement_document_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('procurement_requirement_id')->constrained()->restrictOnDelete();
            $t->enum('stage', ['RESERVATION', 'PR', 'PO', 'GRGI']);
            $t->unsignedInteger('version');
            $t->unsignedInteger('line_no');
            $t->string('document_no', 100);
            $t->decimal('covered_quantity', 18, 4);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['procurement_requirement_id', 'stage', 'version', 'line_no'], 'proc_doc_line_version_unique');
        });
        Schema::create('procurement_import_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->enum('stage', ['RESERVATION', 'PR', 'PO', 'GRGI']);
            $t->string('checksum', 64);
            $t->unsignedInteger('version');
            $t->enum('status', ['PREVIEW', 'APPLIED', 'FAILED'])->default('PREVIEW');
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100)->nullable()->unique();
            $t->timestamps();
            $t->unique(['stage', 'checksum', 'version'], 'proc_import_version_unique');
        });
        Schema::create('procurement_import_rows', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('procurement_import_batch_id')->constrained()->cascadeOnDelete();
            $t->uuid('row_key');
            $t->json('source_data');
            $t->json('errors')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('procurement_validations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('procurement_requirement_id')->constrained()->restrictOnDelete();
            $t->enum('decision', ['VALID', 'REVISE', 'REJECT']);
            $t->text('note')->nullable();
            $t->foreignUuid('validated_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('validated_at');
            $t->timestamps();
        });
        Schema::create('procurement_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('procurement_requirement_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 50);
            $t->string('original_name');
            $t->string('disk', 50);
            $t->string('path')->unique();
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size');
            $t->char('checksum', 64);
            $t->unsignedInteger('version');
            $t->boolean('is_vendor_visible')->default(false);
            $t->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['procurement_documents', 'procurement_validations', 'procurement_import_rows', 'procurement_import_batches', 'procurement_document_lines', 'procurement_requirements'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
