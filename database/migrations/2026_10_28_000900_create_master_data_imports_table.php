<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('file_name');
            $table->char('file_hash', 64)->index();
            $table->string('status', 20)->default('preview');
            $table->json('data');
            $table->json('options');
            $table->json('decisions')->nullable();
            $table->json('corrections')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_imports');
    }
};
