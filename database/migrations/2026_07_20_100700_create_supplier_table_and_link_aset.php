<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->string('kontak')->nullable(); // no. telp / PIC
            $table->string('alamat')->nullable();
            $table->timestamps();
        });

        Schema::table('aset', function (Blueprint $table) {
            // Ganti kolom supplier (teks bebas) jadi relasi ke tabel supplier,
            // biar alurnya beneran mulai dari entitas Supplier, bukan cuma catatan nama.
            $table->foreignId('supplier_id')->nullable()->after('barang_id')
                ->constrained('supplier')->nullOnDelete();
            $table->dropColumn('supplier');
        });
    }

    public function down(): void
    {
        Schema::table('aset', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->string('supplier')->nullable();
        });
        Schema::dropIfExists('supplier');
    }
};
