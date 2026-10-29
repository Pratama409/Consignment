<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'user')->update(['role' => 'warehouse']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('warehouse')->change();
            $table->foreignUuid('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('employee_no')->nullable();
            $table->string('position')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'warehouse')->update(['role' => 'user']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn(['is_active', 'employee_no', 'position', 'last_login_at']);
            $table->string('role', 20)->default('user')->change();
        });
    }
};
