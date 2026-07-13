<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'user_id')) {
                $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            }
            if (!Schema::hasColumn('tickets', 'judul')) {
                $table->string('judul', 150)->after('user_id');
            }
            if (!Schema::hasColumn('tickets', 'deskripsi')) {
                $table->text('deskripsi')->after('judul');
            }
            if (!Schema::hasColumn('tickets', 'kategori')) {
                $table->string('kategori', 50)->nullable()->after('deskripsi');
            }
            if (!Schema::hasColumn('tickets', 'status')) {
                $table->enum('status', ['pending', 'diproses', 'selesai', 'ditolak'])
                    ->default('pending')
                    ->after('kategori');
            }
            if (!Schema::hasColumn('tickets', 'catatan_admin')) {
                $table->text('catatan_admin')->nullable()->after('status');
            }
            if (!Schema::hasColumn('tickets', 'ditangani_oleh')) {
                $table->foreignId('ditangani_oleh')
                    ->nullable()
                    ->after('catatan_admin')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('tickets', 'selesai_at')) {
                $table->timestamp('selesai_at')->nullable()->after('ditangani_oleh');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['kategori', 'catatan_admin', 'selesai_at']);
            $table->dropConstrainedForeignId('ditangani_oleh');
            $table->dropColumn(['status', 'deskripsi', 'judul']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};