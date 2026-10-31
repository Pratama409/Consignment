<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('session_no', 40)->unique();
            $table->enum('scope_type', ['FULL', 'PARTIAL', 'CYCLE']);
            $table->string('cycle_month', 7)->nullable();
            $table->enum('status', ['COUNTING', 'COUNTED', 'RESOLVING', 'COMPLETED', 'CANCELLED'])->default('COUNTING');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('stock_opname_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_opname_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_bucket_id')->constrained()->restrictOnDelete();
            $table->decimal('counted_quantity', 18, 4)->nullable();
            // Snapshot of the balance taken when the count is submitted; never shown to the counter.
            $table->decimal('system_quantity', 18, 4)->nullable();
            $table->foreignUuid('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('counted_at')->nullable();
            $table->foreignUuid('stock_adjustment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['stock_opname_session_id', 'stock_bucket_id'], 'stock_opname_lines_session_bucket_unique');
            $table->index('stock_bucket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_lines');
        Schema::dropIfExists('stock_opname_sessions');
    }
};
