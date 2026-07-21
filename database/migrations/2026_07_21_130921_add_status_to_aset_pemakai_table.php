<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->enum('status', ['pending', 'disetujui', 'ditolak'])->default('disetujui')->after('pekerja_id');
            $table->foreignId('requested_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('catatan_penolakan')->nullable()->after('catatan_pengembalian');
        });
    }

    public function down(): void
    {
        Schema::table('aset_pemakai', function (Blueprint $table) {
            $table->dropForeign(['requested_by_user_id']);
            $table->dropColumn(['status', 'requested_by_user_id', 'catatan_penolakan']);
        });
    }
};