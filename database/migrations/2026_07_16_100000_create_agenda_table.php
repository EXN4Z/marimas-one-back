<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->dateTime('start_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda');
    }
};
