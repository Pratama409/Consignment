<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 10);
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['code', 'effective_from']);
        });

        Schema::create('operational_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_definition_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name', 100);
            $table->date('operational_date');
            $table->string('timezone', 40)->default('Asia/Makassar');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['starts_at', 'ends_at']);
            $table->unique(['operational_date', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_shifts');
        Schema::dropIfExists('shift_definitions');
    }
};
