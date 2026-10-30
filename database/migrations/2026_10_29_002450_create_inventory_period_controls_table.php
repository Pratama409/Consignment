<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const CONTROL_ID = '00000000-0000-7000-8000-000000000001';

    public function up(): void
    {
        Schema::create('inventory_period_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('control_key', 50)->unique();
            $table->foreignUuid('active_period_id')->nullable()->unique()->constrained('inventory_periods')->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('inventory_period_controls')->insert([
            'id' => self::CONTROL_ID,
            'control_key' => 'GLOBAL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_period_controls');
    }
};
