<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\MasterData\Inventory;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'perusahaan_id');
    }
        public function users(): HasMany
    {
        return $this->hasMany(User::class, 'lokasi_kantor_id');
    }
}