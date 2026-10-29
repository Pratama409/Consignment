<?php

use App\Support\UuidTableUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(UuidTableUpgrade::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('UUID conversion preserves identities and is not reversed automatically. Restore the pre-upgrade backup for recovery.');
    }
};
