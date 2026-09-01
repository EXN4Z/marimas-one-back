<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->date("tanggal_input")->nullable()->after("tanggal_garansi");
            $table->date("tanggal_invoice")->nullable()->after("tanggal_input");
            $table->string("merk", 100)->nullable()->after("tanggal_invoice");
            $table->string("type", 100)->nullable()->after("merk");

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
