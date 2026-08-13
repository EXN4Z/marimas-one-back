<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jabatan extends Model
{
    protected $table = 'jabatan';

    protected $fillable = ['nama', 'gaji_pokok', 'tunjangan'];

    // Relasi ke karyawan (dulu Pekerja) sengaja dihapus total — jabatan
    // tidak lagi terhubung ke alur karyawan. Model & tabel ini tetap
    // berdiri sendiri sebagai master data (nama, gaji_pokok, tunjangan)
    // di halaman Master Data.
}