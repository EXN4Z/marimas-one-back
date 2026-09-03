<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Menu "Perusahaan" di Master Data -- struktur field sama persis kaya
// LokasiKantor (menu "Cabang"), tapi sengaja belum dikasih relasi ke
// tabel lain (beda dari LokasiKantor::karyawan()/users()).
class Perusahaan extends Model
{
    protected $table = 'perusahaan';

    protected $fillable = [
        'nama',
        'alamat',
        'telepon',
        'link',
    ];
}