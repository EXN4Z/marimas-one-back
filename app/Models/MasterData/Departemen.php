<?php

namespace App\Models\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Departemen extends Model
{
    protected $table = 'departemen';
    protected $fillable = ['nama'];

    // BARU (eks-pekerja): karyawan sekarang langsung di tabel users.
    public function karyawan()
    {
        return $this->hasMany(User::class, 'departemen_id');
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'departemen_id');
    }
}