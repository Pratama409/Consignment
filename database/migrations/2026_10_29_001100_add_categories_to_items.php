<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        if (DB::getDriverName() === 'sqlite') {
            // An inline nullable FK avoids rebuilding items and losing its integrity triggers.
            DB::statement('ALTER TABLE items ADD COLUMN category_id varchar REFERENCES categories(id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX items_category_id_index ON items (category_id)');
        } else {
            Schema::table('items', function (Blueprint $table) {
                $table->foreignUuid('category_id')->nullable()->index()->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX items_category_id_index');
            DB::statement('ALTER TABLE items DROP COLUMN category_id');
        } else {
            Schema::table('items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('category_id');
            });
        }
        Schema::drop('categories');
    }
};
