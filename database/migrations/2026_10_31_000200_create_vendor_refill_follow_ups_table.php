<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_refill_follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_refill_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['vendor_refill_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_refill_follow_ups');
    }
};
