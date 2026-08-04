<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_filter_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_filter_presets');
    }
};
